# Errors

A failed operation returns `ok: false` and an `error` object:

```json
{ "version": 1, "operation": "office.commit", "ok": false,
  "error": { "code": "approval_required", "retryable": false, "next_action": "await_approval" } }
```

`code`, `retryable` and `next_action` always come together; the combinations
below are fixed by the schema and by `Protocol\WireErrorMapper`. Clients
should branch on `next_action`, not on the HTTP status.

| Code | HTTP | Retryable | `next_action` | Meaning |
| --- | --- | --- | --- | --- |
| `invalid_input` | 400 | no | `stop` | The request does not match the schema or the command's arguments |
| `unsupported_version` | 400 | no | `stop` | `version` is not 1 |
| `authentication_required` | 401 | no | `stop` | No `CallContext` could be resolved |
| `resource_unavailable` | 404 | no | `stop` | The resource does not exist or the caller may not see it (deliberately indistinguishable) |
| `operation_unknown` | 404 | yes | `retry_same` | `office.status` found no commit for this `command_id` (it may still be in flight); retry the identical request |
| `precondition_failed` | 409 | no | `reread` | The expected content no longer matches |
| `scope_changed` | 409 | no | `reread` | The caller's scope or policy changed since the read |
| `generation_changed` | 409 | no | `reread` | The resource changed since the read |
| `schema_changed` | 409 | no | `reread` | The sheet/table structure changed since the read |
| `proposal_expired` | 410 | no | `reread` | The proposal outlived `proposal_ttl_seconds` |
| `cursor_expired` | 410 | no | `reread` | A search or read cursor outlived its TTL |
| `read_token_expired` | 410 | no | `reread` | The read token outlived `read_token_ttl_seconds` |
| `idempotency_conflict` | 409 | no | `stop` | A `command_id` (or chat idempotency key) was reused for a different request |
| `approval_required` | 409 | no | `await_approval` | The proposal needs a human decision first |
| `projection_pending` | 409 | yes | `retry_read` | The text index has not caught up with `after_receipt` yet |
| `calculation_pending` | 409 | yes | `retry_read` | Sheet values have not been recalculated yet |
| `evidence_unavailable` | 410 | no | `inspect_history` | Verification evidence for the receipt is no longer available |
| `idempotency_window_expired` | 410 | no | `inspect_history` | The seven-day deduplication window for `command_id` has passed |
| `limit_exceeded` | 413 | no | `narrow` | The request asks for more than the configured limits allow |
| `unsupported_content` | 422 | no | `stop` | The target contains content the command cannot change safely |
| `temporarily_unavailable` | 503 | yes | `status` for commit, `retry_read` otherwise | Transient failure; after a commit always ask `office.status` |

The workspace endpoints (`/office/...`) use the same codes in their JSON error
bodies.
