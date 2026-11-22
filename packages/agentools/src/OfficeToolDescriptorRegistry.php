<?php

declare(strict_types=1);

namespace Vaulter\Ai\AgenTools;

use AgenTools\Contracts\ToolDescriptorRegistry;
use AgenTools\Tool\ActionProfile;
use AgenTools\Tool\ToolAnnotations;
use AgenTools\Tool\ToolDescriptor;
use Vaulter\Ai\Core\Contracts\HostPolicy;
use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Core\Data\ResourceEntry;
use Vaulter\Ai\Core\Enums\OfficeOperation;

/**
 * Exposes validated AgenTools ToolDescriptor metadata for the seven Office SDK v1 operations,
 * keeping MCP annotations and dev.agentools/action profiles aligned with Office side effects.
 */
final readonly class OfficeToolDescriptorRegistry implements ToolDescriptorRegistry
{
    /** @var array<string, ToolDescriptor> */
    private array $descriptors;

    public function __construct(
        private ?HostPolicy $policy = null,
        private ?CallContext $context = null,
        private ?ResourceEntry $resource = null,
    ) {
        $this->descriptors = $this->buildDescriptors();
    }

    public function forContext(CallContext $context, ?ResourceEntry $resource = null): self
    {
        return new self($this->policy, $context, $resource);
    }

    public function descriptor(string $toolName): ?ToolDescriptor
    {
        $canonical = OfficeOperation::fromToolName($toolName);
        if ($canonical === null) {
            return null;
        }

        $key = $canonical->toolName();
        if (! isset($this->descriptors[$key])) {
            return null;
        }

        if ($this->context !== null && $this->policy !== null
            && in_array($canonical, [OfficeOperation::Prepare, OfficeOperation::Commit], true)) {
            $commands = $this->resource !== null
                ? $this->policy->allowedCommands($this->context, $this->resource)
                : $this->policy->profile($this->context)->commands;
            if ($commands === []) {
                return null;
            }
        }

        return $this->descriptors[$key];
    }

    /**
     * @return list<ToolDescriptor>
     */
    public function all(): array
    {
        $items = [];
        foreach (OfficeOperation::cases() as $operation) {
            $descriptor = $this->descriptor($operation->toolName());
            if ($descriptor !== null) {
                $items[] = $descriptor;
            }
        }

        return $items;
    }

    /**
     * @return array<string, ToolDescriptor>
     */
    private function buildDescriptors(): array
    {
        $idSchema = ['type' => 'string', 'minLength' => 1];
        $markerSchema = [
            'type' => 'object',
            'required' => ['generation', 'position'],
            'properties' => [
                'generation' => ['type' => 'integer', 'minimum' => 0],
                'position' => ['type' => 'integer', 'minimum' => 0],
            ],
            'additionalProperties' => false,
        ];

        return [
            OfficeOperation::Search->toolName() => new ToolDescriptor(
                name: OfficeOperation::Search->toolName(),
                description: "Purpose: Search authorized Office resources.\nCall when: You need to locate a contract, document, or sheet ID by title or text.\nRequired arguments: query.\nExample: {\"query\":\"договор\",\"resource_type\":\"text\",\"limit\":5}",
                inputSchema: [
                    'type' => 'object',
                    'required' => ['query'],
                    'properties' => [
                        'query' => ['type' => 'string'],
                        'resource_type' => ['type' => 'string', 'enum' => ['text', 'sheet']],
                        'limit' => ['type' => 'integer', 'minimum' => 1],
                        'cursor' => $idSchema,
                    ],
                    'additionalProperties' => false,
                ],
                actionProfile: new ActionProfile(
                    sideEffect: 'read',
                    confirmation: 'none',
                    idempotentCommit: null,
                    permissionsHint: ['view'],
                    contentOrigin: 'untrusted_external',
                ),
                annotations: new ToolAnnotations(true, false, true, false),
                title: 'Office Search',
                outputSchema: [
                    'type' => 'object',
                    'required' => ['items', 'truncated', 'next_cursor'],
                    'properties' => [
                        'items' => ['type' => 'array'],
                        'truncated' => ['type' => 'boolean'],
                        'next_cursor' => ['type' => ['string', 'null']],
                    ],
                    'additionalProperties' => false,
                ],
            ),
            OfficeOperation::Read->toolName() => new ToolDescriptor(
                name: OfficeOperation::Read->toolName(),
                description: "Purpose: Read an authorized Office outline, block, or table slice.\nCall when: You have a resource_id and need addressable text or sheet contents plus a read_token.\nRequired arguments: resource_id, selector.\nExample: {\"resource_id\":\"doc-1\",\"selector\":{\"kind\":\"text_block\",\"block_id\":\"p42\"}}",
                inputSchema: [
                    'type' => 'object',
                    'required' => ['resource_id', 'selector'],
                    'properties' => [
                        'resource_id' => $idSchema,
                        'selector' => ['type' => 'object'],
                        'limit' => ['type' => 'integer', 'minimum' => 1],
                        'cursor' => $idSchema,
                        'after_receipt' => $idSchema,
                    ],
                    'additionalProperties' => false,
                ],
                actionProfile: new ActionProfile(
                    sideEffect: 'read',
                    confirmation: 'none',
                    idempotentCommit: null,
                    permissionsHint: ['view'],
                    contentOrigin: 'untrusted_external',
                ),
                annotations: new ToolAnnotations(true, false, true, false),
                title: 'Office Read',
                outputSchema: [
                    'type' => 'object',
                    'required' => ['marker', 'read_token', 'data', 'truncated', 'next_cursor'],
                    'properties' => [
                        'marker' => $markerSchema,
                        'read_token' => $idSchema,
                        'data' => ['type' => 'object'],
                        'truncated' => ['type' => 'boolean'],
                        'next_cursor' => ['type' => ['string', 'null']],
                    ],
                    'additionalProperties' => false,
                ],
            ),
            OfficeOperation::Describe->toolName() => new ToolDescriptor(
                name: OfficeOperation::Describe->toolName(),
                description: "Purpose: Inspect a versioned Office command schema and verification profile.\nCall when: You need exact argument rules for a command on a resource.\nRequired arguments: resource_id, command.\nExample: {\"resource_id\":\"doc-1\",\"command\":\"text.replace_exact@1\"}",
                inputSchema: [
                    'type' => 'object',
                    'required' => ['resource_id', 'command'],
                    'properties' => [
                        'resource_id' => $idSchema,
                        'command' => $idSchema,
                    ],
                    'additionalProperties' => false,
                ],
                actionProfile: new ActionProfile(
                    sideEffect: 'read',
                    confirmation: 'none',
                    idempotentCommit: null,
                    permissionsHint: ['view'],
                ),
                annotations: new ToolAnnotations(true, false, true, false),
                title: 'Office Describe',
                outputSchema: [
                    'type' => 'object',
                    'required' => ['command', 'effect', 'input_schema', 'verification'],
                    'properties' => [
                        'command' => $idSchema,
                        'effect' => ['type' => 'string'],
                        'input_schema' => ['type' => 'object'],
                        'verification' => ['type' => 'string'],
                    ],
                    'additionalProperties' => false,
                ],
            ),
            OfficeOperation::Prepare->toolName() => new ToolDescriptor(
                name: OfficeOperation::Prepare->toolName(),
                description: "Purpose: Prepare an immutable proposal and digest without mutating content.\nCall when: You hold a complete-scope read_token and verified target arguments.\nRequired arguments: resource_id, command, read_token, arguments.\nExample: {\"resource_id\":\"doc-1\",\"command\":\"text.replace_exact@1\",\"read_token\":\"rt-1\",\"arguments\":{\"block_id\":\"p42\",\"expected\":\"15\",\"replacement\":\"30\"}}",
                inputSchema: [
                    'type' => 'object',
                    'required' => ['resource_id', 'command', 'read_token', 'arguments'],
                    'properties' => [
                        'resource_id' => $idSchema,
                        'command' => $idSchema,
                        'read_token' => $idSchema,
                        'arguments' => ['type' => 'object'],
                    ],
                    'additionalProperties' => false,
                ],
                actionProfile: new ActionProfile(
                    sideEffect: 'draft',
                    confirmation: 'none',
                    idempotentCommit: null,
                    permissionsHint: ['edit'],
                ),
                annotations: new ToolAnnotations(false, false, false, false),
                title: 'Office Prepare',
                outputSchema: [
                    'type' => 'object',
                    'required' => ['proposal_id', 'digest', 'base', 'approval', 'summary', 'targets', 'expires_at'],
                    'properties' => [
                        'proposal_id' => $idSchema,
                        'digest' => ['type' => 'string', 'minLength' => 64, 'maxLength' => 64],
                        'base' => $markerSchema,
                        'approval' => ['type' => 'string', 'enum' => ['automatic', 'human']],
                        'summary' => ['type' => 'string'],
                        'targets' => ['type' => 'array', 'items' => $idSchema],
                        'expires_at' => ['type' => 'string'],
                    ],
                    'additionalProperties' => false,
                ],
            ),
            OfficeOperation::Commit->toolName() => new ToolDescriptor(
                name: OfficeOperation::Commit->toolName(),
                description: "Purpose: Commit a prepared Office proposal under an idempotent step key.\nCall when: A proposal is prepared and any required human approval has been granted.\nRequired arguments: resource_id, proposal_id, digest.\nExample: {\"resource_id\":\"doc-1\",\"proposal_id\":\"019a...\",\"digest\":\"0123...\"}",
                inputSchema: [
                    'type' => 'object',
                    'required' => ['resource_id', 'proposal_id', 'digest'],
                    'properties' => [
                        'resource_id' => $idSchema,
                        'proposal_id' => $idSchema,
                        'digest' => ['type' => 'string', 'minLength' => 64, 'maxLength' => 64],
                        'command_id' => $idSchema,
                    ],
                    'additionalProperties' => false,
                ],
                actionProfile: new ActionProfile(
                    sideEffect: 'write',
                    confirmation: 'confirm',
                    idempotentCommit: true,
                    permissionsHint: ['edit'],
                ),
                annotations: new ToolAnnotations(false, false, true, false),
                title: 'Office Commit',
                outputSchema: [
                    'type' => 'object',
                    'required' => ['command_id', 'state'],
                    'properties' => [
                        'command_id' => $idSchema,
                        'state' => ['type' => 'string', 'enum' => ['running', 'committed']],
                        'receipt_id' => $idSchema,
                        'marker' => $markerSchema,
                    ],
                    'additionalProperties' => false,
                ],
            ),
            OfficeOperation::Status->toolName() => new ToolDescriptor(
                name: OfficeOperation::Status->toolName(),
                description: "Purpose: Query authoritative command status after a crash or ambiguous response.\nCall when: Checking whether a submitted command_id committed, is running, or was rejected.\nRequired arguments: resource_id, command_id.\nExample: {\"resource_id\":\"doc-1\",\"command_id\":\"cmd-1\"}",
                inputSchema: [
                    'type' => 'object',
                    'required' => ['resource_id', 'command_id'],
                    'properties' => [
                        'resource_id' => $idSchema,
                        'command_id' => $idSchema,
                    ],
                    'additionalProperties' => false,
                ],
                actionProfile: new ActionProfile(
                    sideEffect: 'read',
                    confirmation: 'none',
                    idempotentCommit: null,
                    permissionsHint: ['view'],
                ),
                annotations: new ToolAnnotations(true, false, true, false),
                title: 'Office Status',
            ),
            OfficeOperation::Verify->toolName() => new ToolDescriptor(
                name: OfficeOperation::Verify->toolName(),
                description: "Purpose: Verify a committed receipt at commit time or against current state.\nCall when: Confirming postconditions of a committed Office mutation.\nRequired arguments: resource_id, receipt_id, mode.\nExample: {\"resource_id\":\"doc-1\",\"receipt_id\":\"rcpt-1\",\"mode\":\"at_commit\"}",
                inputSchema: [
                    'type' => 'object',
                    'required' => ['resource_id', 'receipt_id', 'mode'],
                    'properties' => [
                        'resource_id' => $idSchema,
                        'receipt_id' => $idSchema,
                        'mode' => ['type' => 'string', 'enum' => ['at_commit', 'current']],
                    ],
                    'additionalProperties' => false,
                ],
                actionProfile: new ActionProfile(
                    sideEffect: 'read',
                    confirmation: 'none',
                    idempotentCommit: null,
                    permissionsHint: ['view'],
                    contentOrigin: 'untrusted_external',
                ),
                annotations: new ToolAnnotations(true, false, true, false),
                title: 'Office Verify',
            ),
        ];
    }
}
