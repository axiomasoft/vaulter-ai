<?php

declare(strict_types=1);

namespace Vaulter\Ai\LaravelAi;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Vaulter\Ai\Core\Enums\OfficeOperation;
use Vaulter\Ai\Core\Exceptions\OfficeException;

/**
 * Schema-only Laravel AI Tool descriptor derived from the authorized ChatTools catalog.
 * Execution outside ChatTurn's leased tool ledger is explicitly rejected.
 */
final readonly class SchemaCatalogTool implements Tool
{
    /**
     * @param  list<string>  $resourceIds
     * @param  list<string>  $commandNames
     */
    public function __construct(
        private OfficeOperation $operation,
        private array $resourceIds = [],
        private array $commandNames = [],
    ) {}

    public function name(): string
    {
        return $this->operation->toolName();
    }

    public function description(): string
    {
        $commands = $this->commandNames === [] ? 'none' : implode(', ', $this->commandNames);
        $base = OfficeToolSchema::descriptionFor($this->operation, $commands);
        if ($this->resourceIds === []) {
            return $base;
        }

        return $base.' Allowed resource_ids: '.implode(', ', $this->resourceIds).'.';
    }

    public function handle(Request $request): string
    {
        throw OfficeException::temporarilyUnavailable();
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return OfficeToolSchema::forOperation($this->operation, $schema);
    }
}
