<?php

declare(strict_types=1);

namespace Vaulter\Ai\Documents\Host;

use Vaulter\Ai\Core\Contracts\CommandWriteCapability;
use Vaulter\Ai\Core\Contracts\ResourceDirectory;
use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Core\Data\CommandDescriptor;
use Vaulter\Ai\Core\Data\ResourceEntry;
use Vaulter\Ai\Core\Enums\ResourceType;
use Vaulter\Ai\Core\Exceptions\OfficeException;
use Vaulter\Ai\Core\Persistence\RegistryCommandWriteCapability;
use Vaulter\Ai\Core\Registry\OfficeRegistry;
use Vaulter\Ai\Documents\Writing\DriveDocumentMutation;
use Vaulter\Ai\Documents\Writing\LiveTextOwner;
use Vaulter\Documents\Contracts\LockedDocumentMutation;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;

/**
 * Enforces 3.5 strict-profile journal readiness before exposing or executing text write tools
 * against resources operating in live journal mode.
 */
final readonly class DocumentsCommandWriteCapability implements CommandWriteCapability
{
    private RegistryCommandWriteCapability $fallback;

    public function __construct(
        private OfficeRegistry $registry,
        private TextDocumentAccess $access,
        private ?LiveTextOwner $live = null,
        ?RegistryCommandWriteCapability $fallback = null,
        private ?ResourceDirectory $directory = null,
    ) {
        $this->fallback = $fallback ?? new RegistryCommandWriteCapability($this->registry);
    }

    public function canWrite(CallContext $context, ResourceEntry $resource, CommandDescriptor $descriptor): bool
    {
        if ($this->directory !== null && ! $this->directory instanceof VaulterResourceDirectory) {
            return $this->fallback->canWrite($context, $resource, $descriptor);
        }

        if ($resource->resourceType !== ResourceType::Text) {
            return $this->fallback->canWrite($context, $resource, $descriptor);
        }

        if ($resource->writeMode === 'snapshot') {
            return $this->registry->ownerFor($descriptor->command, 'snapshot') !== null;
        }

        try {
            $lockedState = $this->access->drive($context, $resource)
                ->extension(DriveDocumentMutation::class)
                ->run($resource->resourceId, static function (LockedDocumentMutation $locked): array {
                    $snap = $locked->snapshot();

                    return [
                        'writeMode' => $snap->writeMode,
                        'generation' => $snap->generation,
                        'position' => $snap->position,
                    ];
                });
        } catch (NodeNotFoundException|OfficeException) {
            return false;
        }

        $writeMode = $lockedState['writeMode'];
        if ($writeMode !== 'journal') {
            return $this->registry->ownerFor($descriptor->command, $writeMode) !== null;
        }

        $owner = $this->registry->ownerFor($descriptor->command, 'journal');
        if ($owner === null) {
            return false;
        }

        if ($owner instanceof LiveTextOwner) {
            return $owner->supportsLiveJournal($context, $resource, $lockedState['generation'], $lockedState['position']);
        }

        if ($this->live !== null) {
            return $this->live->supportsLiveJournal($context, $resource, $lockedState['generation'], $lockedState['position']);
        }

        return true;
    }
}
