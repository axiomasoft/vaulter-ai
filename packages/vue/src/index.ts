export { createOfficeWorkspaceClient, normalizeChatSelection, OfficeWorkspaceApiError } from './api/client'
export type {
  ChatEventSubscription,
  ConnectChatEventsOptions,
  ListProposalsOptions,
  OfficeChatClient,
  OfficeWorkspaceChatClient,
  OfficeWorkspaceClient,
  OfficeWorkspaceClientOptions,
  WorkspaceWireErrorPayload,
} from './api/client'
export type * from './api/types'
export { useOfficeChat } from './composables/useOfficeChat'
export type { ChatLiveFragment, UseOfficeChatOptions } from './composables/useOfficeChat'
export { useProposalReview } from './composables/useProposalReview'
export type { UseProposalReviewOptions } from './composables/useProposalReview'
export { default as ChatPanel } from './components/ChatPanel.vue'
export { default as ProposalCard } from './components/ProposalCard.vue'
export { default as ProposalDiffView } from './components/ProposalDiffView.vue'
export { default as ProposalReviewPanel } from './components/ProposalReviewPanel.vue'
