# @vaulter/ai-vue

Vue 3 components and composables for the Vaulter AI workspace: proposal
review with digest-bound decisions, recovery of partially applied runs, a
streaming chat panel, and Tiptap extensions for AI commands, autocomplete and
streamed content.

## Installation

```bash
npm install @vaulter/ai-vue
```

Peer dependencies: `vue` 3.5, `@tiptap/core` and `@tiptap/pm` 3, `yjs` 13.
Node.js `>=26.13.1` is required to build.

```ts
import '@vaulter/ai-vue/style.css'
import { createOfficeWorkspaceClient, ProposalReviewPanel, ChatPanel } from '@vaulter/ai-vue'

const client = createOfficeWorkspaceClient({
  workspacePrefix: 'office',          // matches vaulter-ai.workspace_prefix
  credentials: 'same-origin',         // session cookie of the host app
  headers: () => ({ 'X-CSRF-TOKEN': csrfToken }),
})
```

```vue
<ProposalReviewPanel :client="client" :run-id="runId" />
<ChatPanel :client="client" :resource-ids="[documentId]" />
```

## What is exported

| Export | Purpose |
| --- | --- |
| `createOfficeWorkspaceClient`, `OfficeWorkspaceApiError` | Typed client for the `/office/...` workspace endpoints and the chat SSE stream |
| `useProposalReview`, `ProposalReviewPanel`, `ProposalCard`, `ProposalDiffView` | List, inspect and decide proposals; resume partial runs |
| `useOfficeChat`, `ChatPanel` | Chat sessions with reconnecting event streams |
| `VaulterAiCommands`, `VaulterAiAutocomplete`, `StreamContent`, `StreamingReveal` | Tiptap extensions |
| `getEditorContext`, `normalizeContentProtectionPolicy`, `sanitizeHtmlFragment` | Editor context for the model with protected regions removed, and HTML sanitizing for streamed output |

All wire types are exported as TypeScript types.

## Development

```bash
npm run typecheck -w @vaulter/ai-vue
npm test -w @vaulter/ai-vue
npm run build -w @vaulter/ai-vue
```

See the [HTTP API](../../docs/reference/http-api.md) for the endpoints the
client talks to. Proprietary. Part of the [Vaulter AI](../../README.md) monorepo.
