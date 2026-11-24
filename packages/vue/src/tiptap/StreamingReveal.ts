import { Extension } from '@tiptap/core'
import { Plugin, PluginKey, type EditorState } from '@tiptap/pm/state'
import { Decoration, DecorationSet, type EditorView } from '@tiptap/pm/view'
import * as Y from 'yjs'
import type {
  StreamingRevealSpanInfo,
  VaulterStreamingRevealOptions,
  VaulterStreamingRevealStorage,
} from './types'

export const STREAMING_REVEAL_PLUGIN_KEY = new PluginKey<StreamingRevealPluginState>('vaulterStreamingReveal')
const STREAMING_REVEAL_TICK_META = 'vaulterStreamingRevealTick'
const STREAMING_REVEAL_ADD_META = 'vaulterStreamingRevealAdd'
const STREAMING_REVEAL_CLEAR_META = 'vaulterStreamingRevealClear'

interface RevealSpan {
  aiInstanceId: string
  expectedLength: number
  createdAt: number
  startRel: Y.RelativePosition | null
  endRel: Y.RelativePosition | null
  fallbackFrom: number
  fallbackTo: number
}

interface StreamingRevealPluginState {
  spans: RevealSpan[]
}

interface YSyncPluginStateLike {
  doc: Y.Doc
  type: Y.XmlFragment
  binding: {
    mapping: Map<unknown, unknown>
  }
}

// eslint-disable-next-line @typescript-eslint/no-explicit-any
type AnyYType = Y.AbstractType<any>

function findYSyncState(state: EditorState): YSyncPluginStateLike | null {
  for (const plugin of state.plugins) {
    const key = (plugin as unknown as { key?: string }).key
    if (typeof key === 'string' && key.startsWith('y-sync')) {
      const pluginState = plugin.getState(state) as YSyncPluginStateLike | undefined
      if (pluginState && pluginState.doc && pluginState.type && pluginState.binding) {
        return pluginState
      }
    }
  }
  return null
}

function getParentContainer(type: AnyYType): AnyYType | null {
  const item = type._item
  if (!item || !item.parent) {
    return null
  }
  return item.parent as AnyYType
}

function hasDeletedAncestor(type: AnyYType, root: Y.XmlFragment): boolean {
  let cursor: AnyYType | null = type
  while (cursor !== null) {
    if (cursor._item?.deleted) {
      return true
    }
    if (cursor === root) {
      return false
    }
    cursor = getParentContainer(cursor)
  }
  return true
}

function getXmlTextOffset(xmlText: Y.XmlText, targetItem: Y.Item): number {
  let offset = 0
  let left = targetItem.left
  while (left !== null) {
    if (!left.deleted && left.countable) {
      offset += left.length
    }
    left = left.left
  }
  void xmlText
  return offset
}

function computePmStartOfXmlText(
  fragment: Y.XmlFragment,
  target: Y.XmlText,
  mapping: Map<unknown, unknown>,
): number | null {
  let pos = 0
  let found: number | null = null

  function walk(container: Y.XmlFragment | Y.XmlElement): boolean {
    const children = container.toArray()
    for (const child of children) {
      if (child instanceof Y.XmlText) {
        if (child._item?.deleted) continue
        if (child === target) {
          found = pos
          return true
        }
        pos += child.length
      } else if (child instanceof Y.XmlElement) {
        if (child._item?.deleted) continue
        pos += 1
        if (walk(child)) return true
        pos += 1
      }
    }
    return false
  }

  void mapping
  walk(fragment)
  return found
}

function resolveYjsRelativePos(
  yDoc: Y.Doc,
  fragment: Y.XmlFragment,
  relPos: Y.RelativePosition,
  mapping: Map<unknown, unknown>,
): number | null {
  const abs = Y.createAbsolutePositionFromRelativePosition(relPos, yDoc)
  if (
    abs === null
    || (abs.type !== fragment && !Y.isParentOf(fragment, abs.type._item))
    || hasDeletedAncestor(abs.type, fragment)
  ) {
    return null
  }

  if (abs.type instanceof Y.XmlText) {
    const base = computePmStartOfXmlText(fragment, abs.type, mapping)
    if (base === null) return null
    return base + abs.index
  }

  return null
}

