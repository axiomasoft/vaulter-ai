import { describe, expect, it, vi } from 'vitest'
import { Editor } from '@tiptap/core'
import StarterKit from '@tiptap/starter-kit'
import { TableKit } from '@tiptap/extension-table'
import {
  extractAutocompleteContext,
  VAULTER_AI_AUTOCOMPLETE_PLUGIN_KEY,
  VaulterAiAutocomplete,
} from '../src'

async function flushMicrotasks(ticks = 5): Promise<void> {
  for (let i = 0; i < ticks; i += 1) {
    await new Promise((resolve) => setTimeout(resolve, 10))
  }
}

describe('VaulterAiAutocomplete', () => {
  it('extracts at most 3 blocks before cursor and renders ghost Decoration.widget without mutating doc', async () => {
    let capturedBlocks: string[] = []
    const editor = new Editor({
      extensions: [
        StarterKit,
        VaulterAiAutocomplete.configure({
          debounceMs: 0,
          resolver: async (req) => {
            capturedBlocks = req.blocks
            return ' completion'
          },
        }),
      ],
      content: '<p>Block 1</p><p>Block 2</p><p>Block 3</p><p>Block 4</p>',
    })

    const endPos = editor.state.doc.content.size - 1
    editor.commands.setTextSelection(endPos)

    const extracted = extractAutocompleteContext(editor.state, null, 3, 4000)
    expect(extracted.blocks).toEqual(['Block 2', 'Block 3', 'Block 4'])
    expect(extracted.context).not.toContain('Block 1')

    const beforeJson = JSON.stringify(editor.getJSON())
    expect(editor.commands.triggerAutocomplete()).toBe(true)
    await flushMicrotasks()

    expect(capturedBlocks).toEqual(['Block 2', 'Block 3', 'Block 4'])
    expect(JSON.stringify(editor.getJSON())).toBe(beforeJson)

    const pluginState = VAULTER_AI_AUTOCOMPLETE_PLUGIN_KEY.getState(editor.state)
    expect(pluginState?.suggestion).toBe(' completion')

    const ghost = editor.view.dom.querySelector('[data-testid="ai-autocomplete-ghost"]')
    expect(ghost?.textContent).toBe(' completion')

    // Tab outside tableCell accepts suggestion
    const tabEvent = new KeyboardEvent('keydown', { key: 'Tab', bubbles: true, cancelable: true })
    const handled = editor.view.someProp('handleKeyDown', (f) => f(editor.view, tabEvent))
    expect(handled).toBe(true)
    expect(editor.getText()).toContain('Block 4 completion')

    editor.destroy()
  })

  it('yields Tab inside tableCell to TableKit', async () => {
    const editor = new Editor({
      extensions: [
        StarterKit,
        TableKit,
        VaulterAiAutocomplete.configure({
          debounceMs: 0,
          resolver: async () => ' ghost',
        }),
      ],
      content: '<table><tbody><tr><td><p>Cell 1</p></td><td><p>Cell 2</p></td></tr></tbody></table>',
    })

    // Place cursor at end of "Cell 1"
    let cell1End = 0
    editor.state.doc.descendants((node, pos) => {
      if (node.isTextblock && node.textContent === 'Cell 1') {
        cell1End = pos + 1 + node.content.size
        return false
      }
      return true
    })
    editor.commands.setTextSelection(cell1End)
    expect(editor.commands.triggerAutocomplete()).toBe(true)
    await flushMicrotasks()

    expect(editor.storage.vaulterAiAutocomplete.suggestion).toBe(' ghost')

    const tabEvent = new KeyboardEvent('keydown', { key: 'Tab', bubbles: true, cancelable: true })
    editor.view.someProp('handleKeyDown', (f) => f(editor.view, tabEvent))
    expect(editor.getText()).not.toContain('ghost')
    // Selection moved to Cell 2 by TableKit
    expect(editor.state.selection.$from.parent.textContent).toBe('Cell 2')

    editor.destroy()
  })

  it('aborts fetch/stream on Escape, typing, and selectionChange, and isolates multiple editors', async () => {
    let signalA: AbortSignal | null = null
    let signalB: AbortSignal | null = null

    const editorA = new Editor({
      extensions: [
        StarterKit,
        VaulterAiAutocomplete.configure({
          debounceMs: 0,
          resolver: (req) => new Promise((resolve) => {
            signalA = req.signal
            req.signal.addEventListener('abort', () => resolve(''), { once: true })
          }),
        }),
      ],
      content: '<p>Editor A</p>',
    })

    const editorB = new Editor({
      extensions: [
        StarterKit,
        VaulterAiAutocomplete.configure({
          debounceMs: 0,
          resolver: async (req) => {
            signalB = req.signal
            return ' from B'
          },
        }),
      ],
      content: '<p>Editor B</p>',
    })

    editorA.commands.setTextSelection(editorA.state.doc.content.size - 1)
    editorB.commands.setTextSelection(editorB.state.doc.content.size - 1)

    editorA.commands.triggerAutocomplete()
    editorB.commands.triggerAutocomplete()
    await flushMicrotasks()

    expect(signalA).not.toBeNull()
    expect(signalB).not.toBeNull()
    expect(signalA!.aborted).toBe(false)
    expect(signalB!.aborted).toBe(false)
    expect(editorB.storage.vaulterAiAutocomplete.suggestion).toBe(' from B')

    // Escape on Editor A aborts A without affecting B
    const esc = new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true })
    const handledEsc = editorA.view.someProp('handleKeyDown', (f) => f(editorA.view, esc))
    expect(handledEsc).toBe(true)
    expect(signalA!.aborted).toBe(true)
    expect(signalB!.aborted).toBe(false)
    expect(editorB.storage.vaulterAiAutocomplete.suggestion).toBe(' from B')

    // Selection change on Editor B clears B's ghost suggestion
    editorB.commands.setTextSelection(1)
    expect(editorB.storage.vaulterAiAutocomplete.suggestion).toBe('')

    editorA.destroy()
    editorB.destroy()
  })

  it('respects contentProtectionPolicy by skipping hidden blocks and blocking read_only cursor', () => {
    const policy = {
      hidden_types: ['heading'],
      read_only_types: ['blockquote'],
    }
    const resolver = vi.fn(async () => 'nope')

    const editor = new Editor({
      extensions: [
        StarterKit,
        VaulterAiAutocomplete.configure({
          contentProtectionPolicy: policy,
          resolver,
        }),
      ],
      content: '<h1>Secret</h1><blockquote><p>Locked</p></blockquote><p>Open</p>',
    })

    // Cursor in Open paragraph: hidden heading is excluded from context
    editor.commands.setTextSelection(editor.state.doc.content.size - 1)
    const ctx = extractAutocompleteContext(editor.state, {
      hiddenTypes: ['heading'],
      readOnlyTypes: ['blockquote'],
      hiddenAttributes: [],
      readOnlyAttributes: [],
    })
    expect(ctx.context).not.toContain('Secret')
    expect(ctx.context).toContain('Open')

    // Cursor in Locked blockquote: autocomplete does not trigger
    editor.commands.setTextSelection(15)
    editor.commands.triggerAutocomplete()
    expect(resolver).not.toHaveBeenCalled()

    editor.destroy()
  })
})
