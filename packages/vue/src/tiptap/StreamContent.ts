import type { Editor } from '@tiptap/core'
import { DOMParser as PMDOMParser, Fragment, Slice, type Mark } from '@tiptap/pm/model'
import type { Transaction } from '@tiptap/pm/state'
import { Mapping } from '@tiptap/pm/transform'
import type { VaulterAiFormat, VaulterAiStreamResult } from './types'

export const STREAM_CONTENT_META = 'vaulterAiStreamContent'

const BLOCKED_TAGS = new Set([
  'SCRIPT',
  'STYLE',
  'IFRAME',
  'OBJECT',
  'EMBED',
  'APPLET',
  'FRAME',
  'FRAMESET',
  'META',
  'LINK',
  'BASE',
  'NOSCRIPT',
  'TEMPLATE',
  'SVG',
  'MATH',
  'FORM',
  'INPUT',
  'TEXTAREA',
  'SELECT',
  'BUTTON',
])

const URL_ATTRS = new Set(['href', 'src', 'action', 'cite', 'poster', 'data'])
const DANGEROUS_ATTRS = new Set(['srcdoc', 'formaction', 'xlink:href'])

function isDangerousUrl(rawValue: string): boolean {
  const normalized = rawValue.replace(/[\u0000-\u0020\u007F]+/g, '').toLowerCase()
  return (
    normalized.startsWith('javascript:')
    || normalized.startsWith('vbscript:')
    || normalized.startsWith('data:')
  )
}

function sanitizeStyleValue(rawStyle: string): string {
  const lower = rawStyle.toLowerCase()
  if (
    lower.includes('expression(')
    || lower.includes('javascript:')
    || lower.includes('vbscript:')
    || lower.includes('-moz-binding')
    || lower.includes('behavior:')
    || lower.includes('url(')
  ) {
    return ''
  }
  return rawStyle
}

function sanitizeElementTree(root: Element): void {
  const children = Array.from(root.children)
  for (const child of children) {
    if (BLOCKED_TAGS.has(child.tagName.toUpperCase())) {
      child.remove()
      continue
    }

    const attrs = Array.from(child.attributes)
    for (const attr of attrs) {
      const name = attr.name.toLowerCase()
      if (name.startsWith('on') || DANGEROUS_ATTRS.has(name)) {
        child.removeAttribute(attr.name)
        continue
      }
      if (URL_ATTRS.has(name) && isDangerousUrl(attr.value)) {
        child.removeAttribute(attr.name)
        continue
      }
      if (name === 'style') {
        const safeStyle = sanitizeStyleValue(attr.value)
        if (safeStyle === '') {
          child.removeAttribute(attr.name)
        } else if (safeStyle !== attr.value) {
          child.setAttribute(attr.name, safeStyle)
        }
      }
    }

    sanitizeElementTree(child)
  }
}

export function sanitizeHtmlFragment(html: string): HTMLElement {
  const doc = new DOMParser().parseFromString(html, 'text/html')
  sanitizeElementTree(doc.body)
  return doc.body
}

function escapeTrailingLessThan(text: string): string {
  return text.replace(/</g, '&lt;')
}

/**
 * Splits `accumulated` into a safe `renderable` HTML prefix and a `pending`
 * trailing incomplete `<...` tag (if any) so partial tags like `<sp` or `<`
 * are never fed prematurely to the HTML parser.
 */
function splitIncompleteTagTail(accumulated: string): { renderable: string; pending: string } {
  let i = 0
  const len = accumulated.length

  while (i < len) {
    const lt = accumulated.indexOf('<', i)
    if (lt === -1) {
      return { renderable: accumulated, pending: '' }
    }

    if (lt === len - 1) {
      return { renderable: accumulated.slice(0, lt), pending: accumulated.slice(lt) }
    }

    const nextChar = accumulated[lt + 1]
    const isTagLike = /[a-zA-Z/?!]/.test(nextChar)
    if (!isTagLike) {
      i = lt + 1
      continue
    }

    let j = lt + 2
    let quote: '"' | "'" | null = null
    let closed = false

    while (j < len) {
      const ch = accumulated[j]
      if (quote !== null) {
        if (ch === quote) {
          quote = null
        }
      } else if (ch === '"' || ch === "'") {
        quote = ch
      } else if (ch === '>') {
        closed = true
        break
      }
      j += 1
    }

    if (!closed) {
      return { renderable: accumulated.slice(0, lt), pending: accumulated.slice(lt) }
    }

    i = j + 1
  }

  return { renderable: accumulated, pending: '' }
}

export class HtmlStreamBuffer {
  private readonly decoder = new TextDecoder('utf-8', { fatal: false })
  private raw = ''
  private renderable = ''
  private pending = ''
  private finished = false

  push(chunk: string | Uint8Array): string {
    if (this.finished) {
      return this.renderable
    }
    const text = typeof chunk === 'string'
      ? chunk
      : this.decoder.decode(chunk, { stream: true })
    if (text === '') {
      return this.renderable
    }
    this.raw += text
    const split = splitIncompleteTagTail(this.raw)
    this.renderable = split.renderable
    this.pending = split.pending
    return this.renderable
  }

