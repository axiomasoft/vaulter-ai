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
