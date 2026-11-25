# Configuration

Each package merges its own file; publish one with
`php artisan vendor:publish --tag=<tag>` to override it.

## `vaulter-ai` (core, tag `vaulter-ai-config`)

| Key | Env | Default | Meaning |
| --- | --- | --- | --- |
| `database.connection` | `VAULTER_AI_DB_CONNECTION` | default connection | Connection for every Vaulter AI table |
| `database.table_prefix` | `VAULTER_AI_TABLE_PREFIX` | `v_` | Table prefix |
| `route_prefix` | `VAULTER_AI_ROUTE_PREFIX` | `api/vaulter-ai/v1` | Prefix of `POST /operations` |
| `middleware` | — | `['api']` | Middleware of the operation endpoint |
| `workspace_prefix` | `VAULTER_AI_WORKSPACE_PREFIX` | `office` | Prefix of the workspace (review, runs, chat) routes |
| `workspace_middleware` | — | `['web']` | Middleware of the workspace routes |
| `profiles.*` | — | `documents`, `sheets` | See [Profiles](#profiles) |
| `limits.search_default_limit` / `search_max_limit` | — | 5 / 50 | Search page size |
| `limits.search_max_candidates` | — | 500 | Candidates fetched from the directory before ACL filtering |
| `limits.read_default_limit` / `read_max_rows` / `read_max_bytes` | — | 20 / 20 / 16384 | Read slice bounds |
| `limits.prepare_max_targets` | — | 20 | Targets per proposal |
| `limits.read_token_ttl_seconds` | — | 900 | Read token lifetime |
| `limits.search_page_ttl_seconds` | — | 900 | Search cursor lifetime |
| `limits.proposal_ttl_seconds` | — | 3600 | Proposal lifetime |
| `encryption.key_id` | `VAULTER_AI_KEY_ID` | `app-key-v1` | Identifier stored with encrypted payloads |
| `secret_store.default_model` | `VAULTER_AI_MODEL` | — | Model when a connection names none |
| `secret_store.key_version` | `VAULTER_AI_SECRET_KEY_VERSION` | 1 | Active key version for stored provider secrets |
| `secret_store.keys` | — | `[]` | Additional key versions, used by `vaulter-ai:secrets:rotate` |
| `secret_store.connections.office_model.driver` | `VAULTER_AI_PROVIDER` | `openai` | Provider driver |
| `secret_store.connections.office_model.key` | `VAULTER_AI_PROVIDER_KEY`, then `OPENAI_API_KEY` | — | Provider API key |
| `secret_store.connections.office_model.model` | `VAULTER_AI_MODEL` | — | Model |
| `secret_store.connections.office_model.url` | `VAULTER_AI_PROVIDER_URL`, then `OPENAI_BASE_URL` | — | Custom endpoint |
| `chat.max_tool_calls` | — | 32 | Tool calls per chat run |
| `chat.max_output_bytes` | — | 65536 | Assistant output per run |
| `chat.delta_batch_bytes` / `chat.delta_batch_ms` | — | 512 / 100 (not in the published file) | How assistant text is batched into `assistant.delta` events |

Retries and request timeouts of model calls are executor settings; see the
`chat.*` keys of the executor packages below. The profile comes from
`CallContext::$profile`, which your `CallContextResolver` sets.

### Profiles

A profile is what a caller may do. `ConfigHostPolicy` reads it from
`profiles.<CallContext::$profile>`:

```php
'documents' => [
    'executor' => 'laravel-ai',            // registry key of the executor
    'provider_connection' => 'office_model',
    'commands' => ['text.replace_exact@1', 'text.insert_block@1', 'text.delete_block@1'],
    'approval' => 'human',                 // or 'automatic'
    'revision' => '1',                     // optional; bump to invalidate open proposals
],
```

Bind your own `HostPolicy` to decide per resource instead.

## `vaulter-ai-documents` (tag `vaulter-ai-documents-config`)

| Key | Env | Default | Meaning |
| --- | --- | --- | --- |
| `projector_version` | — | 1 | Version of the text projection; bump to force a rebuild |
| `default_tenant_id` | `VAULTER_AI_DOCUMENTS_DEFAULT_TENANT_ID` | — | Tenant for projections built outside a request |
| `default_project_scope` | `VAULTER_AI_DOCUMENTS_DEFAULT_PROJECT_SCOPE` | `default` | Project scope for the same |
| `read.live_fallback_on_lag` | `VAULTER_AI_DOCUMENTS_LIVE_FALLBACK_ON_LAG` | `false` | When the projection lags behind `after_receipt`, read the live document (authorized) instead of failing with `projection_pending` |
| `read.snippet_max_chars` | — | 140 | Search snippet length |
| `read.default_outline_limit` | — | 20 | Outline entries per read |
| `projection.auto_index` | `VAULTER_AI_DOCUMENTS_AUTO_INDEX` | `true` | Index text automatically on Vaulter Documents events |

Rebuild projections with
`php artisan vaulter-ai:documents:rebuild-text-index <drive> [--node=] [--tenant=] [--project=]`.

## `vaulter-ai-sheets` (tag `vaulter-ai-sheets-config`)

| Key | Env | Default |
| --- | --- | --- |
| `default_tenant_id` | `VAULTER_AI_SHEETS_DEFAULT_TENANT_ID`, then the documents variable | — |
| `default_project_scope` | `VAULTER_AI_SHEETS_DEFAULT_PROJECT_SCOPE`, then the documents variable | `default` |

## `vaulter-ai-laravel-ai` (tag `vaulter-ai-laravel-ai-config`)

| Key | Env | Default | Meaning |
| --- | --- | --- | --- |
| `queue` | `VAULTER_AI_AGENT_QUEUE` | `office-agent` | Queue of executor runs |
| `worker_timeout_seconds` | `VAULTER_AI_WORKER_TIMEOUT` | 75 | Upper bound of one run attempt |
| `queue_retry_after_seconds` | `VAULTER_AI_QUEUE_RETRY_AFTER` | 100 | Reference value: set the queue connection's `retry_after` to it; it must exceed the worker timeout |
| `stop_grace_period_seconds` | `VAULTER_AI_STOP_GRACE_PERIOD` | 90 | Reference value for the container's stop grace period (above the worker timeout); not read by the package |
| `tool_timeout_seconds` | `VAULTER_AI_TOOL_TIMEOUT` | 30 | Upper bound of one tool call |
| `tries` | `VAULTER_AI_JOB_TRIES` | 3 | Job attempts |
| `backoff_seconds` | — | `[5, 15, 30]` | Delay between attempts |
| `default_model` | `VAULTER_AI_MODEL` | — | Model |
| `provider_connections.office_model` | `VAULTER_AI_PROVIDER` | `openai` | Laravel AI provider per connection |
| `chat.*` | `VAULTER_AI_CHAT_MAX_RETRIES`, `..._RETRY_BACKOFF_MS`, `..._REQUEST_TIMEOUT`, `..._MAX_OUTPUT_BYTES`, `..._MAX_TOOL_CALLS` | as core | Chat limits for this executor |

## `vaulter-ai-agentools` (tag `vaulter-ai-agentools-config`)

| Key | Env | Default | Meaning |
| --- | --- | --- | --- |
| `channel` | `VAULTER_AI_AGENTOOLS_CHANNEL` | `agentools` | Run channel when the dispatch payload names none |
| `lease_seconds` | `VAULTER_AI_AGENTOOLS_LEASE_SECONDS` | 90 | How long a worker owns a claimed run; must exceed the slowest scenario |
| `chat.*` | same variables as laravel-ai | as core | Chat limits for this executor |
| `chat.max_tokens` | — | 4096 (not in the published file) | Output token cap per model call |
| `providers.<connection>.format` | — | inferred | Wire format of a provider connection: `responses`, `chat_completions` or `anthropic`. Without it the format is inferred from the connection URL, then defaults to `responses` |

`APP_KEY` is not needed to boot the providers; it is needed as soon as a
provider secret is decrypted or a payload is encrypted.
