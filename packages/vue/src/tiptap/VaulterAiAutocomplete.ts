import { Extension, type Editor } from '@tiptap/core'
import type { ResolvedPos } from '@tiptap/pm/model'
import { Plugin, PluginKey, type EditorState } from '@tiptap/pm/state'
import { Decoration, DecorationSet, type EditorView } from '@tiptap/pm/view'
import {
  extractSanitizedBlockText,
  getEditorContext,
  modeForPath,
  normalizeContentProtectionPolicy,
} from './contentProtection'
import { consumeTextOrSseStream } from './StreamContent'
import type {
  NormalizedContentProtectionPolicy,
  VaulterAiAutocompleteOptions,
  VaulterAiAutocompleteRequest,
  VaulterAiAutocompleteStorage,
} from './types'

export const VAULTER_AI_AUTOCOMPLETE_PLUGIN_KEY = new PluginKey<AutocompletePluginState>('vaulterAiAutocomplete')
const AUTOCOMPLETE_META = 'vaulterAiAutocompleteMeta'

interface AutocompletePluginState {
  pos: number | null
  suggestion: string
  loading: boolean
  requestId: number
}

interface AutocompleteInstance {
  abortController: AbortController | null
  debounceTimer: ReturnType<typeof setTimeout> | null
  requestId: number
  lastHandledDoc: unknown
}

function isInsideNodeTypes($pos: ResolvedPos, nodeTypes: ReadonlySet<string>): boolean {
  for (let d = $pos.depth; d >= 0; d -= 1) {
    if (nodeTypes.has($pos.node(d).type.name)) {
      return true
    }
  }
  return false
}

export function extractAutocompleteContext(
  state: EditorState,
  policy: NormalizedContentProtectionPolicy | null,
  maxBlocks = 3,
  maxLength = 4000,
): { context: string; blocks: string[]; cursorBlockId: string | null } {
  const { $from } = state.selection
  const cursorBlockPos = $from.depth >= 1 ? $from.before($from.depth) : -1
  let cursorBlockId: string | null = null
  const collected: string[] = []

  state.doc.descendants((node, pos) => {
    if (pos > cursorBlockPos && cursorBlockPos !== -1) {
      return false
    }
    if (!node.isTextblock) {
      return true
    }

    const $nodePos = state.doc.resolve(pos + 1)
    const chain: Array<{ type: string; attrs: Record<string, unknown> }> = []
    for (let d = 0; d <= $nodePos.depth; d += 1) {
      const n = $nodePos.node(d)
      chain.push({ type: n.type.name, attrs: n.attrs as Record<string, unknown> })
    }
    if (modeForPath(policy, chain) === 'hidden') {
      return false
    }

    let text: string
    if (pos === cursorBlockPos) {
      const cutNode = node.cut(0, $from.parentOffset)
      text = extractSanitizedBlockText(cutNode, policy)
      const bid = node.attrs?.blockId
      if (typeof bid === 'string' && bid !== '') {
        cursorBlockId = bid
      }
    } else {
      text = extractSanitizedBlockText(node, policy)
    }

    if (text !== '') {
      collected.push(text)
    }
    return false
  })

  const blocks = collected.slice(-Math.max(1, maxBlocks))
  let joined = blocks.join('\n\n')
  if (joined.length > maxLength) {
    joined = joined.slice(joined.length - maxLength)
  }

  return {
    context: joined,
    blocks,
    cursorBlockId,
  }
}

