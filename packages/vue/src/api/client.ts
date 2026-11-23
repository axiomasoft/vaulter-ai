import type {
  DecideProposalInput,
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

export interface OfficeWorkspaceClient {
  listProposals(options?: ListProposalsOptions): Promise<ProposalDetails[]>
  getProposal(proposalId: string): Promise<ProposalDetails>
  decideProposal(proposalId: string, input: DecideProposalInput): Promise<ProposalDecisionResult>
  getRun(runId: string): Promise<RunDetails>
  resumeRun(runId: string, options?: ResumeRunOptions): Promise<RunDetails>
}

function normalizeSegment(value: string, fallback: string): string {
  const trimmed = value.replace(/^\/+|\/+$/g, '')
  return trimmed === '' ? fallback : trimmed
}

export function createOfficeWorkspaceClient(options: OfficeWorkspaceClientOptions = {}): OfficeWorkspaceClient {
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
  }
}
