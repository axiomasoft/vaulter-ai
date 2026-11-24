<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core\Schema;

use Closure;
use JsonSerializable;
use Vaulter\Ai\Core\Data\ContentProtectionPolicy;

/**
 * Versioned JSON serializer contract for getEditorContext(): serialized ProseMirror/TipTap schema
 * (without DOM hooks) plus protection-filtered document items.
 */
final readonly class EditorContextSchema implements JsonSerializable
{
    public const string VERSION = 'vaulter.ai.editor-context/v1';

    public const int SCHEMA_VERSION = 1;

    private const array STRIPPED_KEYS = [
        'parseDOM',
        'toDOM',
        'parseHTML',
        'renderHTML',
        'renderText',
        'nodeView',
        'addNodeView',
        'addProseMirrorPlugins',
        'addKeyboardShortcuts',
        'addInputRules',
        'addPasteRules',
        'addCommands',
    ];

    /**
     * @param  array{nodes: array<string, array<string, mixed>>, marks: array<string, array<string, mixed>>}  $serializedSchema
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, mixed>|null  $contentProtection
     */
    public function __construct(
        public string $version,
        public int $schemaVersion,
        public array $serializedSchema,
        public array $items = [],
        public ?array $contentProtection = null,
    ) {}

    /**
     * @param  array<string, array<string, mixed>>  $nodeSpecs
     * @param  array<string, array<string, mixed>>  $markSpecs
     * @param  list<array<string, mixed>>  $items
     */
    public static function fromSpecs(
        array $nodeSpecs,
        array $markSpecs,
        ?ContentProtectionPolicy $protection = null,
        array $items = [],
    ): self {
        $nodes = [];
        foreach ($nodeSpecs as $name => $spec) {
            if ($name === '') {
                continue;
            }
            $clean = self::sanitizeSpec($spec);
            $clean['name'] = $name;
            if (isset($spec['attrs']) && is_array($spec['attrs'])) {
                $clean['attrs'] = self::normalizeAttributes($spec['attrs']);
            }
            if ($protection !== null && ! $protection->isEmpty()) {
                $clean['protection'] = $protection->modeForNode(['type' => $name, 'attrs' => []]);
            }
            $nodes[$name] = $clean;
        }

        $marks = [];
        foreach ($markSpecs as $name => $spec) {
            if ($name === '') {
                continue;
            }
            $clean = self::sanitizeSpec($spec);
            $clean['name'] = $name;
            if (isset($spec['attrs']) && is_array($spec['attrs'])) {
                $clean['attrs'] = self::normalizeAttributes($spec['attrs']);
            }
            $marks[$name] = $clean;
        }

        $protectionData = $protection !== null && ! $protection->isEmpty() ? $protection->describe() : null;

        return new self(
            version: self::VERSION,
            schemaVersion: self::SCHEMA_VERSION,
            serializedSchema: [
                'nodes' => $nodes,
                'marks' => $marks,
            ],
            items: $items,
            contentProtection: $protectionData,
        );
    }

    /**
     * @return array{
     *     version: string,
     *     schema_version: int,
     *     serializedSchema: array{nodes: array<string, array<string, mixed>>, marks: array<string, array<string, mixed>>},
     *     items: list<array<string, mixed>>,
     *     contentProtection?: array<string, mixed>,
     * }
     */
    public function toArray(): array
    {
        $payload = [
            'version' => $this->version,
            'schema_version' => $this->schemaVersion,
            'serializedSchema' => $this->serializedSchema,
            'items' => $this->items,
        ];

        if ($this->contentProtection !== null) {
            $payload['contentProtection'] = $this->contentProtection;
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return array<string, mixed>
     */
    private static function sanitizeSpec(array $spec): array
    {
        $clean = [];
        foreach ($spec as $key => $value) {
            if (in_array($key, self::STRIPPED_KEYS, true)) {
                continue;
            }
            if ($value instanceof Closure || is_object($value) && ! $value instanceof JsonSerializable) {
                continue;
            }
            if (is_array($value)) {
                $clean[$key] = self::sanitizeArray($value);

                continue;
            }
            $clean[$key] = $value;
        }

        return $clean;
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private static function sanitizeArray(array $values): array
    {
        $clean = [];
        foreach ($values as $key => $value) {
            if (is_string($key) && in_array($key, self::STRIPPED_KEYS, true)) {
                continue;
            }
            if ($value instanceof Closure || is_object($value) && ! $value instanceof JsonSerializable) {
                continue;
            }
            $clean[$key] = is_array($value) ? self::sanitizeArray($value) : $value;
        }

        return $clean;
    }

    /**
     * @param  array<array-key, mixed>  $attrs
     * @return array<string, array<string, mixed>>
     */
    private static function normalizeAttributes(array $attrs): array
    {
        $normalized = [];
        foreach ($attrs as $name => $definition) {
            if (! is_string($name) || $name === '') {
                continue;
            }
            if (! is_array($definition)) {
                $normalized[$name] = ['type' => 'string', 'nullable' => true];

                continue;
            }
            $entry = [];
            $entry['type'] = is_string($definition['type'] ?? null) ? $definition['type'] : 'string';
            if (array_key_exists('default', $definition) && ! $definition['default'] instanceof Closure) {
                $entry['default'] = $definition['default'];
            }
            if (array_key_exists('nullable', $definition)) {
                $entry['nullable'] = (bool) $definition['nullable'];
            }
            if (isset($definition['enum']) && is_array($definition['enum'])) {
                $entry['enum'] = array_values($definition['enum']);
            }
            if (is_string($definition['description'] ?? null)) {
                $entry['description'] = $definition['description'];
            }
            $normalized[$name] = $entry;
        }

        return $normalized;
    }
}
