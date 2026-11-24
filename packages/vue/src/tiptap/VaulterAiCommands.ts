import { Extension, type Editor } from '@tiptap/core'
import { DOMSerializer, Slice, type Mark } from '@tiptap/pm/model'
import { Plugin, PluginKey } from '@tiptap/pm/state'
import { Mapping } from '@tiptap/pm/transform'
import { Decoration, DecorationSet } from '@tiptap/pm/view'
import { normalizeChatSelection, type ChatEventSubscription } from '../api/client'
import type { ChatEditorSelection } from '../api/types'
import {
  extractSanitizedBlockText,
  getEditorContext,
  modeForRange,
  normalizeContentProtectionPolicy,
} from './contentProtection'
import { consumeTextOrSseStream, StreamContent } from './StreamContent'
import type {
  VaulterAiAcceptOptions,
  VaulterAiAction,
  VaulterAiCommandOptions,
  VaulterAiCommandRequest,
  VaulterAiCommandsOptions,
  VaulterAiFormat,
  VaulterAiRegenerateOptions,
  VaulterAiRejectOptions,
  VaulterAiStorage,
  VaulterAiTextPromptOptions,
  VaulterAiToneOptions,
  VaulterAiTranslateOptions,
} from './types'

export const VAULTER_AI_COMMANDS_PLUGIN_KEY = new PluginKey('vaulterAiCommands')
const PREVIEW_META = 'vaulterAiCommandsPreview'

interface InternalCommandState {
  abortController: AbortController | null
  streamContent: StreamContent | null
  chatSubscription: ChatEventSubscription | null
  chatSessionId: string | null
  activeRunId: string | null
  initialSlice: Slice | null
  initialRange: { from: number; to: number } | null
  initialMarks: readonly Mark[]
  insertMode: boolean
  format: VaulterAiFormat
  lastRequest: { action: VaulterAiAction; options: VaulterAiCommandOptions } | null
  pendingMapping: Mapping
}

function getDefaultPromptForAction(action: VaulterAiAction, options: VaulterAiCommandOptions): string {
  switch (action) {
    case 'shorten':
      return 'Shorten the selected text while preserving its core meaning.'
    case 'lengthen':
      return 'Expand and elaborate on the selected text.'
    case 'tone':
      return `Rewrite the selected text in a ${options.tone ?? 'neutral'} tone.`
    case 'fixGrammar':
      return 'Fix grammar, spelling, and punctuation errors in the selected text.'
    case 'translate':
      return `Translate the selected text to ${options.language ?? 'English'}.`
    case 'summarize':
      return 'Summarize the selected text concisely.'
    case 'prompt':
      return options.text ?? options.prompt ?? ''
  }
}

function serializeSelectionHtml(editor: Editor, from: number, to: number): string {
  const slice = editor.state.doc.slice(from, to)
  const container = document.createElement('div')
  const serializer = DOMSerializer.fromSchema(editor.schema)
  container.appendChild(serializer.serializeFragment(slice.content))
  return container.innerHTML
}

function resolveUniformMarks(editor: Editor, from: number, to: number): readonly Mark[] {
  if (from >= to) {
    return editor.state.doc.resolve(from).marks()
  }
  let uniform: readonly Mark[] | null = null
  let mixed = false
  editor.state.doc.nodesBetween(from, to, (node) => {
    if (!node.isText) return
    if (uniform === null) {
      uniform = node.marks
    } else if (
      uniform.length !== node.marks.length
      || uniform.some((m, i) => !m.eq(node.marks[i]))
    ) {
      mixed = true
    }
  })
  return !mixed && uniform ? uniform : []
}

