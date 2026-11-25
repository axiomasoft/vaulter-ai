# The Office protocol (SDK v1)

Every message is a JSON object with `version: 1` and an `operation`. A request
carries `input`; a response carries either `ok: true` and `result`, or
`ok: false` and `error`. The exact shapes are defined by
[`office-v1.schema.json`](../../packages/core/resources/contracts/office-v1.schema.json).

```json
{ "version": 1, "operation": "office.search", "input": { "query": "termination clause" } }
```

## Lifecycle

```text
search ──► read ──► describe ──► prepare ──► (approval) ──► commit ──► status ──► verify
            ▲                       │                          │
            └──── reread on ◄───────┴── precondition / scope / generation changed
```

| Operation | Purpose | Key input | Key result |
| --- | --- | --- | --- |
| `office.search` | Find resources the caller may access | `query`, `resource_type?`, `limit?`, `cursor?` | `items[]`, `truncated`, `next_cursor` |
| `office.read` | Read a bounded slice | `resource_id`, `selector`, `limit?`, `cursor?`, `after_receipt?` | `data`, `marker`, `read_token`, `truncated`, `next_cursor` |
| `office.describe` | What a command does and how to call it on this resource | `resource_id`, `command` | `effect`, `input_schema` (JSON Schema of `arguments`), `verification` |
| `office.prepare` | Turn a change into a proposal | `resource_id`, `command`, `read_token`, `arguments` | `proposal_id`, `digest`, `approval`, `summary`, `targets`, `expires_at` |
| `office.commit` | Apply an approved proposal | `resource_id`, `proposal_id`, `digest`, `command_id` | `running` or `committed` with `receipt_id` and `marker` |
| `office.status` | Poll a running commit | `resource_id`, `command_id` | same as commit |
| `office.verify` | Check that the change landed | `resource_id`, `receipt_id`, `mode` (`at_commit` or `current`) | `outcome` (`passed`/`failed`), `marker`, `checks[]` |

### Selectors

| `kind` | Resource | Fields |
| --- | --- | --- |
| `text_outline` | text | `query?` |
| `text_block` | text | `block_id` |
| `sheet_catalog` | sheet | — |
| `sheet_table` | sheet | `sheet_id`, `table_id`, `columns[]`, `row_ids[]?` |

### Read tokens

A read returns a `read_token` bound to what was read. `prepare` requires it,
so a model can only propose changes to content it has actually seen, and the
proposal fails with `read_token_expired` or `generation_changed` when the
resource moved on. Tokens live `limits.read_token_ttl_seconds` (15 minutes).

### Proposals and the digest

`prepare` never changes anything. It stores a proposal and returns its
`digest`: the lowercase hex SHA-256 of the RFC 8785 canonical JSON of
`{version: 1, prepare_input, plan}` (the plan without its digest). `commit`
must present the same digest, so what is committed is byte-for-byte what was
reviewed. Test vectors live in `tests/Fixtures/proposal-digest-vectors.json`.

`approval` is decided by the host profile:

- `automatic` — the caller may commit immediately.
- `human` — `commit` fails with `approval_required` (`next_action: await_approval`)
  until a person approves the proposal in the workspace
  (`POST /office/proposals/{id}/decision`). Approval is tied to the digest.

Proposals expire after `limits.proposal_ttl_seconds` (one hour) with
`proposal_expired`.

### Idempotent commit

`command_id` is chosen by the caller and identifies the attempt. Repeating a
commit with the same `command_id` returns the original outcome instead of
applying the change again; reusing it for a different proposal is an
`idempotency_conflict`. When a commit times out or fails with
`temporarily_unavailable`, the client must call `office.status` with the same
`command_id` rather than commit again (`next_action: status`). The
deduplication record is kept for seven days; after that the same `command_id`
yields `idempotency_window_expired` (`next_action: inspect_history`).

### Verification

`verify` re-reads the resource through the owner and reports checks such as
`text_exact`, `marks`, `row_ids`, `formulas`, `calculated_values` and
`calculated_at`. `at_commit` compares against the state recorded at commit,
`current` against the live resource. For sheets, verification can return
`calculation_pending` until the calculation engine has caught up.

## Errors

Errors always carry `code`, `retryable` and `next_action`; the pairs are fixed
by the schema. See [Errors](../reference/errors.md).
