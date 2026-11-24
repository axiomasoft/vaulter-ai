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

// Tiptap AI Extensions & Helpers
export type * from './tiptap/types'
export {
  extractSanitizedBlockText,
  getEditorContext,
  modeForNode,
  modeForPath,
  modeForRange,
  normalizeContentProtectionPolicy,
} from './tiptap/contentProtection'
export {
  consumeTextOrSseStream,
  HtmlStreamBuffer,
  sanitizeHtmlFragment,
  STREAM_CONTENT_META,
  StreamContent,
} from './tiptap/StreamContent'
export type { StreamContentOptions } from './tiptap/StreamContent'
export { VAULTER_AI_COMMANDS_PLUGIN_KEY, VaulterAiCommands } from './tiptap/VaulterAiCommands'
export {
  extractAutocompleteContext,
  VAULTER_AI_AUTOCOMPLETE_PLUGIN_KEY,
  VaulterAiAutocomplete,
} from './tiptap/VaulterAiAutocomplete'
export {
  STREAMING_REVEAL_PLUGIN_KEY,
  StreamingReveal,
  VaulterStreamingReveal,
} from './tiptap/StreamingReveal'