function buildSelectionPayload(
  editor: Editor,
  resourceId: string,
  from: number,
  to: number,
): ChatEditorSelection | null {
  const $from = editor.state.doc.resolve(from)
  for (let d = $from.depth; d >= 1; d -= 1) {
    const node = $from.node(d)
    const blockId = node.attrs?.blockId
    if (typeof blockId === 'string' && blockId !== '') {
      const blockStart = $from.start(d)
      const startUtf16 = Math.max(0, from - blockStart)
      const endUtf16 = Math.max(startUtf16, to - blockStart)
      const excerpt = editor.state.doc.textBetween(from, to, ' ', '\uFFFC')
      return normalizeChatSelection({
        kind: 'text_block',
        resource_id: resourceId,
        block_id: blockId,
        excerpt: excerpt || undefined,
        start_utf16: startUtf16,
        end_utf16: endUtf16,
      })
    }
  }
  return null
}

export const VaulterAiCommands = Extension.create<VaulterAiCommandsOptions, VaulterAiStorage>({
  name: 'vaulterAiCommands',

  addOptions() {
    return {
      defaultFormat: 'rich-text',
      defaultInsert: true,
      autoAccept: false,
      maxPastResponses: 20,
    }
  },

  addStorage(): VaulterAiStorage {
    return {
      state: 'idle',
      isStreaming: false,
      action: null,
      response: null,
      pastResponses: [],
      generatedRange: null,
      error: null,
    }
  },

  addProseMirrorPlugins() {
    const internal: InternalCommandState = {
      abortController: null,
      streamContent: null,
      chatSubscription: null,
      chatSessionId: this.options.sessionId ?? null,
      activeRunId: null,
      initialSlice: null,
      initialRange: null,
      initialMarks: [],
      insertMode: true,
      format: 'rich-text',
      lastRequest: null,
      pendingMapping: new Mapping(),
    }

    ;(this.storage as VaulterAiStorage & { __internal?: InternalCommandState }).__internal = internal

    const storage = this.storage

    return [
      new Plugin({
        key: VAULTER_AI_COMMANDS_PLUGIN_KEY,
        state: {
          init: () => null,
          apply: (tr, value) => {
            if (tr.docChanged && storage.generatedRange) {
              const docSize = tr.doc.content.size
              const mappedFrom = Math.max(0, Math.min(docSize, tr.mapping.map(storage.generatedRange.from, 1)))
              const mappedTo = Math.max(mappedFrom, Math.min(docSize, tr.mapping.map(storage.generatedRange.to, -1)))
              storage.generatedRange = { from: mappedFrom, to: mappedTo }
            }
            if (tr.docChanged && internal.initialRange) {
              internal.pendingMapping.appendMapping(tr.mapping)
            }
            return tr.getMeta(PREVIEW_META) ?? value
          },
        },
        props: {
          decorations(state) {
            if (storage.state !== 'preview' && storage.state !== 'loading') {
              return DecorationSet.empty
            }
            const range = storage.generatedRange
            if (internal.insertMode && range && range.to > range.from && range.to <= state.doc.content.size) {
              return DecorationSet.create(state.doc, [
                Decoration.inline(range.from, range.to, {
                  class: 'vaulter-ai-generated-preview',
                  'data-ai-action': storage.action ?? '',
                }),
              ])
            }
            if (!internal.insertMode && storage.response && internal.initialRange) {
              const pos = Math.max(0, Math.min(state.doc.content.size, internal.pendingMapping.map(internal.initialRange.to, -1)))
              return DecorationSet.create(state.doc, [
                Decoration.widget(
                  pos,
                  () => {
                    const el = document.createElement('span')
                    el.className = 'vaulter-ai-preview-widget'
                    el.dataset.testid = 'ai-preview-widget'
                    el.textContent = storage.response ?? ''
                    return el
                  },
                  { side: 1 },
                ),
              ])
            }
            return DecorationSet.empty
          },
        },
      }),
    ]
  },

  onDestroy() {
    const internal = (this.storage as VaulterAiStorage & { __internal?: InternalCommandState }).__internal
    if (!internal) return
    internal.abortController?.abort()
    internal.abortController = null
    internal.chatSubscription?.close()
    internal.chatSubscription = null
    internal.streamContent?.destroy()
    internal.streamContent = null
  },

  addCommands() {
    const getInternal = (): InternalCommandState =>
      (this.storage as VaulterAiStorage & { __internal: InternalCommandState }).__internal

    const resolvePolicy = () => {
      const raw = typeof this.options.contentProtectionPolicy === 'function'
        ? this.options.contentProtectionPolicy()
        : this.options.contentProtectionPolicy
      return normalizeContentProtectionPolicy(raw)
    }

    const pushPastResponse = (text: string) => {
      const max = this.options.maxPastResponses ?? 20
      this.storage.pastResponses = [...this.storage.pastResponses, text].slice(-max)
    }

    const cleanupTransport = (internal: InternalCommandState) => {
      internal.abortController?.abort()
      internal.abortController = null
      internal.chatSubscription?.close()
      internal.chatSubscription = null
      internal.streamContent?.destroy()
      internal.streamContent = null
      internal.activeRunId = null
    }

    const runAiTextCommand = (
      editor: Editor,
      action: VaulterAiAction,
      cmdOptions: VaulterAiCommandOptions = {},
    ): boolean => {
      if (this.storage.state === 'loading') {
        return false
      }

      const internal = getInternal()
      const policy = resolvePolicy()
      const { from, to } = editor.state.selection

      const protectionMode = modeForRange(editor.state.doc, from, to, policy)
      if (protectionMode === 'hidden' || protectionMode === 'read_only') {
        const err = new Error(
          protectionMode === 'hidden'
            ? 'Target content is hidden by contentProtectionPolicy.'
            : 'Target content is read_only under contentProtectionPolicy.',
        )
        this.storage.state = 'error'
        this.storage.isStreaming = false
        this.storage.error = err
        this.options.onError?.({ action, error: err })
        return false
      }

      const format: VaulterAiFormat = cmdOptions.format ?? this.options.defaultFormat ?? 'rich-text'
      const insertMode = cmdOptions.insert ?? this.options.defaultInsert ?? true
      const isStreaming = cmdOptions.stream ?? Boolean(this.options.streamResolver || this.options.chatClient || this.options.endpoint)
      const prompt = getDefaultPromptForAction(action, cmdOptions)
      const selectedText = extractSanitizedBlockText(
        editor.state.doc.cut(from, to),
        policy,
      )
      const selectedHtml = serializeSelectionHtml(editor, from, to)
      const editorContext = getEditorContext(editor, {
        contentProtectionPolicy: typeof this.options.contentProtectionPolicy === 'function'
          ? this.options.contentProtectionPolicy()
          : this.options.contentProtectionPolicy,
      })

      internal.initialSlice = editor.state.doc.slice(from, to)
      internal.initialRange = { from, to }
      internal.initialMarks = resolveUniformMarks(editor, from, to)
      internal.insertMode = insertMode
      internal.format = format
      internal.lastRequest = { action, options: { ...cmdOptions } }
      internal.pendingMapping = new Mapping()

      const abortController = new AbortController()
      internal.abortController = abortController

      this.storage.state = 'loading'
      this.storage.isStreaming = isStreaming
      this.storage.action = action
      this.storage.response = ''
      this.storage.error = null
      this.storage.generatedRange = insertMode ? { from, to } : null
      this.options.onLoading?.({ action, isStreaming })

      const requestPayload: VaulterAiCommandRequest = {
        action,
        prompt,
        text: selectedText,
        html: selectedHtml,
        format,
        tone: cmdOptions.tone,
        language: cmdOptions.language,
        selection: { from, to },
        editorContext,
        contentProtectionPolicy: policy,
        signal: abortController.signal,
        options: cmdOptions,
      }

      const streamContent = insertMode
        ? new StreamContent(editor, {
          from,
          to,
          format,
          marks: internal.initialMarks,
          addToHistory: false,
          extraMeta: { [PREVIEW_META]: true },
        })
        : null
      internal.streamContent = streamContent

      const handleChunk = (chunk: string) => {
        if (abortController.signal.aborted || editor.isDestroyed) return
        this.storage.response = (this.storage.response ?? '') + chunk
        if (streamContent) {
          streamContent.push(chunk)
          this.storage.generatedRange = streamContent.getMappedRange()
        }
        this.options.onChunk?.({
          action,
          chunk,
          response: this.storage.response,
        })
      }

      const handleComplete = () => {
        if (abortController.signal.aborted || editor.isDestroyed) return
        if (streamContent) {
          this.storage.generatedRange = streamContent.finish()
          streamContent.destroy()
          internal.streamContent = null
        }
        const finalResponse = this.storage.response ?? ''
        pushPastResponse(finalResponse)
        const shouldAutoAccept = cmdOptions.autoAccept ?? this.options.autoAccept ?? false
        this.storage.isStreaming = false
        this.storage.state = shouldAutoAccept ? 'idle' : 'preview'
        if (shouldAutoAccept) {
          internal.initialSlice = null
          internal.initialRange = null
        }
        if (!editor.isDestroyed) {
          editor.view.dispatch(editor.state.tr.setMeta(PREVIEW_META, Date.now()).setMeta('addToHistory', false))
        }
        this.options.onSuccess?.({
          action,
          response: finalResponse,
          isStreaming,
        })
      }

      const handleError = (err: unknown) => {
        if (abortController.signal.aborted) return
        const error = err instanceof Error ? err : new Error(String(err))
        streamContent?.destroy()
        internal.streamContent = null
        this.storage.state = 'error'
        this.storage.isStreaming = false
        this.storage.error = error
        this.storage.generatedRange = null
        this.options.onError?.({ action, error })
      }

      void (async () => {
        try {
          if (this.options.streamResolver) {
            const result = await this.options.streamResolver(requestPayload)
            await consumeTextOrSseStream(result, {
              signal: abortController.signal,
              onChunk: handleChunk,
            })
            handleComplete()
            return
          }

          if (this.options.completionResolver) {
            const text = await this.options.completionResolver(requestPayload)
            handleChunk(text)
            handleComplete()
            return
          }

          if (this.options.endpoint) {
            const fetchFn = this.options.fetchImpl ?? globalThis.fetch
            const customHeaders = this.options.headers ? await this.options.headers() : {}
            const response = await fetchFn(this.options.endpoint, {
              method: 'POST',
              signal: abortController.signal,
              headers: {
                'Content-Type': 'application/json',
                Accept: 'text/event-stream, application/json, text/plain',
                ...customHeaders,
              },
              body: JSON.stringify({
                action,
                prompt,
                text: selectedText,
                html: selectedHtml,
                format,
                tone: cmdOptions.tone,
                language: cmdOptions.language,
                stream: isStreaming,
                selection: { from, to },
                editorContext,
                contentProtectionPolicy: policy,
              }),
            })

            if (!response.ok) {
              throw new Error(`AI endpoint request failed with status ${response.status}.`)
            }

            const contentType = response.headers.get('content-type') ?? ''
            if (contentType.includes('application/json')) {
              const json = (await response.json()) as Record<string, unknown>
              const data = (json.data as Record<string, unknown> | undefined) ?? json
              const text = data.response ?? data.text ?? data.html ?? ''
              if (typeof text === 'string' && text !== '') {
                handleChunk(text)
              }
              handleComplete()
              return
            }

            if (response.body) {
              await consumeTextOrSseStream(response.body, {
                signal: abortController.signal,
                isSse: contentType.includes('text/event-stream'),
                onChunk: handleChunk,
              })
              handleComplete()
              return
            }

            const bodyText = await response.text()
            handleChunk(bodyText)
            handleComplete()
            return
          }

          if (this.options.chatClient) {
            const chatClient = this.options.chatClient
            const resourceId = this.options.resourceId ?? 'default'
            let sid = internal.chatSessionId
            if (!sid) {
              const created = await chatClient.createChatSession()
              sid = created.session_id
              internal.chatSessionId = sid
            }
            const selection = buildSelectionPayload(editor, resourceId, from, to)
            const msgId = typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function'
              ? crypto.randomUUID()
              : `ai-cmd-${Date.now()}-${Math.random().toString(16).slice(2, 8)}`
            const posted = await chatClient.postChatMessage(sid, {
              messageId: msgId,
              content: prompt ? `${prompt}\n\n${selectedText}` : selectedText,
              resourceIds: this.options.resourceId ? [this.options.resourceId] : [],
              selection,
            })
            internal.activeRunId = posted.run_id

            await new Promise<void>((resolve, reject) => {
              const sub = chatClient.connectChatEvents(sid, {
                cursor: 0,
                onEvent: (ev) => {
                  if (abortController.signal.aborted) {
                    sub.close()
                    resolve()
                    return
                  }
                  const evRunId = typeof ev.data.run_id === 'string' ? ev.data.run_id : null
                  if (evRunId && evRunId !== posted.run_id) {
                    return
                  }
                  if (ev.type === 'assistant.delta') {
                    const delta = ev.data.text
                    if (typeof delta === 'string' && delta !== '') {
                      handleChunk(delta)
                    }
                  } else if (ev.type === 'hidden') {
                    sub.close()
                    reject(new Error('AI response hidden due to access policy.'))
                  } else if (ev.type === 'run.finished') {
                    sub.close()
                    if (ev.data.state === 'rejected') {
                      reject(new Error(typeof ev.data.error === 'string' ? ev.data.error : 'AI run rejected.'))
                    } else {
                      resolve()
                    }
                  }
                },
                onError: (err) => {
                  sub.close()
                  reject(err)
                },
              })
              internal.chatSubscription = sub
              abortController.signal.addEventListener('abort', () => {
                sub.close()
                resolve()
              }, { once: true })
            })

            if (!abortController.signal.aborted) {
              handleComplete()
            }
            return
          }

          throw new Error('No AI transport configured (streamResolver, completionResolver, endpoint, or chatClient).')
        } catch (err) {
          handleError(err)
        }
      })()

      return true
    }

    return {
      shorten: (options?: VaulterAiCommandOptions) => ({ editor }) =>
        runAiTextCommand(editor, 'shorten', options),

      lengthen: (options?: VaulterAiCommandOptions) => ({ editor }) =>
        runAiTextCommand(editor, 'lengthen', options),

      tone: (toneOrOptions: string | VaulterAiToneOptions) => ({ editor }) => {
        const opts = typeof toneOrOptions === 'string'
          ? { tone: toneOrOptions }
          : toneOrOptions
        return runAiTextCommand(editor, 'tone', opts)
      },

      fixGrammar: (options?: VaulterAiCommandOptions) => ({ editor }) =>
        runAiTextCommand(editor, 'fixGrammar', options),

      translate: (languageOrOptions: string | VaulterAiTranslateOptions) => ({ editor }) => {
        const opts = typeof languageOrOptions === 'string'
          ? { language: languageOrOptions }
          : languageOrOptions
        return runAiTextCommand(editor, 'translate', opts)
      },

      summarize: (options?: VaulterAiCommandOptions) => ({ editor }) =>
        runAiTextCommand(editor, 'summarize', options),

      aiTextPrompt: (options: string | VaulterAiTextPromptOptions) => ({ editor }) => {
        const opts = typeof options === 'string'
          ? { text: options }
          : options
        return runAiTextCommand(editor, 'prompt', opts)
      },

      aiAccept: (acceptOptions?: VaulterAiAcceptOptions) => ({ editor, tr, dispatch }) => {
        const internal = getInternal()
        const response = this.storage.response
        if (!internal.insertMode && response !== null && internal.initialRange) {
          const docSize = editor.state.doc.content.size
          const mappedFrom = Math.max(0, Math.min(docSize, internal.pendingMapping.map(internal.initialRange.from, 1)))
          const mappedTo = Math.max(mappedFrom, Math.min(docSize, internal.pendingMapping.map(internal.initialRange.to, -1)))
          const targetFrom = acceptOptions?.insertAt ?? (acceptOptions?.append ? mappedTo : mappedFrom)
          const targetTo = acceptOptions?.insertAt !== undefined || acceptOptions?.append ? targetFrom : mappedTo

          const policy = resolvePolicy()
          const mode = modeForRange(editor.state.doc, targetFrom, targetTo, policy)
          if (mode === 'hidden' || mode === 'read_only') {
            return false
          }

          if (dispatch) {
            const isPlain = internal.format === 'plain-text' || !/<[a-zA-Z!/]/.test(response)
            if (isPlain) {
              const marks = internal.initialMarks.length > 0 ? [...internal.initialMarks] : undefined
              const textNode = editor.schema.text(response, marks)
              tr.replaceWith(targetFrom, targetTo, textNode)
            } else {
              tr.insertText(response, targetFrom, targetTo)
            }
          }
        }

        const acceptedAction = this.storage.action
        const acceptedResponse = this.storage.response
        internal.initialSlice = null
        internal.initialRange = null
        this.storage.generatedRange = null
        this.storage.state = 'idle'
        this.storage.isStreaming = false

        if (dispatch) {
          tr.setMeta(PREVIEW_META, Date.now())
        }
        this.options.onAccept?.({ action: acceptedAction, response: acceptedResponse })
        return true
      },

      aiReject: (_rejectOptions?: VaulterAiRejectOptions) => ({ editor, tr, dispatch }) => {
        const internal = getInternal()
        const rejectedAction = this.storage.action
        cleanupTransport(internal)

        if (dispatch && internal.insertMode && internal.initialSlice && this.storage.generatedRange) {
          const docSize = editor.state.doc.content.size
          const from = Math.max(0, Math.min(docSize, this.storage.generatedRange.from))
          const to = Math.max(from, Math.min(docSize, this.storage.generatedRange.to))
          tr.replace(from, to, internal.initialSlice)
          tr.setMeta(PREVIEW_META, Date.now())
          tr.setMeta('addToHistory', false)
        } else if (dispatch) {
          tr.setMeta(PREVIEW_META, Date.now())
          tr.setMeta('addToHistory', false)
        }

        internal.initialSlice = null
        internal.initialRange = null
        this.storage.state = 'idle'
        this.storage.isStreaming = false
        this.storage.response = null
        this.storage.pastResponses = []
        this.storage.generatedRange = null
        this.storage.error = null
        this.options.onReject?.({ action: rejectedAction })
        return true
      },

      aiRegenerate: (overrideOptions?: VaulterAiRegenerateOptions) => ({ editor, tr, dispatch }) => {
        const internal = getInternal()
        if (!internal.lastRequest) {
          return false
        }
        const { action, options } = internal.lastRequest
        if (this.storage.state === 'loading') {
          cleanupTransport(internal)
          this.storage.state = 'idle'
          this.storage.isStreaming = false
        }
        if (dispatch && internal.insertMode && internal.initialSlice && this.storage.generatedRange) {
          const docSize = editor.state.doc.content.size
          const from = Math.max(0, Math.min(docSize, this.storage.generatedRange.from))
          const to = Math.max(from, Math.min(docSize, this.storage.generatedRange.to))
          tr.replace(from, to, internal.initialSlice)
          tr.setMeta('addToHistory', false)
        }
        queueMicrotask(() => {
          if (!editor.isDestroyed) {
            runAiTextCommand(editor, action, { ...options, ...overrideOptions })
          }
        })
        return true
      },

      aiAbort: () => () => {
        const internal = getInternal()
        cleanupTransport(internal)
        this.storage.state = 'idle'
        this.storage.isStreaming = false
        return true
      },
    }
  },
})