  finish(): string {
    if (this.finished) {
      return this.renderable
    }
    this.finished = true
    const tail = this.decoder.decode()
    if (tail !== '') {
      this.raw += tail
    }
    const split = splitIncompleteTagTail(this.raw)
    this.renderable = split.pending !== ''
      ? split.renderable + escapeTrailingLessThan(split.pending)
      : split.renderable
    this.pending = ''
    return this.renderable
  }

  getRenderable(): string {
    return this.renderable
  }

  getPending(): string {
    return this.pending
  }

  getRaw(): string {
    return this.raw
  }
}

export interface StreamContentOptions {
  from: number
  to: number
  format?: VaulterAiFormat
  marks?: readonly Mark[]
  addToHistory?: boolean
  extraMeta?: Record<string, unknown>
}

export class StreamContent {
  private static nextId = 1

  private readonly editor: Editor
  private readonly streamId: string
  private readonly format: VaulterAiFormat
  private readonly marks: readonly Mark[]
  private readonly addToHistory: boolean
  private readonly extraMeta: Record<string, unknown>
  private readonly buffer = new HtmlStreamBuffer()

  private anchorFrom: number
  private anchorTo: number
  private pendingMapping = new Mapping()
  private lastRendered = ''
  private hasWritten = false
  private destroyed = false

  private readonly onTransaction = ({ transaction }: { transaction: Transaction }): void => {
    if (this.destroyed) {
      return
    }
    if (transaction.getMeta(STREAM_CONTENT_META) === this.streamId) {
      return
    }
    if (transaction.docChanged) {
      this.pendingMapping.appendMapping(transaction.mapping)
    }
  }

  constructor(editor: Editor, options: StreamContentOptions) {
    this.editor = editor
    this.streamId = `stream-${StreamContent.nextId++}`
    this.format = options.format ?? 'rich-text'
    this.marks = options.marks ?? []
    this.addToHistory = options.addToHistory ?? false
    this.extraMeta = options.extraMeta ?? {}
    this.anchorFrom = Math.max(0, Math.min(editor.state.doc.content.size, options.from))
    this.anchorTo = Math.max(this.anchorFrom, Math.min(editor.state.doc.content.size, options.to))

    this.editor.on('transaction', this.onTransaction)
  }

  getMappedRange(): { from: number; to: number } {
    const docSize = this.editor.state.doc.content.size
    const mappedFrom = this.pendingMapping.map(this.anchorFrom, 1)
    const mappedTo = this.anchorFrom === this.anchorTo
      ? mappedFrom
      : this.pendingMapping.map(this.anchorTo, -1)

    const from = Math.max(0, Math.min(docSize, mappedFrom))
    const to = Math.max(from, Math.min(docSize, mappedTo))
    return { from, to }
  }

  getRawText(): string {
    return this.buffer.getRaw()
  }

  push(chunk: string | Uint8Array): boolean {
    if (this.destroyed || this.editor.isDestroyed) {
      return false
    }
    if (this.format === 'plain-text') {
      const decoded = typeof chunk === 'string'
        ? chunk
        : new TextDecoder('utf-8', { fatal: false }).decode(chunk, { stream: true })
      const prev = this.buffer.getRaw()
      this.buffer.push(decoded)
      const next = this.buffer.getRaw()
      if (next === prev && this.hasWritten) {
        return false
      }
      return this.applyContent(next)
    }

    const renderable = this.buffer.push(chunk)
    if (renderable === this.lastRendered) {
      return false
    }
    if (renderable === '' && !this.hasWritten) {
      return false
    }
    return this.applyContent(renderable)
  }

  finish(): { from: number; to: number } {
    if (this.destroyed || this.editor.isDestroyed) {
      return this.getMappedRange()
    }
    if (this.format === 'rich-text') {
      const finalHtml = this.buffer.finish()
      if (finalHtml !== this.lastRendered && (finalHtml !== '' || this.hasWritten)) {
        this.applyContent(finalHtml)
      }
    }
    return this.getMappedRange()
  }

  destroy(): void {
    if (this.destroyed) {
      return
    }
    this.destroyed = true
    this.editor.off('transaction', this.onTransaction)
  }

  private buildSlice(content: string): Slice | null {
    const schema = this.editor.schema
    if (this.format === 'plain-text') {
      if (content === '') {
        return Slice.empty
      }
      const lines = content.split(/\r?\n/)
      if (lines.length === 1) {
        const textNode = schema.text(lines[0], this.marks.length > 0 ? [...this.marks] : undefined)
        return new Slice(Fragment.from(textNode), 0, 0)
      }
      const paragraphType = schema.nodes.paragraph
      if (!paragraphType) {
        return new Slice(Fragment.from(schema.text(content)), 0, 0)
      }
      const blocks = lines.map((line) =>
        line === ''
          ? paragraphType.create()
          : paragraphType.create(null, schema.text(line, this.marks.length > 0 ? [...this.marks] : undefined)),
      )
      return new Slice(Fragment.fromArray(blocks), 1, 1)
    }

    const sanitized = sanitizeHtmlFragment(content)
    const parsed = PMDOMParser.fromSchema(schema).parseSlice(sanitized, { preserveWhitespace: true })
    if (parsed.size === 0 && content.trim() !== '') {
      return null
    }
    return parsed
  }

