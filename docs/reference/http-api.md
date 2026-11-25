# HTTP API

All endpoints resolve the caller through the bound `CallContextResolver`. When
it returns `null` they answer `401` with `authentication_required`.

## Operation endpoint

`POST /{route_prefix}/operations` (default `/api/vaulter-ai/v1/operations`,
middleware `api`). Named route `vaulter-ai.operations`.

The body is one Office SDK v1 request; the response is the matching success or
failure envelope, with the HTTP status from [Errors](errors.md).

```http
POST /api/vaulter-ai/v1/operations
Content-Type: application/json
Authorization: Bearer …

{"version":1,"operation":"office.read","input":{"resource_id":"doc-1","selector":{"kind":"text_outline"}}}
```

This is the endpoint external agents (and the Python client) use. It never
approves anything: a model can prepare proposals, but only a person deciding
through the workspace can approve a `human` proposal.

## Workspace endpoints

Prefix `/{workspace_prefix}` (default `/office`), middleware `web`. They return
`{"data": …}` JSON. The two POST endpoints that change state also accept
classic form posts: without `Accept: application/json` they answer
`303 See Other` to `redirect_to` when it is a same-host absolute path, otherwise
to the previous page if it is on this application's origin, and to the proposals
list as the last resort.

| Method and path | Route name | Purpose |
| --- | --- | --- |
| `GET /proposals` | `vaulter-ai.workspace.proposals.index` | Proposals the caller may view |
| `GET /proposals/{id}` | `vaulter-ai.workspace.proposals.show` | One proposal with its diff |
| `POST /proposals/{id}/decision` | `vaulter-ai.workspace.proposals.decide` | Approve or reject: `digest`, `approved` (bool) or `decision` (`approve`/`reject`), optional `command_id`, `redirect_to` |
| `GET /runs/{id}` | `vaulter-ai.workspace.runs.show` | An executor run with its steps |
| `POST /runs/{id}/resume` | `vaulter-ai.workspace.runs.resume` | Continue a partial run: optional `arguments_by_resource`, `reread_only`, `redirect_to` |
| `POST /chat/sessions` | `vaulter-ai.workspace.chat.open` | Open a chat session: optional `retention_seconds` |
| `GET /chat/sessions/{id}` | `vaulter-ai.workspace.chat.show` | Messages and runs of a session |
| `POST /chat/sessions/{id}/messages` | `vaulter-ai.workspace.chat.messages` | Post a user message; answers `202` with the `run_id`. Repeating the same idempotency key with the same content returns the same run, with different content `409` |
| `GET /chat/sessions/{id}/events` | `vaulter-ai.workspace.chat.events` | Server-Sent Events stream |

The decision is bound to the proposal `digest`: approving a digest that no
longer matches the stored proposal fails, so a reviewer always approves the
exact change they saw.

## Chat event stream

`GET /office/chat/sessions/{id}/events` answers `text/event-stream`. Each frame
carries the event position as `id`, its type as `event` and a JSON `data`
object:

```text
id: 42
event: assistant.delta
data: {…}
```

Event types include `assistant.delta`, `assistant.interrupted`,
`tool.requested`, `approval.required`, `tool.committed`, `tool.verified` and
`run.finished`. Resume after a disconnect with the `Last-Event-ID` header (or
`?cursor=`); when the cursor is no longer available the server sends
`event: reset` and the client must reload the session.

A stream lasts about 25 seconds, sends a `: keep-alive` comment every 12
seconds and then ends; `EventSource` reconnects on its own with
`Last-Event-ID`. The caller is re-authorized on every poll, so revoking access
stops the stream within one poll interval (200 ms). Each open stream holds one
PHP worker, so size the web server pool for the number of concurrently open
chat panels.
