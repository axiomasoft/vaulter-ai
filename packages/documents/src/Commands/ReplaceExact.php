<?php

declare(strict_types=1);

namespace Vaulter\Ai\Documents\Commands;

use stdClass;
use Vaulter\Ai\Core\Contracts\OfficeCommand;
use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Core\Data\CommandDescriptor;
use Vaulter\Ai\Core\Data\PreparedPlan;
use Vaulter\Ai\Core\Data\ReadTokenRecord;
use Vaulter\Ai\Core\Data\ResourceEntry;
use Vaulter\Ai\Core\Enums\ResourceType;
use Vaulter\Ai\Core\Exceptions\OfficeException;

/**
 * Descriptor and stub entry for text.replace_exact@1 (write execution arrives in Task 3.3).
 */
final readonly class ReplaceExact implements OfficeCommand
{
    public const string COMMAND = 'text.replace_exact@1';

    public function descriptor(): CommandDescriptor
    {
        return new CommandDescriptor(
            command: self::COMMAND,
            name: 'text.replace_exact',
            version: 1,
            resourceType: ResourceType::Text,
            effect: 'single_resource_write',
            inputSchema: (object) [
                'type' => 'object',
                'required' => ['block_id', 'expected', 'replacement'],
                'properties' => (object) [
                    'block_id' => (object) ['type' => 'string', 'minLength' => 1],
                    'expected' => (object) ['type' => 'string', 'minLength' => 1],
                    'replacement' => (object) ['type' => 'string'],
                ],
                'additionalProperties' => false,
            ],
            verification: 'text_and_marks',
            requiredPermission: 'edit',
        );
    }

    public function prepare(
        CallContext $context,
        ResourceEntry $resource,
        ReadTokenRecord $token,
        stdClass $arguments,
    ): PreparedPlan {
        throw OfficeException::operationUnknown();
    }
}
