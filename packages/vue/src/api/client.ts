import type {
  ChatEditorSelection,
  ChatSessionDetails,
  ChatSseEvent,
  CreateChatSessionInput,
  DecideProposalInput,
  PostChatMessageInput,
  PostChatMessageResult,
  ProposalDecisionResult,
  ProposalDetails,
  ResumeRunOptions,
  RunDetails,
} from './types'

export interface WorkspaceWireErrorPayload {
  error?: {
    code?: string
    retryable?: boolean
    next_action?: string
  }
}

export class OfficeWorkspaceApiError extends Error {
  readonly code: string
  readonly status: number
  readonly retryable: boolean
  readonly nextAction: string

  constructor(message: string, status: number, code: string, retryable = false, nextAction = 'fail') {
    super(message)
    this.name = 'OfficeWorkspaceApiError'
    this.status = status
    this.code = code
    this.retryable = retryable
    this.nextAction = nextAction
  }
}

export interface OfficeWorkspaceClientOptions {
  baseUrl?: string
  workspacePrefix?: string
  headers?: () => Record<string, string> | Promise<Record<string, string>>
  credentials?: RequestCredentials
  fetchImpl?: typeof fetch
}

export interface ListProposalsOptions {
  resourceId?: string
  decision?: string
}

export interface ConnectChatEventsOptions {
  cursor?: number
  maxReconnectAttempts?: number
  baseReconnectDelayMs?: number
  maxReconnectDelayMs?: number
  onEvent: (event: ChatSseEvent) => void
  onReset?: () => void | Promise<void>
  onError?: (error: OfficeWorkspaceApiError) => void
  onOpen?: () => void
}

export interface ChatEventSubscription {
  close(): void
  readonly closed: boolean
  readonly lastSeq: number
}

export interface OfficeWorkspaceClient {
  listProposals(options?: ListProposalsOptions): Promise<ProposalDetails[]>
  getProposal(proposalId: string): Promise<ProposalDetails>
  decideProposal(proposalId: string, input: DecideProposalInput): Promise<ProposalDecisionResult>
  getRun(runId: string): Promise<RunDetails>
  resumeRun(runId: string, options?: ResumeRunOptions): Promise<RunDetails>
}

export interface OfficeChatClient {
  createChatSession(input?: CreateChatSessionInput): Promise<ChatSessionDetails>
  getChatSession(sessionId: string): Promise<ChatSessionDetails>
  postChatMessage(sessionId: string, input: PostChatMessageInput): Promise<PostChatMessageResult>
  connectChatEvents(sessionId: string, options: ConnectChatEventsOptions): ChatEventSubscription
}

export type OfficeWorkspaceChatClient = OfficeWorkspaceClient & OfficeChatClient

const MAX_SELECTION_EXCERPT_BYTES = 2048
const MAX_SELECTION_ID_BYTES = 255
const MAX_SELECTION_ITEMS = 20

function truncateUtf8Bytes(value: string, maxBytes: number): string {
  const encoder = new TextEncoder()
  if (encoder.encode(value).byteLength <= maxBytes) {
    return value
  }
  let low = 0
  let high = value.length
  while (low < high) {
    const mid = Math.ceil((low + high) / 2)
    if (encoder.encode(value.slice(0, mid)).byteLength <= maxBytes) {
      low = mid
    } else {
      high = mid - 1
    }
  }
  return value.slice(0, low)
}

export function normalizeChatSelection(selection?: ChatEditorSelection | null): ChatEditorSelection | null {
  if (!selection) {
    return null
  }
  if (selection.kind === 'text_block') {
    const out: ChatEditorSelection = {
      kind: 'text_block',
      resource_id: truncateUtf8Bytes(selection.resource_id, MAX_SELECTION_ID_BYTES),
      block_id: truncateUtf8Bytes(selection.block_id, MAX_SELECTION_ID_BYTES),
    }
    if (typeof selection.excerpt === 'string' && selection.excerpt.length > 0) {
      out.excerpt = truncateUtf8Bytes(selection.excerpt, MAX_SELECTION_EXCERPT_BYTES)
    }
    if (typeof selection.start_utf16 === 'number' && typeof selection.end_utf16 === 'number'
      && Number.isInteger(selection.start_utf16) && Number.isInteger(selection.end_utf16)
      && selection.start_utf16 >= 0 && selection.end_utf16 >= selection.start_utf16) {
      out.start_utf16 = selection.start_utf16
      out.end_utf16 = Math.min(selection.end_utf16, selection.start_utf16 + MAX_SELECTION_EXCERPT_BYTES)
    }
    return out
  }

  const out: ChatEditorSelection = {
    kind: 'sheet_range',
    resource_id: truncateUtf8Bytes(selection.resource_id, MAX_SELECTION_ID_BYTES),
    sheet_id: truncateUtf8Bytes(selection.sheet_id, MAX_SELECTION_ID_BYTES),
    table_id: truncateUtf8Bytes(selection.table_id, MAX_SELECTION_ID_BYTES),
  }
  if (Array.isArray(selection.row_ids)) {
    out.row_ids = selection.row_ids.slice(0, MAX_SELECTION_ITEMS).map((id) => truncateUtf8Bytes(id, MAX_SELECTION_ID_BYTES))
  }
  if (Array.isArray(selection.columns)) {
    out.columns = selection.columns.slice(0, MAX_SELECTION_ITEMS).map((col) => truncateUtf8Bytes(col, MAX_SELECTION_ID_BYTES))
  }
  return out
}

