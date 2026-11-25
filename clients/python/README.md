# vaulter-office-client

Thin Python client for the Vaulter AI Office SDK v1 and the workspace
endpoints. Standard library only, fully typed (`py.typed`), sync and async.

```bash
pip install vaulter-office-client     # from the private channel
```

Python 3.12 or newer.

## Usage

```python
from vaulter_office import OfficeClient

client = OfficeClient(
    "https://app.example.com/api/vaulter-ai/v1/operations",
    credentials_provider=lambda: {"Authorization": f"Bearer {token()}"},
    timeout=10.0,
)

hits = client.search("termination clause", resource_type="text")
doc = hits["result"]["items"][0]["resource_id"]
outline = client.read(doc, {"kind": "text_outline"})
proposal = client.prepare(
    doc,
    "text.replace_exact@1",
    outline["result"]["read_token"],
    {
        "block_id": "p42",
        "expected": "…",
        "replacement": "…",
    },
)
outcome = client.commit(doc, proposal["result"]["proposal_id"], proposal["result"]["digest"])
```

Every method returns the decoded response envelope (`{"ok": true, "result": …}`
or `{"ok": false, "error": …}`); domain errors are values, not exceptions.
`OfficeProtocolError` is raised only when the transport or the response is
broken: connection failures, timeouts, redirects, non-JSON bodies.

- `credentials_provider` is called for every request, so tokens can rotate.
  It must return headers; the client never stores credentials.
- Only `http` and `https` endpoints are accepted, and redirects are never
  followed, so credentials cannot leak to another host.
- The workspace endpoint (`/office`) is derived from the operations URL; pass
  `workspace_endpoint=` when the host uses another prefix.

### Commit retries

`commit()` generates a `command_id` when none is given and retries transient
failures up to `max_retries` times with exponential backoff. After a failure
that may have reached the server it asks `office.status` with the same
`command_id` first and only re-sends the commit when the server has no record
of it, so a change is never applied twice.

### Async

`AsyncOfficeClient` has the same methods as coroutines. It runs the blocking
transport in a thread and binds the credentials of each call to that call, so
concurrent calls for different tenants never share headers.

### Workspace and chat

`list_proposals`, `get_proposal`, `decide_proposal`, `get_run`, `resume_run`,
`open_chat_session`, `get_chat_session`, `post_chat_message` and
`stream_chat_events` (a generator of decoded SSE events that resumes from a
cursor) cover the `/office/...` endpoints.

## Development

```bash
uv sync --group dev
ruff check . && ruff format --check .
mypy
python -m unittest discover -s tests
```

The tests run against an in-process HTTP server and need no network.
Proprietary. Part of the [Vaulter AI](../../README.md) monorepo.