  private applyContent(content: string): boolean {
    const slice = this.buildSlice(content)
    if (!slice) {
      return false
    }

    const { from, to } = this.getMappedRange()
    const tr = this.editor.state.tr
    tr.setMeta(STREAM_CONTENT_META, this.streamId)
    tr.setMeta('addToHistory', this.addToHistory)
    for (const [key, val] of Object.entries(this.extraMeta)) {
      tr.setMeta(key, val)
    }

    tr.replace(from, to, slice)
    this.editor.view.dispatch(tr)

    this.anchorFrom = tr.mapping.map(from, -1)
    this.anchorTo = Math.max(this.anchorFrom, tr.mapping.map(to, 1))
    this.pendingMapping = new Mapping()
    this.lastRendered = content
    this.hasWritten = true
    return true
  }
}

function isReadableStream(value: unknown): value is ReadableStream<Uint8Array | string> {
  return typeof value === 'object' && value !== null && typeof (value as ReadableStream).getReader === 'function'
}

function isAsyncIterable(value: unknown): value is AsyncIterable<string | Uint8Array> {
  return typeof value === 'object' && value !== null && Symbol.asyncIterator in value
}

export async function consumeTextOrSseStream(
  source: VaulterAiStreamResult,
  options: {
    signal?: AbortSignal
    isSse?: boolean
    onChunk: (chunk: string) => void
  },
): Promise<void> {
  if (typeof source === 'string') {
    if (!options.signal?.aborted && source !== '') {
      options.onChunk(source)
    }
    return
  }

  const decoder = new TextDecoder('utf-8', { fatal: false })
  let sseBuffer = ''

  const processSse = (flush: boolean): void => {
    let normalized = sseBuffer.replace(/\r\n/g, '\n')
    while (true) {
      const boundary = normalized.indexOf('\n\n')
      if (boundary === -1) {
        break
      }
      const block = normalized.slice(0, boundary)
      normalized = normalized.slice(boundary + 2)
      emitSseBlock(block)
    }
    if (flush && normalized.trim() !== '') {
      emitSseBlock(normalized)
      normalized = ''
    }
    sseBuffer = normalized
  }

  const emitSseBlock = (block: string): void => {
    let eventName = 'message'
    const dataLines: string[] = []
    for (const rawLine of block.split('\n')) {
      const line = rawLine.endsWith('\r') ? rawLine.slice(0, -1) : rawLine
      if (line === '' || line.startsWith(':')) continue
      const colon = line.indexOf(':')
      const field = colon === -1 ? line : line.slice(0, colon)
      let val = colon === -1 ? '' : line.slice(colon + 1)
      if (val.startsWith(' ')) val = val.slice(1)
      if (field === 'event') eventName = val || 'message'
      else if (field === 'data') dataLines.push(val)
    }
    if (dataLines.length === 0) return
    const rawData = dataLines.join('\n')
    if (rawData === '[DONE]') return
    try {
      const parsed = JSON.parse(rawData) as Record<string, unknown>
      if (eventName === 'error') {
        const msg = typeof parsed.message === 'string' ? parsed.message : 'AI stream error.'
        throw new Error(msg)
      }
      const delta = parsed.text ?? parsed.html ?? parsed.delta ?? parsed.chunk ?? parsed.response
      if (typeof delta === 'string' && delta !== '') {
        options.onChunk(delta)
      }
    } catch (err) {
      if (err instanceof Error && eventName === 'error') {
        throw err
      }
      options.onChunk(rawData)
    }
  }

  const pushDecoded = (rawChunk: string | Uint8Array): void => {
    const text = typeof rawChunk === 'string'
      ? rawChunk
      : decoder.decode(rawChunk, { stream: true })
    if (text === '') return
    if (options.isSse) {
      sseBuffer += text
      processSse(false)
    } else {
      options.onChunk(text)
    }
  }

  if (isReadableStream(source)) {
    const reader = source.getReader()
    try {
      while (!options.signal?.aborted) {
        const { done, value } = await reader.read()
        if (done) {
          const tail = decoder.decode()
          if (options.isSse) {
            sseBuffer += tail
            processSse(true)
          } else if (tail !== '') {
            options.onChunk(tail)
          }
          break
        }
        if (value !== undefined) {
          pushDecoded(value)
        }
      }
    } finally {
      if (options.signal?.aborted) {
        await reader.cancel().catch(() => undefined)
      }
    }
    return
  }

  if (isAsyncIterable(source)) {
    for await (const chunk of source) {
      if (options.signal?.aborted) break
      pushDecoded(chunk)
    }
    if (!options.signal?.aborted) {
      const tail = decoder.decode()
      if (options.isSse) {
        sseBuffer += tail
        processSse(true)
      } else if (tail !== '') {
        options.onChunk(tail)
      }
    }
  }
}
