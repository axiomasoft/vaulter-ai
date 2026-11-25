# Security model

## Trust boundaries

- **The model is untrusted.** It can only call the seven Office operations
  with resource ids and arguments. Tenant, subject and permissions come from
  the host's `CallContextResolver`; arguments that try to carry authority are
  rejected as `invalid_input`.
- **Reads are bounded and authorized per page.** Search candidates and read
  slices are filtered through the host's access checks; a resource the caller
  may not see is indistinguishable from one that does not exist
  (`resource_unavailable`).
- **Writes need a read and a proposal.** `prepare` requires a fresh read token
  and only stores a proposal. `commit` requires the proposal's digest, the
  current profile revision and, for `human` profiles, an approval made in the
  workspace by someone `ProposalApprovalPolicy` allows. The model has no way to
  approve.
- **Commits are idempotent and verifiable.** A `command_id` is applied at most
  once; `verify` proves the change against the owner's own state.

## Secrets

Provider API keys are resolved on the server by `ProviderSecretStore`. They are
never sent to the browser, never written into prompts, SSE frames, transcripts
or logs, and provider error messages are masked before they are surfaced.
Per-tenant keys are stored with authenticated encryption under a versioned key
(`secret_store.key_version`, `secret_store.keys`, falling back to `APP_KEY`)
and can be re-encrypted with `php artisan vaulter-ai:secrets:rotate`.

## Data at rest

Proposal payloads and read-token dependencies contain document content. They
are encrypted with AES-256-GCM and bound to their table, scope and record as
associated data. Proposals, read tokens and cursors expire by TTL; chat
sessions carry an explicit retention.

## Browser

The workspace routes run under the `web` middleware: they rely on the host's
session authentication and CSRF protection. Form posts redirect only to
same-host paths. Streamed model output is sanitized before it is inserted into
the editor (`@vaulter/ai-vue`), and protected content regions are excluded
from the editor context sent to the model.

## Reporting

See [SECURITY.md](../../SECURITY.md).
