export interface OfficeMarker {
  generation: number
  position: number
}

export interface TextSegmentMark {
  type: string
  attrs?: Record<string, unknown>
}

export interface TextSegment {
  text: string
  marks: TextSegmentMark[]
}

export interface TextReplaceDiff {
  kind: 'text_replace'
  block_id: string
  section_path: string[]
  expected: string
  replacement: string
  start_utf16: number
  before_segments: TextSegment[]
  after_segments: TextSegment[]
}

export interface SheetFormulaInputRow {
  row_id: string
  left: number | null
  right: number | null
}

export interface SheetFillFormulaDiff {
  kind: 'sheet_fill_formula'
  sheet_id: string
  table_id: string
  scope: string
  row_ids: string[]
  destination_column_id: string
  destination_column_letter: string
  left_column_letter: string
  right_column_letter: string
  formula_template: string
  inputs: SheetFormulaInputRow[]
}

export interface CommandFallbackDiff {
  kind: 'command'
  command: string
  targets: string[]
  summary: string
}

export type ProposalDiff = TextReplaceDiff | SheetFillFormulaDiff | CommandFallbackDiff

export type HumanStatus = 'pending' | 'approved' | 'rejected' | 'automatic'

export type OwnerStatus = 'not_started' | 'pending_dispatch' | 'running' | 'committed' | 'rejected'

export type VerificationStatus = 'unverified' | 'passed' | 'failed'

export interface ProposalResourceRef {
  resource_id: string
  resource_type: string
  title: string
}

export interface ProposalApprovalState {
  mode: 'human' | 'automatic' | string
  decision: 'pending' | 'approved' | 'rejected' | string
  human_status: HumanStatus | string
  reviewer: string | null
  decided_at: string | null
  expires_at: string
  expired: boolean
  can_approve: boolean
  can_reject: boolean
}

export interface ProposalExecutionState {
  owner_status: OwnerStatus | string
  command_id: string | null
  owner_key: string | null
  dispatch_state: string | null
  receipt_id: string | null
  marker: OfficeMarker | null
  rejection_reason: string | null
}

export interface ProposalVerificationState {
  status: VerificationStatus | string
  mode: string | null
  outcome: string | null
  marker: OfficeMarker | null
  checks: unknown
}

export interface ProposalRunRef {
  run_id: string
  state: string
  step_id: number
}

export interface ProposalDetails {
  proposal_id: string
  digest: string
  initiator: string
  tenant_id: string
  project_scope: string
  resource: ProposalResourceRef
  command: string
  base: OfficeMarker
  targets: string[]
  summary: string
  diff: ProposalDiff | null
  approval: ProposalApprovalState
  execution: ProposalExecutionState
  verification: ProposalVerificationState
  run: ProposalRunRef | null
}

export interface ProposalDecisionResult {
  won: boolean
  proposal: ProposalDetails
}

export interface RunReadSnapshot {
  step_id: number
  marker: OfficeMarker | null
  data: Record<string, unknown> | null
}

export interface RunResourceStatus {
  resource_id: string
  visible: boolean
  resource_type?: string
  title?: string
  base?: OfficeMarker | null
  latest_read?: RunReadSnapshot | null
  proposal?: ProposalDetails | null
  execution?: ProposalExecutionState
  verification?: ProposalVerificationState
  status: string
  error?: string | null
  requires_reread: boolean
  can_continue: boolean
  draft_configured?: boolean
}

export interface RunSummaryCounts {
  total_resources: number
  confirmed_count: number
  remaining_count: number
  awaiting_approval_count: number
  conflict_count: number
}

export interface RunStepSummary {
  step_id: number
  operation: string
  resource_id: string | null
  proposal_id: string | null
  command_id: string | null
  receipt_id: string | null
  state: string
  safe_error: string | null
  created_at: string
  updated_at: string
}

export interface RunDetails {
  run_id: string
  tenant_id: string
  project_scope: string
  initiator: string
  channel: string
  profile_revision: string
  state: string
  created_at: string
  updated_at: string
  can_resume: boolean
  summary: RunSummaryCounts
  confirmed_steps: RunResourceStatus[]
  remaining_steps: RunResourceStatus[]
  resources: RunResourceStatus[]
  steps: RunStepSummary[]
}

export interface DecideProposalInput {
  digest: string
  approved: boolean
  commandId?: string
}

export interface ResumeRunOptions {
  argumentsByResource?: Record<string, Record<string, unknown>>
  rereadOnly?: boolean
}

export interface ChatTextBlockSelection {
  kind: 'text_block'
  resource_id: string
  block_id: string
  excerpt?: string | null
  start_utf16?: number | null
  end_utf16?: number | null
}

export interface ChatSheetRangeSelection {
  kind: 'sheet_range'
  resource_id: string
  sheet_id: string
  table_id: string
  row_ids?: string[] | null
  columns?: string[] | null
}

export type ChatEditorSelection = ChatTextBlockSelection | ChatSheetRangeSelection

export interface ChatProviderStatus {
  configured: boolean
  connection: string
  driver: string | null
  model: string | null
  hint: string | null
}

export interface ChatRunSummary {
  run_id: string
  state: string
  channel: string
  created_at: string
  updated_at: string
}

export interface VisibleChatToolCall {
  call_id: string
  run_id: string
  step_id: number
  tool: string
  tool_version: string
  state: string
  resource_id: string | null
  proposal_id: string | null
  command_id: string | null
  receipt_id: string | null
  hidden?: false
  receipt_verified?: boolean
  receipt_verification_failed?: boolean
}

