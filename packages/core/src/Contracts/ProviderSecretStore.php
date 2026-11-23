<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core\Contracts;

use Vaulter\Ai\Core\Data\ProviderSecret;

/**
 * Server-side provider secret resolver. Raw API keys must never cross into browser bundles,
 * SSE frames, LLM prompts, database transcripts, or logs.
 */
interface ProviderSecretStore
{
    public function resolve(string $connection): ProviderSecret;

    /** @return array{configured:bool, connection:string, driver:?string, model:?string, hint:?string} */
    public function status(?string $connection): array;

    public function maskText(string $text): string;
}
