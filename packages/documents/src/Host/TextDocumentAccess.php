<?php

declare(strict_types=1);

namespace Vaulter\Ai\Documents\Host;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Core\Data\ResourceEntry;
use Vaulter\Ai\Core\Exceptions\OfficeException;
use Vaulter\Documents\Data\Snapshots\DocumentSnapshot;
use Vaulter\Documents\DocumentsManager;
use Vaulter\Documents\Exceptions\DocumentContentDegradedException;
use Vaulter\Documents\Exceptions\DocumentStatePendingException;
use Vaulter\Documents\Exceptions\DocumentTypeUnavailableException;
use Vaulter\Drive\Contracts\DriveHandle;
use Vaulter\Drive\Exceptions\Access\PermissionDeniedException;
use Vaulter\Drive\Exceptions\DriveArchivedException;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Drive\Vaulter;

final readonly class TextDocumentAccess
{
    public function __construct(
        private Vaulter $vaulter,
        private DocumentsManager $documents,
        private VaulterResourceDirectory $directory,
    ) {}

    public function resource(CallContext $context, string $resourceId): ResourceEntry
    {
        return $this->directory->findAccessible($context, $resourceId) ?? throw OfficeException::resourceUnavailable();
    }

    public function drive(CallContext $context, ResourceEntry $resource): DriveHandle
    {
        $actor = $context->actor;
        $driveId = $resource->driveId ?? $context->driveId;
        $drive = $driveId !== null ? $this->vaulter->findDriveById($driveId) : null;
        if (! $actor instanceof Authorizable || $drive === null) {
            throw OfficeException::resourceUnavailable();
        }

        return $drive->actingAs($actor);
    }

    public function content(CallContext $context, ResourceEntry $resource): DocumentSnapshot
    {
        try {
            return $this->documents->in($this->drive($context, $resource))->content($resource->resourceId);
        } catch (NodeNotFoundException|PermissionDeniedException|DriveArchivedException) {
            throw OfficeException::resourceUnavailable();
        } catch (DocumentStatePendingException) {
            throw OfficeException::projectionPending();
        } catch (DocumentTypeUnavailableException|DocumentContentDegradedException) {
            throw OfficeException::unsupportedContent();
        }
    }
}
