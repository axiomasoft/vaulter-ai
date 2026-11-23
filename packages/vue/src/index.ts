export { createOfficeWorkspaceClient, OfficeWorkspaceApiError } from './api/client'
export type {
  ListProposalsOptions,
  OfficeWorkspaceClient,
  OfficeWorkspaceClientOptions,
  WorkspaceWireErrorPayload,
} from './api/client'
export type * from './api/types'
export { useProposalReview } from './composables/useProposalReview'
export type { UseProposalReviewOptions } from './composables/useProposalReview'
export { default as ProposalCard } from './components/ProposalCard.vue'
export { default as ProposalDiffView } from './components/ProposalDiffView.vue'
export { default as ProposalReviewPanel } from './components/ProposalReviewPanel.vue'
