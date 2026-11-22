<?php

declare(strict_types=1);

namespace Vaulter\Ai\Documents\Writing;

use DateTimeImmutable;
use Illuminate\Support\Str;
use stdClass;
use Vaulter\Ai\Core\Contracts\Clock;
use Vaulter\Ai\Core\Contracts\CommandOwner;
use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Core\Data\CommandOutcome;
use Vaulter\Ai\Core\Data\CommandScope;
use Vaulter\Ai\Core\Data\Marker;
use Vaulter\Ai\Core\Data\PreparedPlan;
use Vaulter\Ai\Core\Enums\ErrorCode;
use Vaulter\Ai\Core\Exceptions\OfficeException;
use Vaulter\Ai\Core\Protocol\RequestFingerprint;
use Vaulter\Ai\Documents\Commands\ReplaceExact;
use Vaulter\Ai\Documents\Host\TextDocumentAccess;
use Vaulter\Ai\Documents\Persistence\TextCommands;
use Vaulter\Documents\Content\TipTap\TipTapContent;
use Vaulter\Documents\Contracts\LockedDocumentMutation;
use Vaulter\Documents\Exceptions\DocumentContentDegradedException;
use Vaulter\Documents\Exceptions\DocumentStateTooLargeException;
use Vaulter\Documents\Exceptions\DocumentTypeUnavailableException;
use Vaulter\Documents\Exceptions\InvalidDocumentContentException;
use Vaulter\Documents\Types\DocumentTypeRegistry;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Events\EventRecorder;
use Vaulter\ValueObjects\Events\EventScope;

final readonly class SingleTextOwner implements CommandOwner
{
    public const string KEY = 'documents.single_text@1';

    public function __construct(
        private TextDocumentAccess $access,
        private TextCommands $commands,
        private TextVerifier $verifier,
        private DocumentTypeRegistry $types,
        private EventRecorder $events,
        private Clock $clock,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function supports(string $command, ?string $writeMode = null): bool
    {
        return $command === ReplaceExact::COMMAND && ($writeMode === null || $writeMode === 'snapshot');
    }

    public function commit(CommandScope $scope, PreparedPlan $plan): CommandOutcome
    {
        $resource = $this->access->resource($scope->context, $scope->resourceId);
        try {
            return $this->access->drive($scope->context, $resource)->extension(DriveDocumentMutation::class)->run(
                $scope->resourceId,
                fn (LockedDocumentMutation $locked, DriveContext $drive): CommandOutcome => $this->decideLocked($locked, $drive, $scope, $plan),
            );
        } catch (NodeNotFoundException) {
            throw OfficeException::resourceUnavailable();
        } catch (DocumentContentDegradedException|DocumentTypeUnavailableException|InvalidDocumentContentException) {
            throw OfficeException::unsupportedContent();
        } catch (DocumentStateTooLargeException) {
            throw OfficeException::limitExceeded();
        }
    }

    private function decideLocked(LockedDocumentMutation $locked, DriveContext $drive, CommandScope $scope, PreparedPlan $plan): CommandOutcome
    {
        $db = $locked->connection();
        $fingerprint = RequestFingerprint::of($plan->command, $plan->proposalId, $plan->digest);
        $existing = $this->commands->outcome($db, $scope, $plan->proposalId, $fingerprint);
        if ($existing !== null) {
            return $existing;
        }

        $state = $locked->snapshot();
        $conditions = $plan->preconditions;
        $type = $this->types->find($state->type);

        try {
            if ($conditions === [] || new DateTimeImmutable($plan->expiresAt) <= $this->clock->now()) {
                throw new OfficeException(ErrorCode::ProposalExpired);
            }
            if ($state->writeMode !== 'snapshot' || $state->writeMode !== ($conditions['write_mode'] ?? null)
                || $state->generation !== $plan->base->generation) {
                throw OfficeException::generationChanged();
            }
            if ($state->type !== ($conditions['type'] ?? null) || $state->schemaVersion !== ($conditions['schema'] ?? null)
                || $type?->schemaVersion() !== $state->schemaVersion) {
                throw OfficeException::schemaChanged();
            }
            if ($state->pending || $state->position < $plan->base->position) {
                throw OfficeException::projectionPending();
            }
            if (! $state->content instanceof TipTapContent) {
                throw OfficeException::unsupportedContent();
            }
            $blockId = $conditions['block_id'] ?? null;
            $expected = $conditions['expected'] ?? null;
            $replacement = $conditions['replacement'] ?? null;
            if (! is_string($blockId) || ! is_string($expected) || $expected === '' || ! is_string($replacement)) {
                throw OfficeException::preconditionFailed();
            }

            $document = $state->content->toArray();
            [$path, $block] = TextReplacement::find($document, $blockId);
            if (TextReplacement::identity($block) !== ($conditions['block_identity'] ?? null)) {
                throw OfficeException::preconditionFailed('target_changed');
            }
            $target = TextReplacement::target($block, $expected);
            $applied = TextReplacement::apply($document, $path, $block, $target, $expected, $replacement);
            if ($applied['evidence']['start_utf16'] !== ($conditions['start_utf16'] ?? null)
                || TextReplacement::length($expected) !== ($conditions['length_utf16'] ?? null)) {
                throw OfficeException::preconditionFailed('target_changed');
            }
            $content = $type->codec()->decode($applied['document'], $state->schemaVersion);
        } catch (OfficeException $failure) {
            if ($failure->errorCode === ErrorCode::ProjectionPending) {
                throw $failure;
            }
            $outcome = new CommandOutcome($scope->commandId, 'rejected', reason: $failure->errorCode);
            $this->commands->record($db, $scope, $plan->proposalId, $fingerprint, $state->documentId, $outcome);

            return $outcome;
        }

        $after = $locked->replace($content, $state->position);
        $receipt = (string) Str::uuid7();
        $marker = new Marker($after->generation, $after->position);
        $outcome = new CommandOutcome($scope->commandId, 'committed', $receipt, $marker);
        $this->commands->record($db, $scope, $plan->proposalId, $fingerprint, $after->documentId, $outcome);
        [, $committedBlock] = TextReplacement::find($after->content->toArray(), $blockId);
        $this->verifier->record($db, $scope, $receipt, $marker, $applied['evidence'] + [
            'block_id' => $blockId,
            'block' => $committedBlock,
        ]);
        $this->events->record(new TextCommandCommitted(
            new EventScope($drive->operation->tenant->id, $drive->operation->scope->id, $scope->context->profile),
            $scope->resourceId, $scope->commandId, $receipt, $marker, $drive,
        ));

        return $outcome;
    }

    public function status(CommandScope $scope): CommandOutcome
    {
        $this->access->resource($scope->context, $scope->resourceId);

        return $this->commands->findOutcome($scope) ?? new CommandOutcome($scope->commandId, 'running');
    }

    public function verify(CallContext $context, string $resourceId, string $receiptId, string $mode): stdClass
    {
        return $this->verifier->verify($context, $resourceId, $receiptId, $mode);
    }
}
