<?php

declare(strict_types=1);

namespace Vaulter\Ai\LaravelAi;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Vaulter\Ai\Core\Enums\OfficeOperation;

/**
 * Shared typed JSON Schema definitions for the seven Office SDK v1 operations.
 */
final class OfficeToolSchema
{
    /**
     * @return array<string, Type>
     */
    public static function forOperation(OfficeOperation $operation, JsonSchema $schema): array
    {
        return match ($operation) {
            OfficeOperation::Search => [
                'query' => $schema->string()->description('Search query string.')->required(),
                'resource_type' => $schema->string()->enum(['text', 'sheet'])->description('Optional resource type filter.'),
                'limit' => $schema->integer()->min(1)->description('Maximum number of items per page.'),
                'cursor' => $schema->string()->description('Opaque pagination cursor from a previous search page.'),
            ],
            OfficeOperation::Read => [
                'resource_id' => $schema->string()->min(1)->description('Target resource identifier.')->required(),
                'selector' => $schema->object([
                    'kind' => $schema->string()->enum(['text_outline', 'text_block', 'sheet_catalog', 'sheet_table'])->required(),
                    'query' => $schema->string(),
                    'block_id' => $schema->string()->min(1),
                    'sheet_id' => $schema->string()->min(1),
                    'table_id' => $schema->string()->min(1),
                    'columns' => $schema->array()->items($schema->string()->min(1)),
                    'row_ids' => $schema->array()->items($schema->string()->min(1)),
                ])->description('Typed selector matching the target resource kind.')->required(),
                'limit' => $schema->integer()->min(1),
                'cursor' => $schema->string()->min(1),
                'after_receipt' => $schema->string()->min(1),
            ],
            OfficeOperation::Describe => [
                'resource_id' => $schema->string()->min(1)->description('Target resource identifier.')->required(),
                'command' => $schema->string()->min(1)->description('Versioned command name, e.g. text.replace_exact@1.')->required(),
            ],
            OfficeOperation::Prepare => [
                'resource_id' => $schema->string()->min(1)->description('Target resource identifier.')->required(),
                'command' => $schema->string()->min(1)->description('Versioned command name.')->required(),
                'read_token' => $schema->string()->min(1)->description('Complete-scope read_token from office_read.')->required(),
                'arguments' => $schema->object([
                    'block_id' => $schema->string()->min(1),
                    'expected' => $schema->string()->min(1),
                    'replacement' => $schema->string(),
                    'sheet_id' => $schema->string()->min(1),
                    'table_id' => $schema->string()->min(1),
                    'scope' => $schema->string()->enum(['fixed_members', 'current_rows']),
                    'destination_column_id' => $schema->string()->min(1),
                    'expression' => $schema->object([
                        'op' => $schema->string()->enum(['subtract'])->required(),
                        'left_column_id' => $schema->string()->min(1)->required(),
                        'right_column_id' => $schema->string()->min(1)->required(),
                    ]),
                ])->description('Command arguments matching office_describe input_schema.')->required(),
            ],
            OfficeOperation::Commit => [
                'resource_id' => $schema->string()->min(1)->description('Target resource identifier.')->required(),
                'proposal_id' => $schema->string()->min(1)->description('Prepared proposal_id.')->required(),
                'digest' => $schema->string()->min(64)->max(64)->description('64-hex SHA-256 JCS proposal digest.')->required(),
            ],
            OfficeOperation::Status => [
                'resource_id' => $schema->string()->min(1)->description('Target resource identifier.')->required(),
                'command_id' => $schema->string()->min(1)->description('Host command_id for the submitted command.')->required(),
            ],
            OfficeOperation::Verify => [
                'resource_id' => $schema->string()->min(1)->description('Target resource identifier.')->required(),
                'receipt_id' => $schema->string()->min(1)->description('Committed receipt_id.')->required(),
                'mode' => $schema->string()->enum(['at_commit', 'current'])->description('Verification mode.')->required(),
            ],
        };
    }

    public static function descriptionFor(OfficeOperation $operation, string $commandsSummary): string
    {
        return match ($operation) {
            OfficeOperation::Search => 'Search authorized Office resources by query, optional resource_type, limit, and cursor.',
            OfficeOperation::Read => 'Read an authorized Office resource outline, text block, sheet catalog, or sheet table slice.',
            OfficeOperation::Describe => "Describe input schema and verification profile for an allowed command ({$commandsSummary}).",
            OfficeOperation::Prepare => "Prepare a validated mutation proposal for an allowed command ({$commandsSummary}) using a read_token.",
            OfficeOperation::Commit => 'Commit a prepared proposal by proposal_id and digest using the host-managed idempotency step key.',
            OfficeOperation::Status => 'Inspect the authoritative status of a submitted Office command by resource_id and command_id.',
            OfficeOperation::Verify => 'Verify a committed Office receipt in at_commit or current mode.',
        };
    }
}
