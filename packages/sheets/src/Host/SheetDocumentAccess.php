<?php

declare(strict_types=1);

namespace Vaulter\Ai\Sheets\Host;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Vaulter\Ai\Core\Contracts\ResourceDirectory;
use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Core\Data\ResourceEntry;
use Vaulter\Ai\Core\Enums\ResourceType;
use Vaulter\Ai\Core\Exceptions\OfficeException;
use Vaulter\Drive\Contracts\DriveHandle;
use Vaulter\Drive\Vaulter;

/**
 * Resolves authorized spreadsheet resources and obtains actor-scoped DriveWorkbookOperations.
 */
final readonly class SheetDocumentAccess
{
    public function __construct(
        private Vaulter $vaulter,
        private ResourceDirectory $directory,
    ) {}

    public function resource(CallContext $context, string $resourceId, string $permission = 'view'): ResourceEntry
    {
        $entry = $this->directory->findAccessible($context, $resourceId, $permission);
        if ($entry === null || $entry->resourceType !== ResourceType::Sheet) {
            throw OfficeException::resourceUnavailable();
        }

        return $entry;
    }

    public function canEdit(CallContext $context, string $resourceId): bool
    {
        $entry = $this->directory->findAccessible($context, $resourceId, 'edit');

        return $entry !== null && $entry->resourceType === ResourceType::Sheet;
    }

    public function drive(CallContext $context, ResourceEntry $resource): DriveHandle
    {
        $actor = $context->actor;
        $driveId = $resource->driveId ?? $context->driveId;
        $drive = is_string($driveId) && $driveId !== '' ? $this->vaulter->findDriveById($driveId) : null;
        if (! $actor instanceof Authorizable || $drive === null) {
            throw OfficeException::resourceUnavailable();
        }

        return $drive->actingAs($actor);
    }

    public function workbooks(CallContext $context, ResourceEntry $resource): DriveWorkbookOperations
    {
        return $this->drive($context, $resource)->extension(DriveWorkbookOperations::class);
    }
}