export const VaulterAiAutocomplete = Extension.create<VaulterAiAutocompleteOptions, VaulterAiAutocompleteStorage>({
  name: 'vaulterAiAutocomplete',

  addOptions() {
    return {
      enabled: true,
      debounceMs: 250,
      minChars: 1,
      contextBlockLimit: 3,
      inputLength: 4000,
      allowMidBlock: false,
      yieldInNodeTypes: ['tableCell', 'tableHeader'],
    }
  },

  addStorage(): VaulterAiAutocompleteStorage {
    return {
      suggestion: '',
      loading: false,
      pos: null,
    }
  },

  addProseMirrorPlugins() {
    const instance: AutocompleteInstance = {
      abortController: null,
      debounceTimer: null,
      requestId: 0,
      lastHandledDoc: null,
    }

    const resolvePolicy = (): NormalizedContentProtectionPolicy | null => {
      const raw = typeof this.options.contentProtectionPolicy === 'function'
        ? this.options.contentProtectionPolicy()
        : this.options.contentProtectionPolicy
      return normalizeContentProtectionPolicy(raw)
    }

    const cancelPending = (view?: EditorView) => {
      if (instance.debounceTimer !== null) {
        clearTimeout(instance.debounceTimer)
        instance.debounceTimer = null
      }
      if (instance.abortController) {
        instance.abortController.abort()
        instance.abortController = null
      }
      instance.requestId += 1
      this.storage.suggestion = ''
      this.storage.loading = false
      this.storage.pos = null

      if (view && !view.isDestroyed) {
        const current = VAULTER_AI_AUTOCOMPLETE_PLUGIN_KEY.getState(view.state)
        if (current && (current.suggestion !== '' || current.loading)) {
          view.dispatch(
            view.state.tr
              .setMeta(AUTOCOMPLETE_META, { pos: null, suggestion: '', loading: false, requestId: instance.requestId })
              .setMeta('addToHistory', false),
          )
        }
      }
    }

    const runAutocomplete = (editor: Editor, view: EditorView, force = false) => {
      if (this.options.enabled === false || view.isDestroyed || editor.isDestroyed) {
        return
      }
      const { selection, doc } = view.state
      if (!selection.empty) {
        return
      }
      const { $from, from } = selection
      if (!$from.parent.isTextblock) {
        return
      }
      if (!this.options.allowMidBlock && $from.parentOffset !== $from.parent.content.size) {
        return
      }
      if (this.options.shouldTrigger && !this.options.shouldTrigger(editor)) {
        return
      }

      const policy = resolvePolicy()
      const chain: Array<{ type: string; attrs: Record<string, unknown> }> = []
      for (let d = 0; d <= $from.depth; d += 1) {
        const n = $from.node(d)
        chain.push({ type: n.type.name, attrs: n.attrs as Record<string, unknown> })
      }
      const mode = modeForPath(policy, chain)
      if (mode === 'hidden' || mode === 'read_only') {
        return
      }

      const minChars = this.options.minChars ?? 1
      if (!force && $from.parent.textContent.length < minChars) {
        return
      }

      cancelPending()
      const reqId = ++instance.requestId
      const abortController = new AbortController()
      instance.abortController = abortController

      const { context, blocks, cursorBlockId } = extractAutocompleteContext(
        view.state,
        policy,
        this.options.contextBlockLimit ?? 3,
        this.options.inputLength ?? 4000,
      )
      if (!force && context.trim() === '') {
        return
      }

      const editorContext = getEditorContext(editor, {
        contentProtectionPolicy: typeof this.options.contentProtectionPolicy === 'function'
          ? this.options.contentProtectionPolicy()
          : this.options.contentProtectionPolicy,
      })

      this.storage.loading = true
      this.storage.pos = from
      if (!view.isDestroyed) {
        view.dispatch(
          view.state.tr
            .setMeta(AUTOCOMPLETE_META, { pos: from, suggestion: '', loading: true, requestId: reqId })
            .setMeta('addToHistory', false),
        )
      }

      const request: VaulterAiAutocompleteRequest = {
        context,
        blocks,
        cursorBlockId,
        editorContext,
        signal: abortController.signal,
      }

      const appendSuggestion = (chunk: string) => {
        if (abortController.signal.aborted || reqId !== instance.requestId || view.isDestroyed) {
          return
        }
        this.storage.suggestion += chunk
        view.dispatch(
          view.state.tr
            .setMeta(AUTOCOMPLETE_META, {
              pos: from,
              suggestion: this.storage.suggestion,
              loading: true,
              requestId: reqId,
            })
            .setMeta('addToHistory', false),
        )
      }

      const finishSuggestion = () => {
        if (abortController.signal.aborted || reqId !== instance.requestId || view.isDestroyed) {
          return
        }
        this.storage.loading = false
        view.dispatch(
          view.state.tr
            .setMeta(AUTOCOMPLETE_META, {
              pos: from,
              suggestion: this.storage.suggestion,
              loading: false,
              requestId: reqId,
            })
            .setMeta('addToHistory', false),
        )
      }

      void (async () => {
        try {
          if (this.options.resolver) {
            const result = await this.options.resolver(request)
            await consumeTextOrSseStream(result, {
              signal: abortController.signal,
              onChunk: appendSuggestion,
            })
            finishSuggestion()
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
                context,
                blocks,
                cursorBlockId,
                editorContext,
              }),
            })
            if (!response.ok) {
              throw new Error(`Autocomplete request failed with status ${response.status}.`)
            }
            const contentType = response.headers.get('content-type') ?? ''
            if (contentType.includes('application/json')) {
              const json = (await response.json()) as Record<string, unknown>
              const data = (json.data as Record<string, unknown> | undefined) ?? json
              const text = data.response ?? data.text ?? data.suggestion ?? ''
              if (typeof text === 'string' && text !== '') {
                appendSuggestion(text)
              }
              finishSuggestion()
              return
            }
            if (response.body) {
              await consumeTextOrSseStream(response.body, {
                signal: abortController.signal,
                isSse: contentType.includes('text/event-stream'),
                onChunk: appendSuggestion,
              })
              finishSuggestion()
              return
            }
            const text = await response.text()
            appendSuggestion(text)
            finishSuggestion()
            return
          }
        } catch (err) {
          if (abortController.signal.aborted || reqId !== instance.requestId) {
            return
          }
          this.storage.loading = false
          this.storage.suggestion = ''
          this.options.onError?.(err instanceof Error ? err : new Error(String(err)))
        }
      })()

      void doc
    }

    ;(this.storage as VaulterAiAutocompleteStorage & {
      __trigger?: () => boolean
      __accept?: () => boolean
      __dismiss?: () => boolean
    }).__trigger = () => {
      if (this.editor.isDestroyed) return false
      runAutocomplete(this.editor, this.editor.view, true)
      return true
    }

    ;(this.storage as VaulterAiAutocompleteStorage & {
      __accept?: () => boolean
    }).__accept = () => {
      if (this.editor.isDestroyed) return false
      const pluginState = VAULTER_AI_AUTOCOMPLETE_PLUGIN_KEY.getState(this.editor.state)
      if (!pluginState || pluginState.suggestion === '' || pluginState.pos === null) {
        return false
      }
      const text = pluginState.suggestion
      const pos = Math.max(0, Math.min(this.editor.state.doc.content.size, pluginState.pos))
      cancelPending()
      const tr = this.editor.state.tr.insertText(text, pos)
      tr.setMeta(AUTOCOMPLETE_META, { pos: null, suggestion: '', loading: false, requestId: instance.requestId })
      this.editor.view.dispatch(tr)
      this.options.onAccept?.(text)
      return true
    }

    ;(this.storage as VaulterAiAutocompleteStorage & {
      __dismiss?: () => boolean
    }).__dismiss = () => {
      if (this.editor.isDestroyed) return false
      const hadActive = this.storage.loading || this.storage.suggestion !== ''
      cancelPending(this.editor.view)
      if (hadActive) {
        this.options.onCancel?.()
      }
      return hadActive
    }

    const yieldSet = new Set(this.options.yieldInNodeTypes ?? ['tableCell', 'tableHeader'])

    return [
      new Plugin<AutocompletePluginState>({
        key: VAULTER_AI_AUTOCOMPLETE_PLUGIN_KEY,
        state: {
          init: () => ({ pos: null, suggestion: '', loading: false, requestId: 0 }),
          apply: (tr, value, oldState, newState) => {
            const meta = tr.getMeta(AUTOCOMPLETE_META) as AutocompletePluginState | undefined
            if (meta) {
              return meta
            }
            if (tr.docChanged || !oldState.selection.eq(newState.selection)) {
              if (instance.debounceTimer !== null) {
                clearTimeout(instance.debounceTimer)
                instance.debounceTimer = null
              }
              if (instance.abortController) {
                instance.abortController.abort()
                instance.abortController = null
                instance.requestId += 1
              }
              this.storage.suggestion = ''
              this.storage.loading = false
              this.storage.pos = null
              return { pos: null, suggestion: '', loading: false, requestId: instance.requestId }
            }
            return value
          },
        },
        props: {
          decorations: (state) => {
            const pluginState = VAULTER_AI_AUTOCOMPLETE_PLUGIN_KEY.getState(state)
            if (!pluginState || pluginState.suggestion === '' || pluginState.pos === null) {
              return DecorationSet.empty
            }
            const pos = Math.max(0, Math.min(state.doc.content.size, pluginState.pos))
            const text = pluginState.suggestion
            return DecorationSet.create(state.doc, [
              Decoration.widget(
                pos,
                () => {
                  const span = document.createElement('span')
                  span.className = 'vaulter-ai-autocomplete-ghost'
                  span.dataset.testid = 'ai-autocomplete-ghost'
                  span.setAttribute('aria-hidden', 'true')
                  span.setAttribute('contenteditable', 'false')
                  span.textContent = text
                  return span
                },
                { side: 1, key: 'vaulter-ai-autocomplete-ghost' },
              ),
            ])
          },
          handleKeyDown: (view, event) => {
            const pluginState = VAULTER_AI_AUTOCOMPLETE_PLUGIN_KEY.getState(view.state)
            if (event.key === 'Escape') {
              const hadActive = Boolean(
                instance.abortController
                || instance.debounceTimer !== null
                || (pluginState && (pluginState.suggestion !== '' || pluginState.loading)),
              )
              if (!hadActive) {
                return false
              }
              event.preventDefault()
              cancelPending(view)
              this.options.onCancel?.()
              return true
            }

            if (event.key === 'Tab') {
              if (!pluginState || pluginState.suggestion === '' || pluginState.pos === null) {
                return false
              }
              if (isInsideNodeTypes(view.state.selection.$from, yieldSet)) {
                return false
              }
              event.preventDefault()
              const text = pluginState.suggestion
              const pos = Math.max(0, Math.min(view.state.doc.content.size, pluginState.pos))
              cancelPending()
              const tr = view.state.tr.insertText(text, pos)
              tr.setMeta(AUTOCOMPLETE_META, { pos: null, suggestion: '', loading: false, requestId: instance.requestId })
              view.dispatch(tr)
              this.options.onAccept?.(text)
              return true
            }

            return false
          },
        },
        view: (editorView) => {
          instance.lastHandledDoc = editorView.state.doc
          return {
            update: (view, prevState) => {
              if (this.options.enabled === false) {
                return
              }
              if (!view.state.doc.eq(prevState.doc) && view.state.doc !== instance.lastHandledDoc) {
                instance.lastHandledDoc = view.state.doc
                const delay = this.options.debounceMs ?? 250
                if (delay <= 0) {
                  runAutocomplete(this.editor, view, false)
                } else {
                  instance.debounceTimer = setTimeout(() => {
                    instance.debounceTimer = null
                    runAutocomplete(this.editor, view, false)
                  }, delay)
                }
              }
            },
            destroy: () => {
              cancelPending()
            },
          }
        },
      }),
    ]
  },

  addCommands() {
    return {
      triggerAutocomplete: () => () => {
        const fn = (this.storage as VaulterAiAutocompleteStorage & { __trigger?: () => boolean }).__trigger
        return fn ? fn() : false
      },
      acceptAutocomplete: () => () => {
        const fn = (this.storage as VaulterAiAutocompleteStorage & { __accept?: () => boolean }).__accept
        return fn ? fn() : false
      },
      dismissAutocomplete: () => () => {
        const fn = (this.storage as VaulterAiAutocompleteStorage & { __dismiss?: () => boolean }).__dismiss
        return fn ? fn() : false
      },
    }
  },
})