function parseSseBlock(block: string): ChatSseEvent | null {
  const lines = block.split('\n')
  let eventType = 'message'
  let idRaw: string | null = null
  const dataLines: string[] = []

  for (const rawLine of lines) {
    const line = rawLine.endsWith('\r') ? rawLine.slice(0, -1) : rawLine
    if (line === '' || line.startsWith(':')) {
      continue
    }
    const colon = line.indexOf(':')
    const field = colon === -1 ? line : line.slice(0, colon)
    let value = colon === -1 ? '' : line.slice(colon + 1)
    if (value.startsWith(' ')) {
      value = value.slice(1)
    }
    if (field === 'event') {
      eventType = value || 'message'
    } else if (field === 'data') {
      dataLines.push(value)
    } else if (field === 'id') {
      idRaw = value
    }
  }

  if (dataLines.length === 0 && eventType === 'message' && idRaw === null) {
    return null
  }

  const seq = idRaw !== null && /^(?:0|[1-9][0-9]*)$/.test(idRaw) ? Number.parseInt(idRaw, 10) : null
  const rawData = dataLines.join('\n')
  let parsedData: Record<string, unknown> = {}
  if (rawData !== '') {
    try {
      const candidate = JSON.parse(rawData) as unknown
      if (typeof candidate === 'object' && candidate !== null && !Array.isArray(candidate)) {
        parsedData = candidate as Record<string, unknown>
      }
    } catch {
      parsedData = {}
    }
  }

  return {
    seq,
    type: eventType,
    data: parsedData,
  }
}

function normalizeSegment(value: string, fallback: string): string {
  const trimmed = value.replace(/^\/+|\/+$/g, '')
  return trimmed === '' ? fallback : trimmed
}

