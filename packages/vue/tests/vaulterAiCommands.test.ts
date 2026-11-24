import { describe, expect, it, vi } from 'vitest'
import { Editor, Extension } from '@tiptap/core'
import StarterKit from '@tiptap/starter-kit'
import {
  createOfficeWorkspaceClient,
  getEditorContext,
  VaulterAiCommands,
} from '../src'

const BlockIdAttr = Extension.create({
  name: 'testBlockId',
  addGlobalAttributes() {
    return [
      {
        types: ['paragraph', 'heading'],
        attributes: {
          blockId: { default: null },
        },
      },
    ]
  },
})

async function flushMicrotasks(ticks = 6): Promise<void> {
  for (let i = 0; i < ticks; i += 1) {
    await new Promise((resolve) => setTimeout(resolve, 10))
  }
}

describe('VaulterAiCommands', () => {
  it('supports all preset & custom commands, preview (insert: false) + aiAccept preserving bold, and aiReject rollback', async () => {
    const calls: string[] = []
    const editor = new Editor({
      extensions: [
        StarterKit,
        BlockIdAttr,
        VaulterAiCommands.configure({
          completionResolver: async (req) => {
            calls.push(`${req.action}:${req.tone ?? ''}:${req.language ?? ''}`)
            return '30'
          },
        }),
      ],
      content: {
        type: 'doc',
        content: [
          {
            type: 'paragraph',
            attrs: { blockId: 'p42' },
            content: [
              { type: 'text', text: 'Payment in ' },
              { type: 'text', text: '15', marks: [{ type: 'bold' }] },
              { type: 'text', text: ' days.' },
            ],
          },
        ],
      },
    })

    // 1. Preview mode (insert: false) does not mutate doc until aiAccept
    editor.commands.setTextSelection({ from: 12, to: 14 })
    expect(editor.commands.aiTextPrompt({ text: 'Change to 30', format: 'plain-text', insert: false })).toBe(true)
    await flushMicrotasks()

    expect(editor.getText()).toContain('Payment in 15 days.')
    expect(editor.storage.vaulterAiCommands.state).toBe('preview')
    expect(editor.storage.vaulterAiCommands.response).toBe('30')

    expect(editor.commands.aiAccept()).toBe(true)
    expect(editor.getText()).toContain('Payment in 30 days.')
    expect(JSON.stringify(editor.getJSON())).toContain('"type":"bold"')

    // 2. Direct insert + aiReject restores original slice with bold
    editor.commands.setTextSelection({ from: 12, to: 14 })
    expect(editor.commands.shorten()).toBe(true)
    await flushMicrotasks()
    expect(editor.commands.aiRegenerate()).toBe(true)
    await flushMicrotasks()
    expect(editor.commands.aiReject()).toBe(true)
    expect(editor.storage.vaulterAiCommands.state).toBe('idle')
    expect(editor.storage.vaulterAiCommands.pastResponses).toEqual([])

    // 3. All preset commands invoke runner
    for (const run of [
      () => editor.commands.lengthen({ autoAccept: true }),
      () => editor.commands.tone({ tone: 'formal', autoAccept: true }),
      () => editor.commands.fixGrammar({ autoAccept: true }),
      () => editor.commands.translate({ language: 'ru', autoAccept: true }),
      () => editor.commands.summarize({ autoAccept: true }),
    ]) {
      editor.commands.setTextSelection({ from: 1, to: 5 })
      expect(run()).toBe(true)
      await flushMicrotasks()
    }

    expect(calls).toEqual([
      'prompt::',
      'shorten::',
      'shorten::',
      'lengthen::',
      'tone:formal:',
      'fixGrammar::',
      'translate::ru',
      'summarize::',
    ])

    editor.destroy()
  })

  it('guards against overlapping commands while loading and streams via SSE endpoint & OfficeChatClient', async () => {
    let release!: (value: string) => void
    const editor = new Editor({
      extensions: [
        StarterKit,
        VaulterAiCommands.configure({
          completionResolver: () => new Promise<string>((resolve) => {
            release = resolve
          }),
        }),
      ],
      content: '<p>Hello world</p>',
    })

    editor.commands.setTextSelection({ from: 1, to: 6 })
    expect(editor.commands.shorten()).toBe(true)
    expect(editor.storage.vaulterAiCommands.state).toBe('loading')
    expect(editor.commands.lengthen()).toBe(false)

    release('Hi')
    await flushMicrotasks()
    expect(editor.getText()).toContain('Hi world')
    editor.destroy()

    // SSE endpoint test
    const encoder = new TextEncoder()
    const sseFetch = vi.fn(async () => new Response(
      new ReadableStream({
        start(controller) {
          controller.enqueue(encoder.encode('event: assistant.delta\ndata: {"text":"Updated"}\n\n'))
          controller.enqueue(encoder.encode('event: run.finished\ndata: {"state":"completed"}\n\n'))
          controller.close()
        },
      }),
      { status: 200, headers: { 'Content-Type': 'text/event-stream' } },
    ))

    const sseEditor = new Editor({
      extensions: [
        StarterKit,
        VaulterAiCommands.configure({
          endpoint: 'https://host.example/ai/inline',
          fetchImpl: sseFetch as unknown as typeof fetch,
        }),
      ],
      content: '<p>Draft text</p>',
    })

    sseEditor.commands.setTextSelection({ from: 1, to: 6 })
    expect(sseEditor.commands.fixGrammar({ autoAccept: true })).toBe(true)
    await flushMicrotasks()
    expect(sseEditor.getText()).toContain('Updated text')
    sseEditor.destroy()

    // OfficeChatClient integration test
    const chatFetch = vi.fn(async (input: RequestInfo | URL) => {
      const url = String(input)
      if (url.endsWith('/chat/sessions')) {
        return new Response(JSON.stringify({
          data: {
            session_id: 's-1',
            state: 'active',
            profile_revision: '1',
            last_seq: 0,
            provider: { configured: true, connection: 'office', driver: 'openai', model: 'gpt', hint: null },
            runs: [],
            tool_calls: [],
            messages: [],
          },
        }), { status: 201 })
      }
      if (url.includes('/messages')) {
        return new Response(JSON.stringify({ data: { message_id: 'm-1', run_id: 'r-1' } }), { status: 202 })
      }
      return new Response(
        new ReadableStream({
          start(controller) {
            controller.enqueue(encoder.encode('id: 1\nevent: assistant.delta\ndata: {"run_id":"r-1","text":"ChatResult"}\n\n'))
            controller.enqueue(encoder.encode('id: 2\nevent: run.finished\ndata: {"run_id":"r-1","state":"completed"}\n\n'))
            controller.close()
          },
        }),
        { status: 200, headers: { 'Content-Type': 'text/event-stream' } },
      )
    })

    const chatClient = createOfficeWorkspaceClient({
      baseUrl: 'https://host.example',
      fetchImpl: chatFetch as unknown as typeof fetch,
    })

    const chatEditor = new Editor({
      extensions: [
        StarterKit,
        BlockIdAttr,
        VaulterAiCommands.configure({
          chatClient,
          resourceId: 'doc-1',
        }),
      ],
      content: {
        type: 'doc',
        content: [
          { type: 'paragraph', attrs: { blockId: 'p1' }, content: [{ type: 'text', text: 'Original' }] },
        ],
      },
    })

    chatEditor.commands.setTextSelection({ from: 1, to: 9 })
    expect(chatEditor.commands.shorten({ autoAccept: true })).toBe(true)
    await flushMicrotasks(10)
    expect(chatEditor.getText()).toContain('ChatResult')
    chatEditor.destroy()
  })

  it('enforces contentProtectionPolicy and strips DOM hooks & hidden nodes in getEditorContext()', () => {
    const policy = {
      rules: [
        { mode: 'hidden', attribute: 'level', value: 6 },
        { mode: 'read_only', node_type: 'blockquote' },
      ],
    }

    const resolver = vi.fn(async () => 'Should not run')
    const editor = new Editor({
      extensions: [
        StarterKit,
        BlockIdAttr,
        VaulterAiCommands.configure({
          contentProtectionPolicy: policy,
          completionResolver: resolver,
        }),
      ],
      content: {
        type: 'doc',
        content: [
          { type: 'heading', attrs: { blockId: 'h_secret', level: 6 }, content: [{ type: 'text', text: 'Top Secret' }] },
          {
            type: 'blockquote',
            content: [
              { type: 'paragraph', attrs: { blockId: 'p_ro' }, content: [{ type: 'text', text: 'Locked clause' }] },
            ],
          },
          { type: 'paragraph', attrs: { blockId: 'p_open' }, content: [{ type: 'text', text: 'Editable clause' }] },
        ],
      },
    })

    const ctx = getEditorContext(editor, { contentProtectionPolicy: policy })
    expect(ctx.version).toBe('vaulter.ai.editor-context/v1')
    expect(JSON.stringify(ctx.serializedSchema)).not.toContain('toDOM')
    expect(JSON.stringify(ctx.serializedSchema)).not.toContain('parseDOM')
    expect(JSON.stringify(ctx)).not.toContain('Top Secret')
    expect(ctx.items.map((i) => i.block_id)).toEqual(['p_ro', 'p_open'])
    expect(ctx.items[0]?.protection).toBe('read_only')

    // Command on read_only blockquote is blocked
    const roPos = editor.state.doc.textContent.indexOf('Locked')
    expect(roPos).toBeGreaterThan(-1)
    editor.commands.setTextSelection({ from: 16, to: 22 })
    expect(editor.commands.shorten()).toBe(false)
    expect(editor.storage.vaulterAiCommands.state).toBe('error')
    expect(resolver).not.toHaveBeenCalled()

    editor.destroy()
  })
})
