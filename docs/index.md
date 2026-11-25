# Vaulter AI documentation

Vaulter AI lets a language model read and change Vaulter documents and
spreadsheets through a small, versioned, approval-gated protocol: the
**Office SDK v1**. The model never touches storage directly. It searches and
reads bounded slices, prepares a proposal whose digest pins exactly what will
change, waits for a human (or a policy) to approve it, commits it idempotently
and verifies the result.

| Start here | |
| --- | --- |
| [Installation](getting-started/installation.md) | Packages, requirements, migrations and the minimum host bindings |
| [Architecture](concepts/architecture.md) | Package layering, ports, executors and owners |
| [Office protocol](concepts/office-protocol.md) | The search → read → prepare → commit → verify lifecycle |
| [Host integration](guides/host-integration.md) | Contracts the host implements: authentication, policy, approvals |
| [Configuration](reference/configuration.md) | Every config key and environment variable |
| [HTTP API](reference/http-api.md) | Operation endpoint, workspace endpoints, chat SSE |
| [Errors](reference/errors.md) | Wire error codes, HTTP statuses and the `next_action` contract |
| [Security model](security.md) | Trust boundaries, secrets, approvals and verification |

Client libraries:

- PHP: call `Vaulter\Ai\Core\Contracts\Office::handle()` in-process (jobs and HTTP share it).
- Vue: [`@vaulter/ai-vue`](../packages/vue/README.md) — proposal review and chat panel.
- Python: [`vaulter-office-client`](../clients/python/README.md) — stdlib sync and async client.

The JSON Schema of the wire format lives in
[`packages/core/resources/contracts/office-v1.schema.json`](../packages/core/resources/contracts/office-v1.schema.json)
and is the single source of truth: the PHP server, the Python client tests and the
Vue types are all checked against it.