export function createOfficeWorkspaceClient(options: OfficeWorkspaceClientOptions = {}): OfficeWorkspaceChatClient {
  const baseUrl = (options.baseUrl ?? '').replace(/\/+$/, '')
  const prefix = normalizeSegment(options.workspacePrefix ?? 'office', 'office')
  const rootPath = `${baseUrl}/${prefix}`
  const credentials = options.credentials ?? 'same-origin'
  const fetchFn = options.fetchImpl ?? globalThis.fetch

  async function requestJson<T>(path: string, init: RequestInit = {}): Promise<T> {
    if (typeof fetchFn !== 'function') {
      throw new OfficeWorkspaceApiError('Fetch API is unavailable in this environment.', 0, 'temporarily_unavailable', true, 'retry')
    }

    const customHeaders = options.headers ? await options.headers() : {}
    const headers: Record<string, string> = {
      Accept: 'application/json',
      ...customHeaders,
    }
    if (init.body !== undefined) {
      headers['Content-Type'] = 'application/json'
    }

    const response = await fetchFn(`${rootPath}${path}`, {
      ...init,
      credentials,
      headers: {
        ...headers,
        ...(init.headers as Record<string, string> | undefined),
      },
    })

    const rawText = await response.text()
    let parsed: unknown = null
    if (rawText !== '') {
      try {
        parsed = JSON.parse(rawText) as unknown
      } catch {
        parsed = null
      }
    }

    if (!response.ok) {
      const payload = (parsed ?? {}) as WorkspaceWireErrorPayload
      const err = payload.error
      const code = typeof err?.code === 'string' && err.code !== '' ? err.code : `http_${response.status}`
      const retryable = typeof err?.retryable === 'boolean' ? err.retryable : response.status >= 500
      const nextAction = typeof err?.next_action === 'string' ? err.next_action : 'fail'
      throw new OfficeWorkspaceApiError(`Workspace request failed (${code}).`, response.status, code, retryable, nextAction)
    }

    if (typeof parsed !== 'object' || parsed === null || !('data' in parsed)) {
      throw new OfficeWorkspaceApiError('Malformed workspace JSON envelope.', response.status, 'temporarily_unavailable', false, 'fail')
    }

    return (parsed as { data: T }).data
  }

  return {
    async listProposals(params?: ListProposalsOptions): Promise<ProposalDetails[]> {
      const search = new URLSearchParams()
      if (params?.resourceId) {
        search.set('resource_id', params.resourceId)
      }
      if (params?.decision) {
        search.set('decision', params.decision)
      }
      const suffix = search.toString()
      return requestJson<ProposalDetails[]>(suffix === '' ? '/proposals' : `/proposals?${suffix}`, { method: 'GET' })
    },

    async getProposal(proposalId: string): Promise<ProposalDetails> {
      return requestJson<ProposalDetails>(`/proposals/${encodeURIComponent(proposalId)}`, { method: 'GET' })
    },

    async decideProposal(proposalId: string, input: DecideProposalInput): Promise<ProposalDecisionResult> {
      return requestJson<ProposalDecisionResult>(`/proposals/${encodeURIComponent(proposalId)}/decision`, {
        method: 'POST',
        body: JSON.stringify({
          digest: input.digest,
          approved: input.approved,
          ...(input.commandId ? { command_id: input.commandId } : {}),
        }),
      })
    },

    async getRun(runId: string): Promise<RunDetails> {
      return requestJson<RunDetails>(`/runs/${encodeURIComponent(runId)}`, { method: 'GET' })
    },

    async resumeRun(runId: string, resumeOptions: ResumeRunOptions = {}): Promise<RunDetails> {
      return requestJson<RunDetails>(`/runs/${encodeURIComponent(runId)}/resume`, {
        method: 'POST',
        body: JSON.stringify({
          ...(resumeOptions.argumentsByResource ? { arguments_by_resource: resumeOptions.argumentsByResource } : {}),
          ...(resumeOptions.rereadOnly !== undefined ? { reread_only: resumeOptions.rereadOnly } : {}),
        }),
      })
    },

    async createChatSession(input: CreateChatSessionInput = {}): Promise<ChatSessionDetails> {
      return requestJson<ChatSessionDetails>('/chat/sessions', {
        method: 'POST',
        body: JSON.stringify({
          ...(input.retentionSeconds !== undefined ? { retention_seconds: input.retentionSeconds } : {}),
        }),
      })
    },

    async getChatSession(sessionId: string): Promise<ChatSessionDetails> {
      return requestJson<ChatSessionDetails>(`/chat/sessions/${encodeURIComponent(sessionId)}`, { method: 'GET' })
    },

    async postChatMessage(sessionId: string, input: PostChatMessageInput): Promise<PostChatMessageResult> {
      const normalizedSelection = normalizeChatSelection(input.selection)
      return requestJson<PostChatMessageResult>(`/chat/sessions/${encodeURIComponent(sessionId)}/messages`, {
        method: 'POST',
        body: JSON.stringify({
          message_id: input.messageId,
          content: input.content,
          resource_ids: input.resourceIds ?? [],
          ...(normalizedSelection !== null ? { selection: normalizedSelection } : {}),
        }),
      })
    },

    connectChatEvents(sessionId: string, eventOptions: ConnectChatEventsOptions): ChatEventSubscription {
      let currentSeq = Math.max(0, Math.trunc(eventOptions.cursor ?? 0))
      let isClosed = false
      let attempt = 0
      let activeAbort: AbortController | null = null
      let retryTimer: ReturnType<typeof setTimeout> | null = null

      const maxAttempts = eventOptions.maxReconnectAttempts ?? Number.POSITIVE_INFINITY
      const baseDelay = eventOptions.baseReconnectDelayMs ?? 150
      const maxDelay = eventOptions.maxReconnectDelayMs ?? 4000

      const waitReconnect = (delayMs: number): Promise<boolean> => new Promise((resolve) => {
        if (isClosed) {
          resolve(false)
          return
        }
        retryTimer = setTimeout(() => {
          retryTimer = null
          resolve(!isClosed)
        }, delayMs)
      })

      const parseError = async (response: Response): Promise<OfficeWorkspaceApiError> => {
        const rawText = await response.text()
        let parsed: unknown = null
        if (rawText !== '') {
          try {
            parsed = JSON.parse(rawText) as unknown
          } catch {
            parsed = null
          }
        }
        const payload = (parsed ?? {}) as WorkspaceWireErrorPayload
        const err = payload.error
        const code = typeof err?.code === 'string' && err.code !== '' ? err.code : `http_${response.status}`
        const retryable = typeof err?.retryable === 'boolean' ? err.retryable : response.status >= 500
        const nextAction = typeof err?.next_action === 'string' ? err.next_action : 'fail'
        return new OfficeWorkspaceApiError(`Chat SSE request failed (${code}).`, response.status, code, retryable, nextAction)
      }

      const pump = async (): Promise<void> => {
        if (typeof fetchFn !== 'function') {
          eventOptions.onError?.(new OfficeWorkspaceApiError('Fetch API is unavailable in this environment.', 0, 'temporarily_unavailable', true, 'retry'))
          isClosed = true
          return
        }

        while (!isClosed) {
          activeAbort = new AbortController()
          try {
            const customHeaders = options.headers ? await options.headers() : {}
            const headers: Record<string, string> = {
              Accept: 'text/event-stream',
              'Last-Event-ID': String(currentSeq),
              ...customHeaders,
            }

            const streamUrl = `${rootPath}/chat/sessions/${encodeURIComponent(sessionId)}/events?cursor=${currentSeq}`
            const response = await fetchFn(streamUrl, {
              method: 'GET',
              credentials,
              headers,
              signal: activeAbort.signal,
            })

            if (isClosed) {
              return
            }

            if (!response.ok) {
              const apiError = await parseError(response)
              if (response.status === 410 || apiError.code === 'cursor_expired') {
                eventOptions.onEvent({ seq: null, type: 'reset', data: {} })
                await eventOptions.onReset?.()
                isClosed = true
                return
              }
              if (!apiError.retryable) {
                eventOptions.onError?.(apiError)
                isClosed = true
                return
              }
              throw apiError
            }

            attempt = 0
            eventOptions.onOpen?.()

            let resetTriggered = false
            const processBuffer = async (input: string, flushAll: boolean): Promise<string> => {
              let normalized = input.replace(/\r\n/g, '\n')
              while (true) {
                const boundary = normalized.indexOf('\n\n')
                if (boundary === -1) {
                  break
                }
                const block = normalized.slice(0, boundary)
                normalized = normalized.slice(boundary + 2)
                const parsedEvent = parseSseBlock(block)
                if (!parsedEvent) {
                  continue
                }
                if (parsedEvent.seq !== null && parsedEvent.seq > currentSeq) {
                  currentSeq = parsedEvent.seq
                }
                eventOptions.onEvent(parsedEvent)
                if (parsedEvent.type === 'reset') {
                  resetTriggered = true
                  await eventOptions.onReset?.()
                  isClosed = true
                  return ''
                }
              }

              if (flushAll && normalized.trim() !== '') {
                const parsedEvent = parseSseBlock(normalized)
                if (parsedEvent) {
                  if (parsedEvent.seq !== null && parsedEvent.seq > currentSeq) {
                    currentSeq = parsedEvent.seq
                  }
                  eventOptions.onEvent(parsedEvent)
                  if (parsedEvent.type === 'reset') {
                    resetTriggered = true
                    await eventOptions.onReset?.()
                    isClosed = true
                  }
                }
                return ''
              }
              return normalized
            }

            if (response.body && typeof response.body.getReader === 'function') {
              const reader = response.body.getReader()
              const decoder = new TextDecoder()
              let buffer = ''
              while (!isClosed) {
                const { done, value } = await reader.read()
                if (done) {
                  buffer += decoder.decode()
                  await processBuffer(buffer, true)
                  break
                }
                buffer += decoder.decode(value, { stream: true })
                buffer = await processBuffer(buffer, false)
                if (resetTriggered || isClosed) {
                  await reader.cancel().catch(() => undefined)
                  break
                }
              }
            } else {
              const bodyText = await response.text()
              await processBuffer(bodyText, true)
            }

            if (resetTriggered || isClosed) {
              return
            }

            if (attempt >= maxAttempts) {
              isClosed = true
              return
            }
            const delayMs = Math.min(maxDelay, baseDelay * (2 ** attempt))
            attempt += 1
            const shouldContinue = await waitReconnect(delayMs)
            if (!shouldContinue) {
              return
            }
          } catch (err) {
            if (isClosed) {
              return
            }
            if (err instanceof DOMException && err.name === 'AbortError') {
              return
            }
            const apiError = err instanceof OfficeWorkspaceApiError
              ? err
              : new OfficeWorkspaceApiError(
                err instanceof Error ? err.message : 'Chat SSE connection interrupted.',
                0,
                'temporarily_unavailable',
                true,
                'retry',
              )
            eventOptions.onError?.(apiError)
            if (attempt >= maxAttempts) {
              isClosed = true
              return
            }
            const delayMs = Math.min(maxDelay, baseDelay * (2 ** attempt))
            attempt += 1
            const shouldContinue = await waitReconnect(delayMs)
            if (!shouldContinue) {
              return
            }
          } finally {
            activeAbort = null
          }
        }
      }

      void pump()

      return {
        close(): void {
          if (isClosed) {
            return
          }
          isClosed = true
          if (retryTimer !== null) {
            clearTimeout(retryTimer)
            retryTimer = null
          }
          activeAbort?.abort()
        },
        get closed(): boolean {
          return isClosed
        },
        get lastSeq(): number {
          return currentSeq
        },
      }
    },
  }
}