function resolveSpanRange(
  state: EditorState,
  span: RevealSpan,
): { from: number; to: number } | null {
  if (span.startRel && span.endRel) {
    const syncState = findYSyncState(state)
    if (!syncState) return null
    const from = resolveYjsRelativePos(syncState.doc, syncState.type, span.startRel, syncState.binding.mapping)
    const to = resolveYjsRelativePos(syncState.doc, syncState.type, span.endRel, syncState.binding.mapping)
    if (from === null || to === null || to <= from) {
      return null
    }
    if (to - from !== span.expectedLength) {
      return null
    }
    return { from, to }
  }

  const docSize = state.doc.content.size
  const from = Math.max(0, Math.min(docSize, span.fallbackFrom))
  const to = Math.max(from, Math.min(docSize, span.fallbackTo))
  if (to <= from || to - from !== span.expectedLength) {
    return null
  }
  return { from, to }
}

function applyCharCap(spans: RevealSpan[], maxChars: number): RevealSpan[] {
  let total = spans.reduce((sum, s) => sum + s.expectedLength, 0)
  if (total <= maxChars) {
    return spans
  }
  const result = [...spans]
  while (result.length > 0 && total > maxChars) {
    const oldest = result[0]
    const excess = total - maxChars
    if (oldest.expectedLength <= excess) {
      total -= oldest.expectedLength
      result.shift()
    } else {
      const trimBy = excess
      if (oldest.startRel && oldest.endRel) {
        // Trim leading chars by dropping oldest span when relative pos cannot be trivially shifted without Y.Doc
        total -= oldest.expectedLength
        result.shift()
      } else {
        result[0] = {
          ...oldest,
          fallbackFrom: oldest.fallbackFrom + trimBy,
          expectedLength: oldest.expectedLength - trimBy,
        }
        total -= trimBy
      }
    }
  }
  return result
}

