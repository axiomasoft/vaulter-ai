<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core\Data;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use JsonSerializable;

/**
 * Server-enforced node protection policy for hidden and read-only document nodes.
 *
 * @phpstan-type AttributeRule array{attribute: string, values?: list<mixed>, node_types?: list<string>}
 */
final readonly class ContentProtectionPolicy implements JsonSerializable
{
    /**
     * @param  list<string>  $hiddenTypes
     * @param  list<string>  $readOnlyTypes
     * @param  list<AttributeRule>  $hiddenAttributes
     * @param  list<AttributeRule>  $readOnlyAttributes
     */
    public function __construct(
        public array $hiddenTypes = [],
        public array $readOnlyTypes = [],
        public array $hiddenAttributes = [],
        public array $readOnlyAttributes = [],
    ) {}

    public static function none(): self
    {
        return new self;
    }

    /**
     * @param  array<array-key, mixed>  $config
     */
    public static function fromArray(array $config): self
    {
        $hiddenTypes = self::stringList($config['hidden_types'] ?? $config['hiddenTypes'] ?? []);
        $readOnlyTypes = self::stringList($config['read_only_types'] ?? $config['readOnlyTypes'] ?? []);
        $hiddenAttributes = self::attributeRules($config['hidden_attributes'] ?? $config['hiddenAttributes'] ?? []);
        $readOnlyAttributes = self::attributeRules($config['read_only_attributes'] ?? $config['readOnlyAttributes'] ?? []);

        $rules = $config['rules'] ?? null;
        if (is_array($rules)) {
            foreach ($rules as $rule) {
                if (! is_array($rule)) {
                    continue;
                }
                $mode = $rule['mode'] ?? null;
                if ($mode !== 'hidden' && $mode !== 'read_only') {
                    continue;
                }
                $nodeTypes = self::stringList($rule['node_types'] ?? (isset($rule['node_type']) ? [$rule['node_type']] : []));
                $attr = is_string($rule['attribute'] ?? null) ? $rule['attribute'] : (is_string($rule['attr'] ?? null) ? $rule['attr'] : null);
                if ($attr !== null && $attr !== '') {
                    $entry = ['attribute' => $attr];
                    if (array_key_exists('values', $rule) && is_array($rule['values'])) {
                        $entry['values'] = array_values($rule['values']);
                    } elseif (array_key_exists('value', $rule)) {
                        $entry['values'] = [$rule['value']];
                    }
                    if ($nodeTypes !== []) {
                        $entry['node_types'] = $nodeTypes;
                    }
                    if ($mode === 'hidden') {
                        $hiddenAttributes[] = $entry;
                    } else {
                        $readOnlyAttributes[] = $entry;
                    }
                } elseif ($nodeTypes !== []) {
                    if ($mode === 'hidden') {
                        array_push($hiddenTypes, ...$nodeTypes);
                    } else {
                        array_push($readOnlyTypes, ...$nodeTypes);
                    }
                }
            }
        }

        return new self(
            hiddenTypes: array_values(array_unique($hiddenTypes)),
            readOnlyTypes: array_values(array_unique($readOnlyTypes)),
            hiddenAttributes: $hiddenAttributes,
            readOnlyAttributes: $readOnlyAttributes,
        );
    }

    /**
     * Resolve effective policy monotonically from config, profile, resource metadata, context attributes, and override.
     */
    public static function resolve(
        ?CallContext $context = null,
        ?ResourceEntry $resource = null,
        ?self $override = null,
    ): self {
        $policy = self::none();

        $container = Container::getInstance();
        if ($container->bound('config')) {
            $config = $container->make(ConfigRepository::class);
            $global = $config->get('vaulter-ai.content_protection');
            if (is_array($global)) {
                $policy = $policy->merge(self::fromArray($global));
            }
            if ($context !== null) {
                $profileConfig = $config->get("vaulter-ai.profiles.{$context->profile}.content_protection");
                if (is_array($profileConfig)) {
                    $policy = $policy->merge(self::fromArray($profileConfig));
                }
            }
        }

        if ($resource !== null) {
            $resourcePolicy = $resource->metadata['content_protection'] ?? null;
            if ($resourcePolicy instanceof self) {
                $policy = $policy->merge($resourcePolicy);
            } elseif (is_array($resourcePolicy)) {
                $policy = $policy->merge(self::fromArray($resourcePolicy));
            }
        }

        if ($context !== null) {
            $contextPolicy = $context->attributes['content_protection'] ?? null;
            if ($contextPolicy instanceof self) {
                $policy = $policy->merge($contextPolicy);
            } elseif (is_array($contextPolicy)) {
                $policy = $policy->merge(self::fromArray($contextPolicy));
            }
        }

        if ($override !== null) {
            $policy = $policy->merge($override);
        }

        return $policy;
    }

    public function merge(self $other): self
    {
        if ($other->isEmpty()) {
            return $this;
        }
        if ($this->isEmpty()) {
            return $other;
        }

        return new self(
            hiddenTypes: array_values(array_unique([...$this->hiddenTypes, ...$other->hiddenTypes])),
            readOnlyTypes: array_values(array_unique([...$this->readOnlyTypes, ...$other->readOnlyTypes])),
            hiddenAttributes: [...$this->hiddenAttributes, ...$other->hiddenAttributes],
            readOnlyAttributes: [...$this->readOnlyAttributes, ...$other->readOnlyAttributes],
        );
    }

    public function isEmpty(): bool
    {
        return $this->hiddenTypes === []
            && $this->readOnlyTypes === []
            && $this->hiddenAttributes === []
            && $this->readOnlyAttributes === [];
    }

    public function mayMatchContainers(): bool
    {
        if ($this->isEmpty()) {
            return false;
        }
        if ($this->hiddenAttributes !== [] || $this->readOnlyAttributes !== []) {
            return true;
        }

        foreach ([...$this->hiddenTypes, ...$this->readOnlyTypes] as $type) {
            if (! in_array($type, ['paragraph', 'heading'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $node
     * @return 'editable'|'read_only'|'hidden'
     */
    public function modeForNode(array $node): string
    {
        if ($this->isEmpty()) {
            return 'editable';
        }

        $type = is_string($node['type'] ?? null) ? $node['type'] : '';
        $attrs = is_array($node['attrs'] ?? null) ? $node['attrs'] : [];

        if ($type !== '' && in_array($type, $this->hiddenTypes, true)) {
            return 'hidden';
        }
        foreach ($this->hiddenAttributes as $rule) {
            if ($this->matchesAttributeRule($type, $attrs, $rule)) {
                return 'hidden';
            }
        }

        if ($type !== '' && in_array($type, $this->readOnlyTypes, true)) {
            return 'read_only';
        }
        foreach ($this->readOnlyAttributes as $rule) {
            if ($this->matchesAttributeRule($type, $attrs, $rule)) {
                return 'read_only';
            }
        }

        return 'editable';
    }

    /**
     * @param  list<array<string, mixed>>  $ancestorAndSelfNodes
     * @return 'editable'|'read_only'|'hidden'
     */
    public function modeForPath(array $ancestorAndSelfNodes): string
    {
        if ($this->isEmpty()) {
            return 'editable';
        }

        $effective = 'editable';
        foreach ($ancestorAndSelfNodes as $node) {
            $mode = $this->modeForNode($node);
            if ($mode === 'hidden') {
                return 'hidden';
            }
            if ($mode === 'read_only') {
                $effective = 'read_only';
            }
        }

        return $effective;
    }

    /**
     * @param  array<string, mixed>  $node
     */
    public function canReadNode(array $node): bool
    {
        return $this->modeForNode($node) !== 'hidden';
    }

    /**
     * @param  list<array<string, mixed>>  $ancestorAndSelfNodes
     */
    public function canReadPath(array $ancestorAndSelfNodes): bool
    {
        return $this->modeForPath($ancestorAndSelfNodes) !== 'hidden';
    }

    /**
     * @param  array<string, mixed>  $node
     */
    public function canWriteNode(array $node): bool
    {
        return $this->modeForNode($node) === 'editable';
    }

    /**
     * @param  list<array<string, mixed>>  $ancestorAndSelfNodes
     */
    public function canWritePath(array $ancestorAndSelfNodes): bool
    {
        return $this->modeForPath($ancestorAndSelfNodes) === 'editable';
    }

    /**
     * @return array{
     *     hidden_types: list<string>,
     *     read_only_types: list<string>,
     *     hidden_attributes: list<AttributeRule>,
     *     read_only_attributes: list<AttributeRule>,
     *     summary: string,
     * }
     */
    public function describe(): array
    {
        $parts = [];
        if ($this->hiddenTypes !== []) {
            $parts[] = 'Hidden node types: '.implode(', ', $this->hiddenTypes);
        }
        if ($this->readOnlyTypes !== []) {
            $parts[] = 'Read-only node types (cannot be modified): '.implode(', ', $this->readOnlyTypes);
        }
        if ($this->hiddenAttributes !== []) {
            $attrs = array_map(static fn (array $r): string => $r['attribute'], $this->hiddenAttributes);
            $parts[] = 'Hidden node attributes: '.implode(', ', array_unique($attrs));
        }
        if ($this->readOnlyAttributes !== []) {
            $attrs = array_map(static fn (array $r): string => $r['attribute'], $this->readOnlyAttributes);
            $parts[] = 'Read-only node attributes (cannot be modified): '.implode(', ', array_unique($attrs));
        }

        return [
            'hidden_types' => $this->hiddenTypes,
            'read_only_types' => $this->readOnlyTypes,
            'hidden_attributes' => $this->hiddenAttributes,
            'read_only_attributes' => $this->readOnlyAttributes,
            'summary' => $parts === [] ? 'No content protection restrictions.' : implode('; ', $parts),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->describe();
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * @param  array<array-key, mixed>  $attrs
     * @param  AttributeRule  $rule
     */
    private function matchesAttributeRule(string $type, array $attrs, array $rule): bool
    {
        $nodeTypes = $rule['node_types'] ?? null;
        if ($nodeTypes !== null && $nodeTypes !== [] && ($type === '' || ! in_array($type, $nodeTypes, true))) {
            return false;
        }

        $attr = $rule['attribute'];
        if (! array_key_exists($attr, $attrs)) {
            return false;
        }

        $actual = $attrs[$attr];
        if (array_key_exists('values', $rule)) {
            return in_array($actual, $rule['values'], true);
        }

        return $actual === true;
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $item) {
            if (is_string($item) && $item !== '') {
                $out[] = $item;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @return list<AttributeRule>
     */
    private static function attributeRules(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $item) {
            if (! is_array($item)) {
                continue;
            }
            $attr = $item['attribute'] ?? $item['attr'] ?? null;
            if (! is_string($attr) || $attr === '') {
                continue;
            }
            $rule = ['attribute' => $attr];
            if (array_key_exists('values', $item) && is_array($item['values'])) {
                $rule['values'] = array_values($item['values']);
            } elseif (array_key_exists('value', $item)) {
                $rule['values'] = [$item['value']];
            }
            $nodeTypes = self::stringList($item['node_types'] ?? (isset($item['node_type']) ? [$item['node_type']] : []));
            if ($nodeTypes !== []) {
                $rule['node_types'] = $nodeTypes;
            }
            $out[] = $rule;
        }

        return $out;
    }
}
