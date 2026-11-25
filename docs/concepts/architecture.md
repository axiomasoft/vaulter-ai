# Architecture

## Packages and layering

```text
                 ┌────────────────────────── executors ─────────────────────────┐
                 │  vaulter-ai-laravel-ai (laravel/ai)  vaulter-ai-agentools     │
                 └──────────────────────────────┬───────────────────────────────┘
                                                │ OfficeExecutor, LlmPort
┌───────────────────────────────────────────────▼──────────────────────────────┐
│ vaulter-ai-core: Office SDK v1, registry, proposals, runs, chat, HTTP, schema │
└───────────────▲──────────────────────▲──────────────────────▲────────────────┘
                │ ResourceReader,      │                      │
                │ OfficeCommand,       │                      │
                │ CommandOwner         │                      │
┌───────────────┴────────┐  ┌──────────┴─────────┐  ┌─────────┴──────────────────┐
│ vaulter-ai-documents   │◄─┤ vaulter-ai-        │  │ vaulter-ai-sheets          │
│ (Vaulter Documents)    │  │ collaboration      │  │ (Vaulter Sheets)           │
└────────────────────────┘  └────────────────────┘  └────────────────────────────┘
```

- **Core** defines the protocol and every port. It depends on Laravel components
  only; it knows nothing about Vaulter storage or a model SDK.
- **Owners** (documents, sheets, collaboration) adapt one kind of resource. They
  register readers, commands and command owners into the `OfficeRegistry`.
- **Executors** (laravel-ai, agentools) run the model loop in the background
  and talk to the Office SDK through the same `Office` entry point as HTTP
  clients do. Neither executor depends on an owner.

The graph is enforced by `tests/Arch/LayeringTest.php`; a package may only use
namespaces its own `composer.json` provides, so every package keeps working
when installed alone.

## The registry

Owners register their pieces during `boot()`:

| Registration | Contract | Example |
| --- | --- | --- |
| `registerReader()` | `ResourceReader` — bounded reads for one `ResourceType` | `TextReader`, `SheetReader` |
| `registerCommand()` | `OfficeCommand` — validates arguments and prepares a plan | `ReplaceExact`, `FillFormula` |
| `registerOwner($command, $writeMode, $owner)` | `CommandOwner` — commits, reports status, verifies | `SingleTextOwner` (`snapshot`), `LiveTextCommandOwner` (`journal`) |
| `registerLazyExecutor($key, $factory)` | `OfficeExecutor` — background runtime | `laravel-ai`, `agentools` |

A command can have one owner per *write mode*. Documents commits text as a
`snapshot`; collaboration registers the same `text.replace_exact@1` command for
the `journal` mode, so live documents are changed through the collaboration
journal instead of overwriting the snapshot.

Executors are registered lazily: they are built on first lookup, not while
providers boot. Their dependency chain reaches the provider secret store and
the encrypter, and building it eagerly would make every artisan command —
including `key:generate` and static analysis — require an `APP_KEY`.

## Commands in v1

| Command | Owner package | Effect |
| --- | --- | --- |
| `text.replace_exact@1` | documents, collaboration | Replace a block's text when it still equals the expected read |
| `text.insert_block@1` | documents | Insert a paragraph or heading before/after an anchor block |
| `text.delete_block@1` | documents | Delete one block |
| `sheet.fill_formula@1` | sheets | Fill a table column with a formula for fixed rows or the current rows |

A host profile decides which commands a caller may use (see
[configuration](../reference/configuration.md#profiles)).

## Runs and chat

- An **executor run** is a background model loop for one request. It is
  persisted step by step (`Runs`, `OfficeRunStep`), so an interrupted or
  partially applied run can be inspected and resumed from the workspace
  (`POST /office/runs/{id}/resume`).
- A **chat session** stores user and assistant messages and an append-only
  event log that is streamed to the browser over Server-Sent Events. A browser
  that reconnects passes `Last-Event-ID` and receives only what it missed.
- Tool calls made by the model are recorded in a ledger in the same
  transaction that publishes them, so a retried job never applies the same
  tool call twice.

## Data

All tables are created by the packages' migrations on the configured
connection and prefix. Proposals, read tokens and search pages are short-lived
and expire by TTL (`vaulter-ai.limits.*`). Payloads that may contain document
content (read-token dependencies, proposal payloads) are encrypted at rest
with AES-256-GCM, bound to their table, scope and record identity as associated
data (`PayloadCipher`), so a ciphertext copied to another row does not decrypt.
