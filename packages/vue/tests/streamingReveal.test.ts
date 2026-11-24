import { afterEach, describe, expect, it, vi } from 'vitest'
import { Editor } from '@tiptap/core'
import StarterKit from '@tiptap/starter-kit'
import * as Y from 'yjs'
import { prosemirrorToYXmlFragment, ySyncPlugin } from '@tiptap/y-tiptap'
import { STREAMING_REVEAL_PLUGIN_KEY, StreamingReveal } from '../src'

function createAwarenessMock(initial: Array<[number, Record<string, unknown>]> = []) {
  const states = new Map<number, Record<string, unknown>>(initial)
  return {
    states,
    getStates: () => states,
  }
}

function setupCollabReveal(
  initialHtml: string,
  awareness: ReturnType<typeof createAwarenessMock>,
  options: Record<string, unknown> = {},
) {
  const ydoc = new Y.Doc()
  const fragment = ydoc.getXmlFragment('default')

  const seed = new Editor({ extensions: [StarterKit], content: initialHtml })
  prosemirrorToYXmlFragment(seed.state.doc, fragment)
  seed.destroy()

  const editor = new Editor({
    extensions: [
      StarterKit,
      StreamingReveal.configure({
        awareness,
        ...options,
      }),
    ],
  })
  editor.registerPlugin(ySyncPlugin(fragment))

  return { ydoc, fragment, editor }
}

function applyRemoteTextInsert(
  targetDoc: Y.Doc,
  clientId: number,
  blockIndex: number,
  offset: number,
  text: string,
) {
  const remote = new Y.Doc()
  remote.clientID = clientId
  Y.applyUpdate(remote, Y.encodeStateAsUpdate(targetDoc))
  const frag = remote.getXmlFragment('default')
  const p = frag.get(blockIndex) as Y.XmlElement
  const yText = p.get(0) as Y.XmlText
  yText.insert(offset, text)
  Y.applyUpdate(targetDoc, Y.encodeStateAsUpdate(remote))
}

