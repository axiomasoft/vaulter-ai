# axiomasoft/vaulter-ai-collaboration

Live-text owner. For documents edited collaboratively it registers
`text.replace_exact@1` for the `journal` write mode, so AI changes go through
the Vaulter Collaboration journal and reach every connected editor, and it
reads live text with journal markers.

## Installation

```bash
composer require axiomasoft/vaulter-ai-collaboration
```

`Vaulter\Ai\Collaboration\CollaborationAiServiceProvider` is auto-discovered.

## Notes

- Install it next to `axiomasoft/vaulter-ai-documents`.
- Hosts that register providers manually must register
  `CollaborationAiServiceProvider` together with the Vaulter Collaboration
  provider.

## Documentation

- [Architecture](../../docs/concepts/architecture.md)
- [Configuration](../../docs/reference/configuration.md)
- [Changelog](CHANGELOG.md)

Proprietary. Part of the [Vaulter AI](../../README.md) monorepo.
