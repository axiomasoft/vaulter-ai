import { describe, expect, it } from 'vitest'
import { Editor } from '@tiptap/core'
import StarterKit from '@tiptap/starter-kit'
import * as Y from 'yjs'
import { prosemirrorToYXmlFragment, ySyncPlugin } from '@tiptap/y-tiptap'
import { HtmlStreamBuffer, sanitizeHtmlFragment, StreamContent } from '../src'

describe('StreamContent & HtmlStreamBuffer', () => {
  it('buffers incomplete <span and < tags across chunks without corrupting output', () => {
    const editor = new Editor({
      extensions: [StarterKit],
      content: '<p>Payment in 15 days.</p>',
    })

    const stream = new StreamContent(editor, {
      from: 12,
      to: 14,
      format: 'rich-text',
    })

    // Chunk 1 ends with incomplete "<span"
    const wrote1 = stream.push('30 <sp')
    expect(wrote1).toBe(true)
    expect(editor.getText()).toContain('Payment in 30  days.')
    expect(editor.getText()).not.toContain('<sp')

    // Chunk 2 completes the tag and adds bold
    stream.push('an class="note"><strong>net</strong></span>')
    stream.finish()

    expect(editor.getText()).toContain('Payment in 30 net days.')
    const json = JSON.stringify(editor.getJSON())
    expect(json).toContain('"type":"bold"')

    stream.destroy()
    editor.destroy()
  })

  it('flushes trailing literal < on finish() and decodes multibyte UTF-8 split across chunks', () => {
    const buffer = new HtmlStreamBuffer()
    const bytes = new TextEncoder().encode('30 ✅')
    for (let i = 0; i < bytes.length; i += 2) {
      buffer.push(bytes.slice(i, i + 2))
    }
    expect(buffer.finish()).toBe('30 ✅')

    const literalBuf = new HtmlStreamBuffer()
    expect(literalBuf.push('value <')).toBe('value ')
    expect(literalBuf.getPending()).toBe('<')
    expect(literalBuf.finish()).toBe('value &lt;')
  })

  it('strips XSS tags, event handlers, javascript: URLs, and dangerous styles', () => {
    const sanitized = sanitizeHtmlFragment(
      '<p onclick="alert(1)">Safe</p>'
      + '<script>alert("xss")</script>'
      + '<iframe src="javascript:alert(1)"></iframe>'
      + '<svg onload="alert(1)"></svg>'
      + '<img src="x" onerror="alert(1)" />'
      + '<a href="  JaVaScRiPt:alert(1)">badlink</a>'
      + '<span style="background:expression(alert(1))">styled</span>',
    )

    const html = sanitized.innerHTML.toLowerCase()
    expect(html).not.toContain('<script')
    expect(html).not.toContain('alert(')
    expect(html).not.toContain('<iframe')
    expect(html).not.toContain('<svg')
    expect(html).not.toContain('onerror')
    expect(html).not.toContain('onclick')
    expect(html).not.toContain('javascript:')
    expect(html).not.toContain('expression(')
    expect(sanitized.textContent).toContain('Safe')
    expect(sanitized.textContent).toContain('badlink')
    expect(sanitized.textContent).toContain('styled')
  })

  it('maps insertion range through concurrent local edits and remote Yjs edits', () => {
    const ydoc = new Y.Doc()
    const fragment = ydoc.getXmlFragment('default')

    const seed = new Editor({
      extensions: [StarterKit],
      content: '<p>Payment in 15 days.</p><p>Independent clause.</p>',
    })
    prosemirrorToYXmlFragment(seed.state.doc, fragment)
    seed.destroy()

    const editor = new Editor({ extensions: [StarterKit] })
    editor.registerPlugin(ySyncPlugin(fragment))

    const stream = new StreamContent(editor, {
      from: 12,
      to: 14,
      format: 'rich-text',
    })

    stream.push('3')
    expect(editor.getText()).toContain('Payment in 3 days.')

    // Concurrent local edit BEFORE the streaming range
    editor.commands.insertContentAt(1, 'PRE ')
    // Concurrent remote Yjs edit in second paragraph
    const remoteDoc = new Y.Doc()
    Y.applyUpdate(remoteDoc, Y.encodeStateAsUpdate(ydoc))
    const remoteFrag = remoteDoc.getXmlFragment('default')
    const secondP = remoteFrag.get(1) as Y.XmlElement
    const secondText = secondP.get(0) as Y.XmlText
    secondText.insert(secondText.length, ' REMOTE')
    Y.applyUpdate(ydoc, Y.encodeStateAsUpdate(remoteDoc))

    // Finish stream
    stream.push('0')
    const finalRange = stream.finish()

    expect(editor.getText()).toContain('PRE Payment in 30 days.')
    expect(editor.getText()).toContain('Independent clause. REMOTE')
    expect(finalRange.to).toBeGreaterThan(finalRange.from)

    stream.destroy()
    editor.destroy()
  })
})