describe('StreamingReveal (view-only Yjs AI decorations)', () => {
  afterEach(() => {
    vi.useRealTimers()
  })

  it('highlights remote AI inserts by awareness aiInstanceId without writing to Y.Doc, and fades over 550ms', () => {
    vi.useFakeTimers()
    const aiClientId = 9001
    const awareness = createAwarenessMock([
      [aiClientId, { user: { name: 'AI', aiInstanceId: 'ai-inst-1' } }],
    ])

    const { ydoc, editor } = setupCollabReveal('<p>Hello</p>', awareness)
    const docUpdates: Uint8Array[] = []
    ydoc.on('update', (update: Uint8Array, origin: unknown) => {
      if (origin === 'y-sync') return
      docUpdates.push(update)
    })

    applyRemoteTextInsert(ydoc, aiClientId, 0, 5, ' AI world')

    expect(editor.getText()).toBe('Hello AI world')
    const spans = editor.storage.streamingReveal.getActiveSpans()
    expect(spans).toHaveLength(1)
    expect(spans[0].aiInstanceId).toBe('ai-inst-1')
    expect(spans[0].expectedLength).toBe(9)

    const revealedEl = editor.view.dom.querySelector('.vaulter-ai-streaming-reveal')
    expect(revealedEl?.textContent).toBe(' AI world')
    expect(revealedEl?.getAttribute('data-ai-instance-id')).toBe('ai-inst-1')

    // Zero content marks added to editor JSON
    expect(JSON.stringify(editor.getJSON())).not.toContain('streamingReveal')

    // Advance 550ms -> fade completes and decorations are removed
    const updatesBeforeTick = docUpdates.length
    vi.advanceTimersByTime(600)
    expect(editor.storage.streamingReveal.getActiveSpans()).toHaveLength(0)
    expect(editor.view.dom.querySelector('.vaulter-ai-streaming-reveal')).toBeNull()
    expect(docUpdates.length).toBe(updatesBeforeTick)

    editor.destroy()
  })

  it('skips human inserts without aiInstanceId and skips mixed-author batches', () => {
    const humanClientId = 1001
    const aiClientId = 9002
    const awareness = createAwarenessMock([
      [humanClientId, { user: { name: 'Human' } }],
      [aiClientId, { user: { name: 'AI', aiInstanceId: 'ai-2' } }],
    ])

    const { ydoc, editor } = setupCollabReveal('<p>Base</p>', awareness)

    applyRemoteTextInsert(ydoc, humanClientId, 0, 4, ' human')
    expect(editor.storage.streamingReveal.getActiveSpans()).toHaveLength(0)

    // Mixed-author batch: combine updates from two different clientIDs in one applyUpdate
    const doc1 = new Y.Doc()
    doc1.clientID = aiClientId
    Y.applyUpdate(doc1, Y.encodeStateAsUpdate(ydoc))
    ;((doc1.getXmlFragment('default').get(0) as Y.XmlElement).get(0) as Y.XmlText).insert(10, ' A')

    const doc2 = new Y.Doc()
    doc2.clientID = humanClientId
    Y.applyUpdate(doc2, Y.encodeStateAsUpdate(ydoc))
    ;((doc2.getXmlFragment('default').get(0) as Y.XmlElement).get(0) as Y.XmlText).insert(10, ' B')

    const merged = Y.mergeUpdates([Y.encodeStateAsUpdate(doc1), Y.encodeStateAsUpdate(doc2)])
    Y.applyUpdate(ydoc, merged)

    expect(editor.storage.streamingReveal.getActiveSpans()).toHaveLength(0)
    editor.destroy()
  })

  it('enforces 400-char cap and skips whole-node batches > 2 blocks', () => {
    const aiClientId = 9003
    const awareness = createAwarenessMock([
      [aiClientId, { user: { name: 'AI', aiInstanceId: 'ai-3' } }],
    ])

    const { ydoc, editor } = setupCollabReveal('<p>Start</p>', awareness)

    // Insert 500 chars -> capped at 400 chars
    applyRemoteTextInsert(ydoc, aiClientId, 0, 5, 'x'.repeat(500))
    const spans = editor.storage.streamingReveal.getActiveSpans()
    const totalChars = spans.reduce((sum, s) => sum + s.expectedLength, 0)
    expect(totalChars).toBeLessThanOrEqual(400)

    // Whole-node batch > 2 blocks (3 paragraphs) -> skipped
    const bulkDoc = new Y.Doc()
    bulkDoc.clientID = aiClientId
    Y.applyUpdate(bulkDoc, Y.encodeStateAsUpdate(ydoc))
    const frag = bulkDoc.getXmlFragment('default')
    for (let i = 0; i < 3; i += 1) {
      const p = new Y.XmlElement('paragraph')
      p.insert(0, [new Y.XmlText(`Bulk ${i}`)])
      frag.insert(frag.length, [p])
    }
    const beforeCount = editor.storage.streamingReveal.getActiveSpans().length
    Y.applyUpdate(ydoc, Y.encodeStateAsUpdate(bulkDoc))
    expect(editor.storage.streamingReveal.getActiveSpans().length).toBe(beforeCount)

    editor.destroy()
  })

  it('tracks Y.RelativePosition across concurrent edits before span and hides stale span on length change', () => {
    const aiClientId = 9004
    const awareness = createAwarenessMock([
      [aiClientId, { user: { name: 'AI', aiInstanceId: 'ai-4' } }],
    ])

    const { ydoc, editor } = setupCollabReveal('<p>Hello</p>', awareness)
    applyRemoteTextInsert(ydoc, aiClientId, 0, 5, ' AI')

    expect(editor.view.dom.querySelector('.vaulter-ai-streaming-reveal')?.textContent).toBe(' AI')

    // Concurrent edit BEFORE the revealed span shifts relative position without invalidating length
    editor.commands.insertContentAt(1, 'PRE ')
    // Trigger a view-only selection update so decorations re-evaluate after ySyncPlugin's view.update() synced Y.Doc
    editor.commands.setTextSelection(1)
    expect(editor.view.dom.querySelector('.vaulter-ai-streaming-reveal')?.textContent).toBe(' AI')

    // Edit INSIDE the revealed span changes its length -> stale span is not drawn
    const text = editor.getText()
    const aiStart = text.indexOf(' AI') + 1
    editor.commands.insertContentAt(aiStart + 2, 'Z')
    editor.commands.setTextSelection(1)
    expect(STREAMING_REVEAL_PLUGIN_KEY.getState(editor.state)?.spans.length).toBe(1)
    expect(editor.view.dom.querySelector('.vaulter-ai-streaming-reveal')).toBeNull()

    editor.destroy()
  })
})
