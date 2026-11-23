<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core\Workspace;

use SensitiveParameter;

/** Holds a possible credential prefix until the next delta disambiguates it. */
final class AssistantTextRedactor
{
    private string $pending = '';

    public function __construct(#[SensitiveParameter] private readonly string $key) {}

    public function push(#[SensitiveParameter] string $chunk): string
    {
        if ($this->key === '') {
            return $chunk;
        }

        $parts = explode($this->key, $this->pending.$chunk);
        $tail = array_pop($parts);
        $safe = $parts === [] ? '' : implode('[REDACTED]', $parts).'[REDACTED]';
        $this->pending = '';
        for ($size = min(strlen($tail), strlen($this->key) - 1); $size > 0; $size--) {
            $suffix = substr($tail, -$size);
            if (str_starts_with($this->key, $suffix)) {
                $this->pending = $suffix;

                return $safe.substr($tail, 0, -$size);
            }
        }

        return $safe.$tail;
    }

    public function finish(): string
    {
        return $this->pending;
    }
}
