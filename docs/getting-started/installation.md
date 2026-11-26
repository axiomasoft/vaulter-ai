# Installation

## Requirements

| | |
| --- | --- |
| PHP | `>=8.4 <8.6` |
| Laravel | 13 (`illuminate/*` `^13.0`) |
| Database | PostgreSQL 18 or 19 in production; SQLite is used by the default test run |
| Node.js | `>=26.13.1` for `@vaulter/ai-vue` |
| Python | `>=3.12` for `vaulter-office-client` |

Vaulter AI is a private channel. Packages are published as ZIP (Composer) and
tgz (npm) artifacts listed in `release/packages.json`; configure the private
repository your organisation uses before requiring them. Publish each Vaulter family dependency at the same version as the AI adapter that requires it. The Composer manifests use `self.version`, so a missing matching core, documents, sheets or collaboration release prevents installation.

## Choose packages

| You want | Require |
| --- | --- |
| The protocol, proposals, runs and chat | `axiomasoft/vaulter-ai-core` |
| AI access to Vaulter Documents (text) | `axiomasoft/vaulter-ai-documents` |
| AI access to Vaulter Sheets | `axiomasoft/vaulter-ai-sheets` |
| Live, collaboratively edited text | `axiomasoft/vaulter-ai-collaboration` (with documents) |
| Laravel AI (`laravel/ai`) as the model runtime | `axiomasoft/vaulter-ai-laravel-ai` |
| AgenTools as the model runtime | `axiomasoft/vaulter-ai-agentools` |

At least one owner (documents, sheets) and one executor (laravel-ai,
agentools) are needed for a working assistant. Core alone is enough to serve
the protocol to an external agent through the HTTP endpoint.

```bash
composer require axiomasoft/vaulter-ai-core axiomasoft/vaulter-ai-documents axiomasoft/vaulter-ai-laravel-ai
php artisan vendor:publish --tag=vaulter-ai-config
php artisan migrate
```

All service providers are auto-discovered. Every table is created through the
`vaulter-ai.database.connection` connection with the `vaulter-ai.database.table_prefix`
prefix (`v_` by default).

## Bind the host contracts

Core ships fail-closed defaults. Before the endpoints answer anything but
`authentication_required`, the host must at least bind a
`CallContextResolver` that turns an authenticated request into a
`CallContext` (tenant, project scope, subject, profile):

```php
use Illuminate\Http\Request;
use Vaulter\Ai\Core\Contracts\CallContextResolver;
use Vaulter\Ai\Core\Data\CallContext;

final class AppCallContextResolver implements CallContextResolver
{
    public function resolve(Request $request): ?CallContext
    {
        $user = $request->user();

        return $user === null ? null : new CallContext(
            tenantId: (string) $user->tenant_id,
            projectScope: 'default',
            effectiveSubject: (string) $user->getAuthIdentifier(),
            profile: 'documents',
            actor: $user,
        );
    }
}

// In an application service provider (registered after package providers):
$this->app->singleton(CallContextResolver::class, AppCallContextResolver::class);
```

See [Host integration](../guides/host-integration.md) for the other contracts
(approval policy, background run context, write capability).

## Model provider

The default profile uses the `office_model` provider connection:

```dotenv
VAULTER_AI_PROVIDER=openai
VAULTER_AI_PROVIDER_KEY=sk-...
VAULTER_AI_MODEL=gpt-5
```

Provider keys stay on the server. Per-tenant keys can be stored encrypted in
the database; rotate them with `php artisan vaulter-ai:secrets:rotate`.

## Queue

Runs started by the Laravel AI executor are queued on `VAULTER_AI_AGENT_QUEUE`
(`office-agent` by default) only after the surrounding transaction commits.
Keep the three timeouts ordered, otherwise a slow run is picked up twice:

```text
VAULTER_AI_TOOL_TIMEOUT (30) < VAULTER_AI_WORKER_TIMEOUT (75) < queue retry_after (VAULTER_AI_QUEUE_RETRY_AFTER, 100)
```

```bash
php artisan queue:work --queue=office-agent --timeout=75
```

Every package publishes its configuration under its own tag:
`vaulter-ai-config`, `vaulter-ai-documents-config`, `vaulter-ai-sheets-config`,
`vaulter-ai-laravel-ai-config` and `vaulter-ai-agentools-config`.