export const StreamingReveal = Extension.create<VaulterStreamingRevealOptions, VaulterStreamingRevealStorage>({
  name: 'streamingReveal',

  addOptions() {
    return {
      fadeDurationMs: 550,
      maxChars: 400,
      maxBlocks: 2,
      quantizeStepMs: 100,
      className: 'vaulter-ai-streaming-reveal',
      awareness: null,
      includeLocal: false,
    }
  },

  addStorage(): VaulterStreamingRevealStorage {
    return {
      getActiveSpans: () => [],
    }
  },

  addProseMirrorPlugins() {
    const fadeDurationMs = this.options.fadeDurationMs ?? 550
    const maxChars = this.options.maxChars ?? 400
    const maxBlocks = this.options.maxBlocks ?? 2
    const quantizeStepMs = this.options.quantizeStepMs ?? 100
    const className = this.options.className ?? 'vaulter-ai-streaming-reveal'

    let attachedDoc: Y.Doc | null = null
    let attachedFragment: Y.XmlFragment | null = null
    let pendingYjsSpans: RevealSpan[] | null = null
    let tickTimer: ReturnType<typeof setTimeout> | null = null
    let currentView: EditorView | null = null

    const getNow = () => Date.now()

    const scheduleTick = (spans: RevealSpan[]) => {
      if (tickTimer !== null) {
        clearTimeout(tickTimer)
        tickTimer = null
      }
      if (spans.length === 0 || !currentView || currentView.isDestroyed) {
        return
      }
      tickTimer = setTimeout(() => {
        tickTimer = null
        if (!currentView || currentView.isDestroyed) return
        currentView.dispatch(
          currentView.state.tr
            .setMeta(STREAMING_REVEAL_TICK_META, getNow())
            .setMeta('addToHistory', false),
        )
      }, Math.min(quantizeStepMs, fadeDurationMs))
    }

    const resolveAiInstanceId = (clientId: number, yTr: Y.Transaction): string | null => {
      if (this.options.resolveAiInstanceId) {
        const custom = this.options.resolveAiInstanceId(clientId, yTr)
        if (typeof custom === 'string' && custom !== '') {
          if (this.options.aiInstanceId && custom !== this.options.aiInstanceId) {
            return null
          }
          return custom
        }
      }
      const states = this.options.awareness?.getStates()
      const clientState = states?.get(clientId)
      const user = clientState?.user as Record<string, unknown> | undefined
      const id = typeof user?.aiInstanceId === 'string'
        ? user.aiInstanceId
        : (typeof clientState?.aiInstanceId === 'string' ? clientState.aiInstanceId : null)
      if (!id) return null
      if (this.options.aiInstanceId && id !== this.options.aiInstanceId) {
        return null
      }
      return id
    }

    const onBeforeObserverCalls = (yTr: Y.Transaction) => {
      pendingYjsSpans = null
      if (!attachedDoc || !attachedFragment) return
      if (!this.options.includeLocal && yTr.local) return
      if (yTr.origin instanceof Y.UndoManager) return

      const advancingClients: number[] = []
      yTr.afterState.forEach((afterClock, clientId) => {
        const beforeClock = yTr.beforeState.get(clientId) ?? 0
        if (afterClock > beforeClock) {
          advancingClients.push(clientId)
        }
      })

      // Skip empty or mixed-author batches
      if (advancingClients.length !== 1) {
        return
      }

      const authorClientId = advancingClients[0]
      const aiInstanceId = resolveAiInstanceId(authorClientId, yTr)
      if (!aiInstanceId) {
        return
      }

      const beforeClock = yTr.beforeState.get(authorClientId) ?? 0
      const afterClock = yTr.afterState.get(authorClientId) ?? 0
      const clientItems = attachedDoc.store.clients.get(authorClientId)
      if (!clientItems) return

      let blockInsertCount = 0
      const touchedBlocks = new Set<AnyYType>()
      const createdSpans: RevealSpan[] = []
      const now = getNow()

      for (const struct of clientItems) {
        if (!(struct instanceof Y.Item)) continue
        if (struct.id.clock < beforeClock || struct.id.clock >= afterClock) continue
        if (struct.deleted) continue
        if (!Y.isParentOf(attachedFragment, struct)) continue

        if (struct.content instanceof Y.ContentType && struct.content.type instanceof Y.XmlElement) {
          blockInsertCount += 1
          touchedBlocks.add(struct.content.type)
          continue
        }

        if (struct.content instanceof Y.ContentString && struct.parent instanceof Y.XmlText) {
          const xmlText = struct.parent
          const parentBlock = getParentContainer(xmlText)
          if (parentBlock) {
            touchedBlocks.add(parentBlock)
          }
          const offset = getXmlTextOffset(xmlText, struct)
          const len = struct.length
          if (len <= 0) continue
          const clampedLen = Math.min(len, maxChars)
          const startOffset = offset + (len - clampedLen)
          const startRel = Y.createRelativePositionFromTypeIndex(xmlText, startOffset, 0)
          const endRel = Y.createRelativePositionFromTypeIndex(xmlText, offset + len, -1)
          createdSpans.push({
            aiInstanceId,
            expectedLength: clampedLen,
            createdAt: now,
            startRel,
            endRel,
            fallbackFrom: 0,
            fallbackTo: 0,
          })
        }
      }

      if (blockInsertCount > maxBlocks || touchedBlocks.size > maxBlocks) {
        pendingYjsSpans = null
        return
      }

      if (createdSpans.length > 0) {
        pendingYjsSpans = createdSpans
      }
    }

    const onAfterTransaction = () => {
      pendingYjsSpans = null
    }

    const ensureYjsAttached = (state: EditorState) => {
      const syncState = findYSyncState(state)
      const doc = (this.options.yDoc as Y.Doc | undefined) ?? syncState?.doc ?? null
      const fragment = (this.options.fragment as Y.XmlFragment | undefined) ?? syncState?.type ?? null

      if (doc === attachedDoc && fragment === attachedFragment) {
        return
      }
      if (attachedDoc) {
        attachedDoc.off('beforeObserverCalls', onBeforeObserverCalls)
        attachedDoc.off('afterTransaction', onAfterTransaction)
      }
      attachedDoc = doc
      attachedFragment = fragment
      if (attachedDoc) {
        attachedDoc.on('beforeObserverCalls', onBeforeObserverCalls)
        attachedDoc.on('afterTransaction', onAfterTransaction)
      }
    }

    this.storage.getActiveSpans = (): readonly StreamingRevealSpanInfo[] => {
      if (!currentView || currentView.isDestroyed) return []
      const pluginState = STREAMING_REVEAL_PLUGIN_KEY.getState(currentView.state)
      if (!pluginState) return []
      return pluginState.spans.map((s) => ({
        aiInstanceId: s.aiInstanceId,
        expectedLength: s.expectedLength,
        createdAt: s.createdAt,
      }))
    }

    return [
      new Plugin<StreamingRevealPluginState>({
        key: STREAMING_REVEAL_PLUGIN_KEY,
        state: {
          init: () => ({ spans: [] }),
          apply: (tr, value, _oldState, newState) => {
            if (tr.getMeta(STREAMING_REVEAL_CLEAR_META)) {
              return { spans: [] }
            }

            const now = getNow()
            let spans = value.spans.filter((s) => now - s.createdAt < fadeDurationMs)

            if (!tr.getMeta(STREAMING_REVEAL_TICK_META) && tr.docChanged) {
              spans = spans.map((s) =>
                s.startRel
                  ? s
                  : {
                    ...s,
                    fallbackFrom: tr.mapping.map(s.fallbackFrom, 1),
                    fallbackTo: Math.max(
                      tr.mapping.map(s.fallbackFrom, 1),
                      tr.mapping.map(s.fallbackTo, -1),
                    ),
                  },
              )
            }

            const incoming = pendingYjsSpans ?? (tr.getMeta(STREAMING_REVEAL_ADD_META) as RevealSpan[] | undefined) ?? null
            pendingYjsSpans = null

            if (incoming && incoming.length > 0) {
              for (const nextSpan of incoming) {
                const last = spans[spans.length - 1]
                if (last && last.aiInstanceId === nextSpan.aiInstanceId) {
                  const lastRange = resolveSpanRange(newState, last)
                  const nextRange = resolveSpanRange(newState, nextSpan)
                  if (lastRange && nextRange && lastRange.to === nextRange.from) {
                    spans[spans.length - 1] = {
                      ...last,
                      endRel: nextSpan.endRel,
                      fallbackTo: nextSpan.fallbackTo,
                      expectedLength: last.expectedLength + nextSpan.expectedLength,
                      createdAt: now,
                    }
                    continue
                  }
                }
                spans.push(nextSpan)
              }
              spans = applyCharCap(spans, maxChars)
            }

            if (spans.length > 0) {
              scheduleTick(spans)
            }

            return { spans }
          },
        },
        props: {
          decorations: (state) => {
            const pluginState = STREAMING_REVEAL_PLUGIN_KEY.getState(state)
            if (!pluginState || pluginState.spans.length === 0) {
              return DecorationSet.empty
            }
            const now = getNow()
            const decorations: Decoration[] = []

            for (const span of pluginState.spans) {
              const age = now - span.createdAt
              if (age >= fadeDurationMs) continue

              const resolved = resolveSpanRange(state, span)
              if (!resolved) continue

              const quantizedAge = Math.floor(age / quantizeStepMs) * quantizeStepMs
              const opacity = Math.max(0, 1 - quantizedAge / fadeDurationMs)

              decorations.push(
                Decoration.inline(resolved.from, resolved.to, {
                  class: className,
                  'data-ai-instance-id': span.aiInstanceId,
                  'data-reveal-age': String(quantizedAge),
                  style: `--vaulter-reveal-opacity: ${opacity.toFixed(2)}; opacity: ${opacity.toFixed(2)};`,
                }),
              )
            }

            return DecorationSet.create(state.doc, decorations)
          },
        },
        view: (editorView) => {
          currentView = editorView
          ensureYjsAttached(editorView.state)
          return {
            update: (view) => {
              currentView = view
              ensureYjsAttached(view.state)
            },
            destroy: () => {
              if (tickTimer !== null) {
                clearTimeout(tickTimer)
                tickTimer = null
              }
              if (attachedDoc) {
                attachedDoc.off('beforeObserverCalls', onBeforeObserverCalls)
                attachedDoc.off('afterTransaction', onAfterTransaction)
                attachedDoc = null
                attachedFragment = null
              }
              currentView = null
            },
          }
        },
      }),
    ]
  },

  addCommands() {
    return {
      triggerStreamingReveal: (from: number, to: number, aiInstanceId = 'local-ai') => ({ editor, dispatch }) => {
        if (!dispatch || to <= from) return false
        const len = Math.min(to - from, this.options.maxChars ?? 400)
        const clampedFrom = Math.max(0, to - len)
        const tr = editor.state.tr.setMeta(STREAMING_REVEAL_ADD_META, [
          {
            aiInstanceId,
            expectedLength: len,
            createdAt: Date.now(),
            startRel: null,
            endRel: null,
            fallbackFrom: clampedFrom,
            fallbackTo: to,
          } satisfies RevealSpan,
        ])
        tr.setMeta('addToHistory', false)
        editor.view.dispatch(tr)
        return true
      },
      clearStreamingReveal: () => ({ editor, dispatch }) => {
        if (!dispatch) return false
        const tr = editor.state.tr.setMeta(STREAMING_REVEAL_CLEAR_META, true)
        tr.setMeta('addToHistory', false)
        editor.view.dispatch(tr)
        return true
      },
    }
  },
})

export const VaulterStreamingReveal = StreamingReveal