export interface HiddenChatToolCall {
  call_id: string
  run_id: string
  hidden: true
}

export type ChatToolCallSummary = VisibleChatToolCall | HiddenChatToolCall

export interface VisibleChatMessage {
  message_id: string
  role: 'user' | 'assistant' | string
  run_id: string | null
  content: string
  selection?: ChatEditorSelection
  hidden?: false
}

export interface HiddenChatMessage {
  message_id: string
  hidden: true
}

export type ChatMessage = VisibleChatMessage | HiddenChatMessage

export interface ChatSnapshotFragment {
  run_id: string | null
  seqs: number[]
  text: string
  interrupted: boolean
  hidden: boolean
}

export interface ChatSessionDetails {
  session_id: string
  state: string
  profile_revision: string
  last_seq: number
  provider: ChatProviderStatus
  runs: ChatRunSummary[]
  tool_calls: ChatToolCallSummary[]
  messages: ChatMessage[]
  live_fragments?: ChatSnapshotFragment[]
}

export interface CreateChatSessionInput {
  retentionSeconds?: number
}

export interface PostChatMessageInput {
  messageId: string
  content: string
  resourceIds?: string[]
  selection?: ChatEditorSelection | null
}

export interface PostChatMessageResult {
  message_id: string
  run_id: string
}

export type ChatSseEventType =
  | 'assistant.delta'
  | 'assistant.interrupted'
  | 'tool.requested'
  | 'approval.required'
  | 'tool.committed'
  | 'tool.verified'
  | 'run.finished'
  | 'hidden'
  | 'reset'
  | (string & {})

export interface ChatSseEvent {
  seq: number | null
  type: ChatSseEventType
  data: Record<string, unknown>
}

export interface ChatSessionSummary {
  session_id: string
  state: string
  last_seq: number
}

export interface ChatSessionListResult {
  sessions: ChatSessionSummary[]
  next_cursor: string | null
}

export interface ArchiveChatSessionResult {
  session_id: string
  state: string
}

export interface CancelChatRunResult {
  run_id: string
  state: string
}

export interface RetryChatRunResult {
  run_id: string
}

export interface ProposalCardMessages {
  statusDraft: string
  statusPending: string
  statusApprovedByHuman: string
  statusAutomatic: string
  statusApprovedPendingExecution: string
  statusApprovedCommitted: string
  statusOwnerRunning: string
  statusApprovedFailed: string
  statusRejectedByHuman: string
  statusRejectedByOwner: string
  statusOwnerNotStarted: string
  statusVerifyPassed: string
  statusVerifyFailed: string
  statusVerifyUnverified: string
  decisionWon: string
  receiptLabel: string
  errorLabel: string
  ownerRejectionLabel: string
  detailsShow: string
  detailsHide: string
  acceptButton: string
  rejectButton: string
  approvingButton: string
  rejectingButton: string
  initiatorLabel: string
  reviewerLabel: string
  resourceLabel: string
  proposalIdLabel: string
  commandIdLabel: string
  digestLabel: string
  expiresLabel: string
  expiredNote: string
  readonlyNote: string
}

export interface ProposalReviewPanelMessages {
  title: string
  emptyState: string
  refreshButton: string
  refreshingButton: string
  refreshRunButton: string
  resumeRunButton: string
  filterAll: string
  filterOpen: string
  filterResolved: string
  errorPrefix: string
  lostRaceNotice: string
  runTitle: string
  runStateLabel: string
  runInitiatorLabel: string
  runChannelLabel: string
  confirmedStepsTitle: string
  remainingStepsTitle: string
  stepStatusLabel: string
  receiptInlineLabel: string
  verifiedInlineLabel: string
  requiresRereadBadge: string
  card?: Partial<ProposalCardMessages>
}

export interface ChatPanelMessages {
  title: string
  sessionLabel: string
  newSessionFallback: string
  newSessionButton: string
  sessionsLoading: string
  sessionsEmpty: string
  sessionSwitchLabel: string
  archiveSessionButton: string
  resetHistoryNotice: string
  hiddenNotice: string
  inputPlaceholder: string
  sendButton: string
  sendingButton: string
  refreshButton: string
  cancelRunButton: string
  cancellingRunButton: string
  retryRunButton: string
  retryingRunButton: string
  selectionLabel: string
  toolCallsTitle: string
  proposalsTitle: string
  errorPrefix: string
  streamConnectedLabel: string
  streamDisconnectedLabel: string
  providerLabel: string
  providerConfigured: string
  providerOffline: string
  runIdLabel: string
  runStateLabel: string
  cursorLabel: string
  userRoleLabel: string
  assistantRoleLabel: string
  runLabel: string
  hiddenMessageNotice: string
  hiddenFragmentNotice: string
  hiddenToolCallPrefix: string
  hiddenToolCallSuffix: string
  receiptInlineLabel: string
  documentLabel: string
  blockLabel: string
  sheetLabel: string
  tableLabel: string
  tabLabel: string
  statusRunning: string
  statusWaitingApproval: string
  statusOwnerCommitted: string
  statusVerified: string
  statusVerificationFailed: string
  statusInterrupted: string
  statusCancelled: string
  statusFailed: string
  proposalCard?: Partial<ProposalCardMessages>
}
