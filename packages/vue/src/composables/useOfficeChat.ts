import { computed, getCurrentInstance, onBeforeUnmount, ref, shallowRef } from 'vue'
import {
  normalizeChatSelection,
  OfficeWorkspaceApiError,
  type ChatEventSubscription,
  type OfficeChatClient,
  type OfficeWorkspaceClient,
} from '../api/client'
import type {
  ChatEditorSelection,
  ChatMessage,
  ChatProviderStatus,
  ChatRunSummary,
  ChatSessionDetails,
  ChatSseEvent,
  ChatToolCallSummary,
  ProposalDecisionResult,
  ProposalDetails,
} from '../api/types'

export interface ChatLiveFragment {
  id: string
  run_id: string | null
  text: string
  seqs: number[]
  interrupted: boolean
  hidden: boolean
  finished: boolean
}

export interface UseOfficeChatOptions {
  sessionId?: string | null
  initialSession?: ChatSessionDetails | null
  resourceIds?: string[]
  initialSelection?: ChatEditorSelection | null
  autoLoadProposals?: boolean
  baseReconnectDelayMs?: number
  maxReconnectAttempts?: number
}

export type OfficeChatCompatibleClient = OfficeChatClient & Partial<Pick<OfficeWorkspaceClient, 'getProposal' | 'decideProposal'>>

function normalizeError(err: unknown): OfficeWorkspaceApiError {
  if (err instanceof OfficeWorkspaceApiError) {
    return err
  }
  const message = err instanceof Error ? err.message : 'Unexpected chat error.'
  return new OfficeWorkspaceApiError(message, 0, 'temporarily_unavailable', true, 'retry')
}

function generateMessageId(): string {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
    return crypto.randomUUID()
  }
  return `msg-${Date.now()}-${Math.random().toString(16).slice(2, 10)}`
}

