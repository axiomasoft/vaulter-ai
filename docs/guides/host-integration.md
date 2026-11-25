# Host integration

Core binds a default for every contract. Defaults that would grant access are
fail-closed; replace them in an application service provider (application
providers register after package providers, so a plain `singleton()` wins).

| Contract | Default | Replace when |
| --- | --- | --- |
| `CallContextResolver` | `NullCallContextResolver` — nobody is authenticated | Always |
| `HostPolicy` | `ConfigHostPolicy` — profiles from `vaulter-ai.profiles` | Commands or approval mode depend on the resource or the user |
| `ProposalApprovalPolicy` | `DefaultProposalApprovalPolicy` | Reviewers are not the initiators, or approval needs a role |
| `ApprovedCommandContextResolver` | unbound | Reviewers approve proposals that someone else initiated |
| `RunContextResolver` | unbound | Someone other than the initiator resumes runs or approves run steps |
| `CommandWriteCapability` | registry-based; documents binds its own | Writes depend on host state (for example a strict collaboration profile) |
| `ProviderSecretStore` | `DatabaseProviderSecretStore` (per-tenant, encrypted) | Secrets come from a vault |
| `ProposalSuggestionPort` | documents: tracked-change suggestions | Proposals should appear somewhere else in the editor |
| `TransactionRunner` | owner-specific | Never, unless you replace the database layer |
| `Clock` | `SystemClock` | Tests |

## Call context

`CallContext` is the server's statement of who is calling:

| Field | Meaning |
| --- | --- |
| `tenantId` | Tenant; every query and every encrypted payload is bound to it |
| `projectScope` | Project or workspace inside the tenant |
| `effectiveSubject` | The user (or service principal) on whose behalf the call runs |
| `profile` | Key into `vaulter-ai.profiles` |
| `policyRevision` | Revision of the host policy the context was resolved under |
| `driveId` | Optional Vaulter Drive scope |
| `actor` | The authenticated user object, for host policies |
| `attributes` | Free-form data for host policies |

Never derive these values from the request body. Agents send only resource
ids and arguments; authority comes exclusively from the resolver.

## Approval

`ProposalApprovalPolicy` separates three questions:

- `canView($viewer, …)` — may this person see the proposal and its diff?
- `canApprove($approver, …)` — may this person decide it?
- `isApprovalStillValidForCommit($initiator, …)` — is the approval still
  valid at commit time (for example the initiator has not lost write access)?

Approval is recorded against the proposal digest and checked again at commit.

## Acting for the initiator

A commit always runs with the authority of the person who initiated the
proposal or run, never with the reviewer's. When the reviewer is the
initiator nothing else is needed. When they differ, core asks
`ApprovedCommandContextResolver` (by tenant and proposal id) and then
`RunContextResolver` (by tenant and run id) to rebuild the initiator's
`CallContext`; the result is only accepted if tenant, project scope and
subject match the stored initiator. With neither bound, cross-user approval
is refused.

Each profile can carry a `revision`. A proposal remembers the revision it was
prepared under, and commit fails with `precondition_failed` when the profile
revision or its approval mode changed in between — bump it when you tighten a
profile.

## Owners and resources

`axiomasoft/vaulter-ai-documents` binds `ResourceDirectory` to Vaulter Drive:
search and reads are filtered through Drive access checks page by page.
Text is read from an addressable projection that is indexed on Documents
events; it can lag behind a commit, in which case reads with `after_receipt`
return `projection_pending` (or read the live document when
`read.live_fallback_on_lag` is enabled).

`axiomasoft/vaulter-ai-collaboration` must be installed next to documents for
documents that are edited live; it routes `text.replace_exact@1` through the
collaboration journal so concurrent editors see the change.

`axiomasoft/vaulter-ai-sheets` exposes Vaulter Sheets tables. Formula fills
are verified after recalculation; until then verification returns
`calculation_pending`.
