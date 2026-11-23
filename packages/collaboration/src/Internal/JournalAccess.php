<?php

declare(strict_types=1);

namespace Vaulter\Ai\Collaboration\Internal;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Core\Data\ResourceEntry;
use Vaulter\Ai\Core\Enums\ErrorCode;
use Vaulter\Ai\Core\Exceptions\OfficeException;
use Vaulter\Ai\Documents\Host\TextDocumentAccess;
use Vaulter\Collaboration\Persistence\CommandActor;
use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\ValueObjects\Access\Actor;

final readonly class JournalAccess
{
    public function __construct(private TextDocumentAccess $access) {}

    public function actor(CallContext $context, ResourceEntry $resource): CommandActor
    {
        if (! $context->actor instanceof Authorizable) {
            throw OfficeException::authenticationRequired();
        }
        $drive = $this->access->drive($context, $resource)->snapshot();

        return new CommandActor(
            Actor::user($context->actor)->ref(),
            $drive->tenantId,
            $context->projectScope,
            $context->effectiveSubject,
            $drive->id,
            $resource->resourceId,
        );
    }

    public static function translate(ProblemException $failure): OfficeException
    {
        $details = $failure->problemDetails();
        $problemContext = $details['context'] ?? null;
        $reason = is_array($problemContext) && is_string($problemContext['reason'] ?? null)
            ? $problemContext['reason']
            : null;

        return match ($reason) {
            'key_conflict' => new OfficeException(ErrorCode::IdempotencyConflict),
            'key_expired' => new OfficeException(ErrorCode::IdempotencyWindowExpired),
            'schema_changed' => new OfficeException(ErrorCode::SchemaChanged),
            'evidence_unavailable' => new OfficeException(ErrorCode::EvidenceUnavailable),
            'generation_changed' => OfficeException::generationChanged(),
            'projection_behind' => OfficeException::projectionPending(),
            'strict_profile_required' => OfficeException::unsupportedContent(),
            default => match ($failure->httpStatus()) {
                403, 404 => OfficeException::resourceUnavailable(),
                409 => $failure->problemCode() === 'collaboration_epoch_mismatch'
                    ? OfficeException::generationChanged()
                    : OfficeException::temporarilyUnavailable(),
                default => OfficeException::temporarilyUnavailable(),
            },
        };
    }
}