export function useOfficeChat(client: OfficeChatCompatibleClient, options: UseOfficeChatOptions = {}) {
  const session = shallowRef<ChatSessionDetails | null>(options.initialSession ?? null)
  const sessionId = ref<string | null>(options.initialSession?.session_id ?? options.sessionId ?? null)
  const activeRunId = ref<string | null>(
    options.initialSession?.runs.at(-1)?.run_id ?? null,
  )
  const messages = ref<ChatMessage[]>(options.initialSession?.messages ? [...options.initialSession.messages] : [])
  const runs = ref<ChatRunSummary[]>(options.initialSession?.runs ? [...options.initialSession.runs] : [])
  const toolCalls = ref<ChatToolCallSummary[]>(options.initialSession?.tool_calls ? [...options.initialSession.tool_calls] : [])
  const provider = ref<ChatProviderStatus | null>(options.initialSession?.provider ?? null)
  const lastSeq = ref<number>(options.initialSession?.last_seq ?? 0)

  const liveFragments = ref<ChatLiveFragment[]>([])
  const proposalsById = ref<Record<string, ProposalDetails>>({})
  const selection = ref<ChatEditorSelection | null>(normalizeChatSelection(options.initialSelection))
  const resourceIds = ref<string[]>(options.resourceIds ? [...options.resourceIds] : [])

  const loading = ref(false)
  const sending = ref(false)
  const streamConnected = ref(false)
  const submittingProposalIds = ref<string[]>([])
  const error = ref<OfficeWorkspaceApiError | null>(null)
  const lastDecisionWon = ref<boolean | null>(null)
  const resetCount = ref(0)
  const hasHiddenContent = ref(false)

  // Separate explicit UI status flags (interrupted vs waiting approval vs owner committed vs verified)
  const approvalWaitingByRun = ref<Record<string, boolean>>({})
  const ownerCommittedByRun = ref<Record<string, boolean>>({})
  const verifiedByRun = ref<Record<string, boolean>>({})
  const verificationFailedByRun = ref<Record<string, boolean>>({})
  const verifiedOutcomeByRun = ref<Record<string, string | null>>({})
  const interruptedByRun = ref<Record<string, boolean>>({})

  let subscription: ChatEventSubscription | null = null
  let fragmentCounter = 0
  let fragmentSnapshotSeq = 0
  let proposalEpoch = 0
  let streamGeneration = 0

  const autoLoadProposals = options.autoLoadProposals ?? true

  function setSelection(nextSelection: ChatEditorSelection | null): void {
    selection.value = normalizeChatSelection(nextSelection)
  }

  function setResourceIds(ids: string[]): void {
    resourceIds.value = Array.from(new Set(ids.filter((id) => id.trim() !== '')))
  }

  function verifiedReceipt(call: ChatToolCallSummary): boolean {
    if (call.hidden === true || !call.receipt_id) {
      return false
    }
    return call.receipt_verified ?? (call.state === 'verified' && call.tool === 'office.commit')
  }

  function applySessionSnapshot(details: ChatSessionDetails, resetCursor = false): void {
    const sameSession = sessionId.value === details.session_id
    const canRestoreFragments = resetCursor || !sameSession || details.last_seq >= lastSeq.value
    session.value = details
    sessionId.value = details.session_id
    provider.value = details.provider
    runs.value = [...details.runs]
    lastSeq.value = sameSession && !resetCursor ? Math.max(lastSeq.value, details.last_seq) : details.last_seq

    const hiddenRunIds = new Set<string>()
    const completedAssistantRuns = new Set<string>()
    let foundHidden = false

    messages.value = details.messages.map((msg) => {
      if ('hidden' in msg && msg.hidden === true) {
        foundHidden = true
        return { message_id: msg.message_id, hidden: true }
      }
      if (msg.role === 'assistant' && msg.run_id) {
        completedAssistantRuns.add(msg.run_id)
      }
      return msg
    })

    const nextWaiting: Record<string, boolean> = {}
    const nextCommitted: Record<string, boolean> = {}
    const nextVerified: Record<string, boolean> = {}
    const nextFailedVerify: Record<string, boolean> = {}

    toolCalls.value = details.tool_calls.map((tc) => {
      if ('hidden' in tc && tc.hidden === true) {
        foundHidden = true
        hiddenRunIds.add(tc.run_id)
        return { call_id: tc.call_id, run_id: tc.run_id, hidden: true }
      }
      if (tc.state === 'awaiting_approval') {
        nextWaiting[tc.run_id] = true
      }
      if (tc.receipt_id !== null && (tc.state === 'committed' || tc.state === 'verified')) {
        nextCommitted[tc.run_id] = true
      }
      if (verifiedReceipt(tc)) {
        nextVerified[tc.run_id] = true
      }
      if (tc.receipt_verification_failed ?? tc.state === 'committed') {
        nextFailedVerify[tc.run_id] = true
      }
      return tc
    })

    if (details.live_fragments?.some((fragment) => fragment.hidden)) {
      foundHidden = true
    }
    if (foundHidden) {
      hasHiddenContent.value = true
      proposalEpoch += 1
      // Scrub cached selection & proposal cards associated with hidden runs or revoked scope.
      const nextProposals: Record<string, ProposalDetails> = {}
      for (const [pid, prop] of Object.entries(proposalsById.value)) {
        const propRunId = prop.run?.run_id
        const isVisibleCall = details.tool_calls.some(
          (tc) => !('hidden' in tc && tc.hidden === true) && tc.proposal_id === pid,
        )
        if (isVisibleCall && (!propRunId || !hiddenRunIds.has(propRunId))) {
          nextProposals[pid] = prop
        }
      }
      proposalsById.value = nextProposals
    }

    if (details.live_fragments && canRestoreFragments) {
      fragmentSnapshotSeq = details.last_seq
      liveFragments.value = details.live_fragments.map((fragment) => ({
        ...fragment,
        id: `snapshot-${fragment.seqs[0]}`,
        finished: !!fragment.run_id && completedAssistantRuns.has(fragment.run_id),
      }))
      interruptedByRun.value = {}
    } else if (resetCursor || !sameSession) {
      fragmentSnapshotSeq = 0
      liveFragments.value = []
      interruptedByRun.value = {}
    } else {
      liveFragments.value = liveFragments.value
        .filter((frag) => !frag.run_id || !completedAssistantRuns.has(frag.run_id) || frag.hidden || frag.interrupted)
        .map((frag) => foundHidden ? { ...frag, text: '', hidden: true } : frag)
      if (foundHidden) {
        interruptedByRun.value = {}
      }
    }

    if (Object.keys(nextWaiting).length > 0 || foundHidden) {
      approvalWaitingByRun.value = foundHidden ? nextWaiting : { ...approvalWaitingByRun.value, ...nextWaiting }
    }
    if (Object.keys(nextCommitted).length > 0 || foundHidden) {
      ownerCommittedByRun.value = foundHidden ? nextCommitted : { ...ownerCommittedByRun.value, ...nextCommitted }
    }
    if (Object.keys(nextVerified).length > 0 || foundHidden) {
      verifiedByRun.value = foundHidden ? nextVerified : { ...verifiedByRun.value, ...nextVerified }
    }
    if (foundHidden) {
      verifiedOutcomeByRun.value = {}
    }
    if (Object.keys(nextFailedVerify).length > 0 || foundHidden) {
      verificationFailedByRun.value = foundHidden ? nextFailedVerify : { ...verificationFailedByRun.value, ...nextFailedVerify }
    }

    if (!activeRunId.value && details.runs.length > 0) {
      activeRunId.value = details.runs[details.runs.length - 1]?.run_id ?? null
    }

    if (autoLoadProposals && typeof client.getProposal === 'function') {
      for (const tc of details.tool_calls) {
        if (!('hidden' in tc && tc.hidden === true) && tc.proposal_id && !proposalsById.value[tc.proposal_id]) {
          void fetchProposalSafe(tc.proposal_id)
        }
      }
    }
  }

  async function fetchProposalSafe(proposalId: string): Promise<void> {
    if (typeof client.getProposal !== 'function') {
      return
    }
    const epoch = proposalEpoch
    try {
      const prop = await client.getProposal(proposalId)
      if (epoch !== proposalEpoch) {
        return
      }
      proposalsById.value = {
        ...proposalsById.value,
        [proposalId]: prop,
      }
      const rid = prop.run?.run_id
      if (rid) {
        if (prop.approval.decision === 'pending' && prop.approval.mode === 'human') {
          approvalWaitingByRun.value = { ...approvalWaitingByRun.value, [rid]: true }
        } else if (prop.approval.decision !== 'pending') {
          approvalWaitingByRun.value = { ...approvalWaitingByRun.value, [rid]: false }
        }
        if (prop.execution.owner_status === 'committed') {
          ownerCommittedByRun.value = { ...ownerCommittedByRun.value, [rid]: true }
        }
        if (prop.verification.status === 'passed' && prop.execution.receipt_id) {
          verifiedByRun.value = { ...verifiedByRun.value, [rid]: true }
        } else if (prop.verification.status === 'failed') {
          verificationFailedByRun.value = { ...verificationFailedByRun.value, [rid]: true }
        }
      }
    } catch {
      if (epoch !== proposalEpoch) {
        return
      }
      const copy = { ...proposalsById.value }
      delete copy[proposalId]
      proposalsById.value = copy
    }
  }

  async function ensureSession(retentionSeconds?: number): Promise<string> {
    if (sessionId.value) {
      return sessionId.value
    }
    loading.value = true
    error.value = null
    try {
      const created = await client.createChatSession(
        retentionSeconds !== undefined ? { retentionSeconds } : {},
      )
      applySessionSnapshot(created)
      return created.session_id
    } catch (err) {
      error.value = normalizeError(err)
      throw error.value
    } finally {
      loading.value = false
    }
  }

  async function refreshSession(targetSessionId?: string, fromStream?: { generation: number; resetCursor?: boolean }): Promise<ChatSessionDetails> {
    const id = targetSessionId ?? sessionId.value
    if (!id) {
      throw new OfficeWorkspaceApiError('No chat session id specified.', 400, 'invalid_input', false, 'fail')
    }
    loading.value = true
    error.value = null
    try {
      const details = await client.getChatSession(id)
      if (!fromStream || fromStream.generation === streamGeneration) {
        applySessionSnapshot(details, fromStream?.resetCursor)
      }
      return details
    } catch (err) {
      const apiError = normalizeError(err)
      if (!fromStream || fromStream.generation === streamGeneration) {
        error.value = apiError
        if (apiError.status === 410 || apiError.status === 404) {
          clearSessionContent()
        }
      }
      throw apiError
    } finally {
      loading.value = false
    }
  }

  function handleSseEvent(event: ChatSseEvent): void {
    if ((event.type === 'assistant.delta' || event.type === 'assistant.interrupted') &&
        event.seq !== null && event.seq <= fragmentSnapshotSeq) {
      return
    }
    if (event.seq !== null && event.seq > lastSeq.value) {
      lastSeq.value = event.seq
    }

    const eventRunId = typeof event.data.run_id === 'string' && event.data.run_id !== ''
      ? event.data.run_id
      : activeRunId.value

    if (eventRunId && !activeRunId.value) {
      activeRunId.value = eventRunId
    }

    switch (event.type) {
      case 'assistant.delta': {
        const chunk = typeof event.data.text === 'string' ? event.data.text : ''
        if (chunk === '') {
          return
        }
        const tail = liveFragments.value[liveFragments.value.length - 1]
        if (tail && tail.run_id === eventRunId && !tail.interrupted && !tail.hidden && !tail.finished) {
          const updated: ChatLiveFragment = {
            ...tail,
            text: tail.text + chunk,
            seqs: event.seq !== null ? [...tail.seqs, event.seq] : tail.seqs,
          }
          liveFragments.value = [
            ...liveFragments.value.slice(0, -1),
            updated,
          ]
        } else {
          fragmentCounter += 1
          liveFragments.value = [
            ...liveFragments.value,
            {
              id: `frag-${fragmentCounter}`,
              run_id: eventRunId ?? null,
              text: chunk,
              seqs: event.seq !== null ? [event.seq] : [],
              interrupted: false,
              hidden: false,
              finished: false,
            },
          ]
        }
        return
      }

      case 'assistant.interrupted': {
        if (eventRunId) {
          interruptedByRun.value = { ...interruptedByRun.value, [eventRunId]: true }
        }
        let markedExisting = false
        liveFragments.value = liveFragments.value.map((frag, idx) => {
          if (idx === liveFragments.value.length - 1 && frag.run_id === eventRunId && !frag.hidden) {
            markedExisting = true
            return { ...frag, interrupted: true }
          }
          return frag
        })
        if (!markedExisting) {
          fragmentCounter += 1
          liveFragments.value = [
            ...liveFragments.value,
            {
              id: `frag-int-${fragmentCounter}`,
              run_id: eventRunId ?? null,
              text: '',
              seqs: event.seq !== null ? [event.seq] : [],
              interrupted: true,
              hidden: false,
              finished: false,
            },
          ]
        }
        return
      }

      case 'tool.requested': {
        const callId = typeof event.data.call_id === 'string' ? event.data.call_id : ''
        const toolName = typeof event.data.tool === 'string' ? event.data.tool : ''
        if (callId !== '' && !toolCalls.value.some((tc) => tc.call_id === callId)) {
          toolCalls.value = [
            ...toolCalls.value,
            {
              call_id: callId,
              run_id: eventRunId ?? '',
              step_id: toolCalls.value.length + 1,
              tool: toolName,
              tool_version: '1',
              state: 'requested',
              resource_id: null,
              proposal_id: null,
              command_id: null,
              receipt_id: null,
            },
          ]
        }
        return
      }

      case 'approval.required': {
        const callId = typeof event.data.call_id === 'string' ? event.data.call_id : ''
        const proposalId = typeof event.data.proposal_id === 'string' ? event.data.proposal_id : ''
        if (eventRunId) {
          approvalWaitingByRun.value = { ...approvalWaitingByRun.value, [eventRunId]: true }
          activeRunId.value = eventRunId
        }
        toolCalls.value = toolCalls.value.map((tc) => (
          tc.call_id === callId && !('hidden' in tc && tc.hidden === true)
            ? { ...tc, state: 'awaiting_approval', proposal_id: proposalId || tc.proposal_id }
            : tc
        ))
        if (proposalId !== '' && autoLoadProposals) {
          void fetchProposalSafe(proposalId)
        }
        return
      }

      case 'tool.committed': {
        const callId = typeof event.data.call_id === 'string' ? event.data.call_id : ''
        const proposalId = typeof event.data.proposal_id === 'string' ? event.data.proposal_id : null
        const commandId = typeof event.data.command_id === 'string' ? event.data.command_id : null
        const receiptId = typeof event.data.receipt_id === 'string' ? event.data.receipt_id : null
        if (eventRunId) {
          approvalWaitingByRun.value = { ...approvalWaitingByRun.value, [eventRunId]: false }
          ownerCommittedByRun.value = { ...ownerCommittedByRun.value, [eventRunId]: true }
        }
        toolCalls.value = toolCalls.value.map((tc) => (
          tc.call_id === callId && !('hidden' in tc && tc.hidden === true)
            ? {
              ...tc,
              state: 'committed',
              proposal_id: proposalId ?? tc.proposal_id,
              command_id: commandId ?? tc.command_id,
              receipt_id: receiptId ?? tc.receipt_id,
            }
            : tc
        ))
        if (proposalId && autoLoadProposals) {
          void fetchProposalSafe(proposalId)
        }
        return
      }

      case 'tool.verified': {
        const callId = typeof event.data.call_id === 'string' ? event.data.call_id : ''
        const proposalId = typeof event.data.proposal_id === 'string' ? event.data.proposal_id : null
        const commandId = typeof event.data.command_id === 'string' ? event.data.command_id : null
        const receiptId = typeof event.data.receipt_id === 'string' ? event.data.receipt_id : null
        const verification = (event.data.verification ?? {}) as Record<string, unknown>
        const nestedResult = (verification.result ?? verification) as Record<string, unknown>
        const outcome = typeof nestedResult.outcome === 'string' ? nestedResult.outcome : null
        const verified = outcome === 'passed' && !!receiptId && verification.ok === true
        if (eventRunId) {
          approvalWaitingByRun.value = { ...approvalWaitingByRun.value, [eventRunId]: false }
          ownerCommittedByRun.value = { ...ownerCommittedByRun.value, [eventRunId]: true }
          verifiedOutcomeByRun.value = { ...verifiedOutcomeByRun.value, [eventRunId]: outcome }
          if (verified) {
            verifiedByRun.value = { ...verifiedByRun.value, [eventRunId]: true }
          } else if (outcome !== 'passed') {
            verificationFailedByRun.value = { ...verificationFailedByRun.value, [eventRunId]: true }
          }
        }
        toolCalls.value = toolCalls.value.map((tc) => (
          tc.call_id === callId && !('hidden' in tc && tc.hidden === true)
            ? {
              ...tc,
              state: verified ? 'verified' : 'committed',
              proposal_id: proposalId ?? tc.proposal_id,
              command_id: commandId ?? tc.command_id,
              receipt_id: receiptId ?? tc.receipt_id,
              receipt_verified: verified,
              receipt_verification_failed: !verified,
            }
            : tc
        ))
        if (proposalId && autoLoadProposals) {
          void fetchProposalSafe(proposalId)
        }
        return
      }

      case 'run.finished': {
        const state = typeof event.data.state === 'string' ? event.data.state : 'completed'
        if (eventRunId) {
          if (state === 'rejected') {
            approvalWaitingByRun.value = { ...approvalWaitingByRun.value, [eventRunId]: false }
          }
          runs.value = runs.value.map((r) => (
            r.run_id === eventRunId ? { ...r, state } : r
          ))
        }
        liveFragments.value = liveFragments.value.map((frag) => (
          frag.run_id === eventRunId ? { ...frag, finished: true } : frag
        ))
        if (sessionId.value) {
          void refreshSession(sessionId.value).catch(() => undefined)
        }
        return
      }

      case 'hidden': {
        hasHiddenContent.value = true
        const targetSeq = event.seq
        let collapsedAny = false
        liveFragments.value = liveFragments.value.map((frag) => {
          if (targetSeq !== null && frag.seqs.includes(targetSeq)) {
            collapsedAny = true
            return { ...frag, text: '', hidden: true }
          }
          return frag
        })
        if (!collapsedAny) {
          // If hidden frame arrived without a specific cursor match, mark redacted & collapse non-hidden live fragments.
          liveFragments.value = liveFragments.value.map((frag) => ({
            ...frag,
            text: '',
            hidden: true,
          }))
        }
        if (sessionId.value) {
          void refreshSession(sessionId.value).catch(() => undefined)
        }
        return
      }

      case 'reset': {
        resetCount.value += 1
        return
      }
    }
  }

  function disconnectStream(): void {
    streamGeneration += 1
    subscription?.close()
    subscription = null
    streamConnected.value = false
  }

  function connectStream(targetSessionId?: string, startCursor?: number): void {
    const id = targetSessionId ?? sessionId.value
    if (!id) {
      return
    }
    disconnectStream()

    const generation = streamGeneration
    const subscribe = (cursor: number, recoveredCursor?: number): void => {
      let needsSnapshot = false
      subscription = client.connectChatEvents(id, {
        cursor,
        baseReconnectDelayMs: options.baseReconnectDelayMs,
        maxReconnectAttempts: options.maxReconnectAttempts,
        onOpen: async () => {
          if (needsSnapshot) {
            await refreshSession(id, { generation })
          }
          needsSnapshot = true
          if (generation === streamGeneration) {
            streamConnected.value = true
          }
        },
        onEvent: handleSseEvent,
        onReset: async () => {
          streamConnected.value = false
          try {
            const refreshed = await refreshSession(id, { generation, resetCursor: true })
            if (generation !== streamGeneration) {
              return
            }
            if (refreshed.last_seq === recoveredCursor) {
              error.value = new OfficeWorkspaceApiError('Chat cursor could not be restored.', 410, 'cursor_expired', false, 'fail')
              return
            }
            subscribe(refreshed.last_seq, refreshed.last_seq)
          } catch {
            // refreshSession records the failure for the panel.
          }
        },
        onError: (apiError) => {
          needsSnapshot = true
          streamConnected.value = false
          error.value = apiError
          if (apiError.status === 410 || apiError.status === 404) {
            clearSessionContent()
          }
        },
      })
    }
    subscribe(startCursor ?? lastSeq.value)
  }

  function clearSessionContent(): void {
    proposalEpoch += 1
    session.value = null
    activeRunId.value = null
    messages.value = []
    runs.value = []
    liveFragments.value = []
    toolCalls.value = []
    proposalsById.value = {}
    selection.value = null
    approvalWaitingByRun.value = {}
    ownerCommittedByRun.value = {}
    verifiedByRun.value = {}
    verifiedOutcomeByRun.value = {}
    verificationFailedByRun.value = {}
    interruptedByRun.value = {}
  }

  async function sendMessage(content: string, customSelection?: ChatEditorSelection | null, customMessageId?: string): Promise<string> {
    const trimmed = content.trim()
    if (trimmed === '') {
      throw new OfficeWorkspaceApiError('Message cannot be empty.', 400, 'invalid_input', false, 'fail')
    }

    sending.value = true
    error.value = null
    try {
      const id = await ensureSession()
      const msgId = customMessageId ?? generateMessageId()
      const activeSelection = normalizeChatSelection(customSelection !== undefined ? customSelection : selection.value)
      const scope = new Set(resourceIds.value)
      if (activeSelection) {
        scope.add(activeSelection.resource_id)
      }
      const scopeList = Array.from(scope)

      const result = await client.postChatMessage(id, {
        messageId: msgId,
        content,
        resourceIds: scopeList,
        selection: activeSelection,
      })

      activeRunId.value = result.run_id
      approvalWaitingByRun.value = { ...approvalWaitingByRun.value, [result.run_id]: false }
      ownerCommittedByRun.value = { ...ownerCommittedByRun.value, [result.run_id]: false }
      verifiedByRun.value = { ...verifiedByRun.value, [result.run_id]: false }
      verificationFailedByRun.value = { ...verificationFailedByRun.value, [result.run_id]: false }
      interruptedByRun.value = { ...interruptedByRun.value, [result.run_id]: false }

      if (!messages.value.some((m) => m.message_id === msgId)) {
        messages.value = [
          ...messages.value,
          {
            message_id: msgId,
            role: 'user',
            run_id: result.run_id,
            content,
            ...(activeSelection ? { selection: activeSelection } : {}),
          },
        ]
      }

      if (!subscription || subscription.closed) {
        connectStream(id, lastSeq.value)
      }

      return result.run_id
    } catch (err) {
      error.value = normalizeError(err)
      throw error.value
    } finally {
      sending.value = false
    }
  }

  async function decideProposal(proposalId: string, digest: string, approved: boolean, commandId?: string): Promise<ProposalDecisionResult> {
    if (typeof client.decideProposal !== 'function') {
      throw new OfficeWorkspaceApiError('Proposal decision client method is unavailable.', 500, 'temporarily_unavailable', false, 'fail')
    }
    if (!submittingProposalIds.value.includes(proposalId)) {
      submittingProposalIds.value = [...submittingProposalIds.value, proposalId]
    }
    error.value = null
    const epoch = proposalEpoch
    try {
      const res = await client.decideProposal(proposalId, { digest, approved, commandId })
      if (epoch !== proposalEpoch) {
        return res
      }
      lastDecisionWon.value = res.won
      proposalsById.value = {
        ...proposalsById.value,
        [proposalId]: res.proposal,
      }
      const rid = res.proposal.run?.run_id ?? activeRunId.value
      if (rid) {
        approvalWaitingByRun.value = { ...approvalWaitingByRun.value, [rid]: false }
        if (res.proposal.execution.owner_status === 'committed') {
          ownerCommittedByRun.value = { ...ownerCommittedByRun.value, [rid]: true }
        }
        if (res.proposal.verification.status === 'passed' && res.proposal.execution.receipt_id) {
          verifiedByRun.value = { ...verifiedByRun.value, [rid]: true }
        }
      }
      return res
    } catch (err) {
      error.value = normalizeError(err)
      throw error.value
    } finally {
      submittingProposalIds.value = submittingProposalIds.value.filter((id) => id !== proposalId)
    }
  }

  const activeRun = computed<ChatRunSummary | null>(() => {
    if (!activeRunId.value) {
      return runs.value[runs.value.length - 1] ?? null
    }
    return runs.value.find((r) => r.run_id === activeRunId.value) ?? null
  })

  const currentInterrupted = computed<boolean>(() => {
    const rid = activeRunId.value
    if (rid && interruptedByRun.value[rid]) {
      return true
    }
    return liveFragments.value.some((f) => (!rid || f.run_id === rid) && f.interrupted && !f.hidden)
  })

  const currentWaitingApproval = computed<boolean>(() => {
    const rid = activeRunId.value
    if (rid && approvalWaitingByRun.value[rid]) {
      return true
    }
    return Object.values(proposalsById.value).some(
      (p) => (!rid || p.run?.run_id === rid) && p.approval.mode === 'human' && p.approval.decision === 'pending',
    )
  })

  const currentOwnerCommitted = computed<boolean>(() => {
    const rid = activeRunId.value
    if (rid && ownerCommittedByRun.value[rid]) {
      return true
    }
    return toolCalls.value.some(
      (tc) => !('hidden' in tc && tc.hidden === true) && (!rid || tc.run_id === rid) && tc.receipt_id !== null,
    )
  })

  const currentVerified = computed<boolean>(() => {
    const rid = activeRunId.value
    if (rid && verifiedByRun.value[rid]) {
      return true
    }
    return toolCalls.value.some(
      (tc) => (!rid || tc.run_id === rid) && verifiedReceipt(tc),
    )
  })

  const currentVerificationFailed = computed<boolean>(() => {
    const rid = activeRunId.value
    return rid ? Boolean(verificationFailedByRun.value[rid]) && !currentVerified.value : false
  })

  const activeProposals = computed<ProposalDetails[]>(() => {
    const rid = activeRunId.value
    return Object.values(proposalsById.value).filter(
      (p) => !rid || !p.run?.run_id || p.run.run_id === rid,
    )
  })

  if (options.initialSession) {
    applySessionSnapshot(options.initialSession)
  }

  if (getCurrentInstance()) {
    onBeforeUnmount(() => {
      disconnectStream()
    })
  }

  return {
    session,
    sessionId,
    activeRunId,
    activeRun,
    messages,
    liveFragments,
    runs,
    toolCalls,
    proposalsById,
    activeProposals,
    provider,
    selection,
    resourceIds,
    lastSeq,
    resetCount,
    hasHiddenContent,
    currentInterrupted,
    currentWaitingApproval,
    currentOwnerCommitted,
    currentVerified,
    currentVerificationFailed,
    loading,
    sending,
    streamConnected,
    submittingProposalIds,
    error,
    lastDecisionWon,
    setSelection,
    setResourceIds,
    ensureSession,
    refreshSession,
    connectStream,
    disconnectStream,
    sendMessage,
    decideProposal,
  }
}
