<?php

declare(strict_types=1);

namespace Vaulter\Ai\Documents\Writing;

use Illuminate\Contracts\Auth\Access\Authorizable;
use stdClass;
use Vaulter\Ai\Core\Contracts\CommandOwner;
use Vaulter\Ai\Core\Contracts\TextVerificationEvidence;
use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Core\Data\CommandOutcome;
use Vaulter\Ai\Core\Data\CommandScope;
use Vaulter\Ai\Core\Data\Marker;
use Vaulter\Ai\Core\Data\PreparedPlan;
use Vaulter\Ai\Core\Data\ResourceEntry;
use Vaulter\Ai\Core\Enums\ErrorCode;
use Vaulter\Ai\Core\Exceptions\OfficeException;
use Vaulter\Ai\Documents\Commands\ReplaceExact;
use Vaulter\Ai\Documents\Host\TextDocumentAccess;
use Vaulter\Collaboration\Persistence\CommandActor;
use Vaulter\Collaboration\Persistence\CommandJournal;
use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\ValueObjects\Access\Actor;

/** Adapts Office text commands to the strict journal's trusted PHP entry points. */
final readonly class LiveTextOwner implements CommandOwner, TextVerificationEvidence
{
    public function __construct(private CommandJournal $journal, private TextDocumentAccess $access) {}

    public function key(): string
    {
        return 'documents.live_text@1';
    }

    public function supports(string $command, ?string $writeMode = null): bool
    {
        return $command === ReplaceExact::COMMAND && ($writeMode === null || $writeMode === 'journal');
    }

    public function commit(CommandScope $scope, PreparedPlan $plan): CommandOutcome
    {
        $resource = $this->access->resource($scope->context, $scope->resourceId);
        $actor = $this->actor($scope->context, $resource);
        $condition = $plan->preconditions;
        try {
            $record = $this->journal->register(
                $actor, $scope->commandId, $plan->proposalId, $plan->digest,
                $plan->base->generation, (int) $condition['schema'], $plan->base->position,
                [
                    'preconditions' => [[
                        'block_id' => $condition['block_id'],
                        'expected_block' => json_decode($condition['block_identity'], true, flags: JSON_THROW_ON_ERROR),
                    ]],
                    'atomic_effects' => [[
                        'effect' => 'replace_exact', 'block_id' => $condition['block_id'],
                        'expected' => $condition['expected'], 'replacement' => $condition['replacement'],
                    ]],
                ],
            );
        } catch (ProblemException $failure) {
            throw $this->translate($failure);
        }

        return $this->outcome($scope->commandId, $record);
    }

    public function status(CommandScope $scope): CommandOutcome
    {
        $resource = $this->access->resource($scope->context, $scope->resourceId);
        try {
            return $this->outcome($scope->commandId, $this->journal->status($this->actor($scope->context, $resource), $scope->commandId));
        } catch (ProblemException $failure) {
            throw $this->translate($failure);
        }
    }

    public function receiptMarker(CallContext $context, ResourceEntry $resource, string $receipt): ?Marker
    {
        try {
            $marker = $this->journal->receiptMarker($this->actor($context, $resource), $receipt);
        } catch (ProblemException $failure) {
            throw $this->translate($failure);
        }

        return $marker === null ? null : new Marker($marker['generation'], $marker['position']);
    }

    public function projected(CallContext $context, ResourceEntry $resource, int $generation, int $version): ?Marker
    {
        try {
            $marker = $this->journal->projected($this->actor($context, $resource), $generation, $version);
        } catch (ProblemException $failure) {
            throw $this->translate($failure);
        }

        return $marker === null ? null : new Marker($marker['generation'], $marker['position']);
    }

    public function verify(CallContext $context, string $resourceId, string $receiptId, string $mode): stdClass
    {
        $resource = $this->access->resource($context, $resourceId);
        [$marker, $range] = $this->evidence($context, $resource, $receiptId);
        $expected = $this->checks($range['block'], $range, $range['start_utf16']);
        $actual = [
            'text' => $expected['text'], 'textPassed' => $expected['text'] === $range['replacement'],
            'marks' => $expected['marks'] ?? [], 'marksPassed' => $expected['marks'] !== null,
        ];
        if ($mode === 'current') {
            try {
                $current = $this->journal->verifyCurrent($this->actor($context, $resource), $receiptId);
            } catch (ProblemException $failure) {
                throw $this->translate($failure);
            }
            if ($current === null) {
                $snapshot = $this->access->content($context, $resource);
                if ($snapshot->epoch !== $marker->generation) {
                    throw OfficeException::generationChanged();
                }
                $projected = $this->projected($context, $resource, $snapshot->epoch, $snapshot->stateVersion);
                if ($projected?->position !== $marker->position) {
                    throw OfficeException::projectionPending();
                }
            } else {
                $marker = new Marker($current['generation'], $current['position']);
                $actual = $current;
            }
        }

        return (object) [
            'receipt_id' => $receiptId, 'mode' => $mode, 'marker' => $marker->toStdClass(),
            'outcome' => $actual['textPassed'] && $actual['marksPassed'] ? 'passed' : 'failed',
            'checks' => [
                (object) ['name' => 'text_exact', 'passed' => $actual['textPassed'], 'actual' => $actual['text']],
                (object) ['name' => 'marks', 'passed' => $actual['marksPassed'], 'actual' => $actual['marks']],
            ],
        ];
    }

    public function expectedTextChecks(CallContext $context, string $resourceId, string $receiptId, Marker $marker): array
    {
        [$recorded, $range] = $this->evidence($context, $this->access->resource($context, $resourceId), $receiptId);
        if ($recorded->generation !== $marker->generation || $recorded->position !== $marker->position) {
            throw new OfficeException(ErrorCode::EvidenceUnavailable);
        }
        $checks = $this->checks($range['block'], $range, $range['start_utf16']);

        return ['text_exact' => $checks['text'], 'marks' => $checks['marks'] ?? []];
    }

    /** @return array{Marker, array<string, mixed>} */
    private function evidence(CallContext $context, ResourceEntry $resource, string $receipt): array
    {
        try {
            $result = $this->journal->evidence($this->actor($context, $resource), $receipt);
        } catch (ProblemException $failure) {
            throw $this->translate($failure);
        }
        $range = $result['evidence']['ranges'][0] ?? null;
        if (! is_array($range) || ! is_array($range['block'] ?? null)) {
            throw new OfficeException(ErrorCode::EvidenceUnavailable);
        }

        return [new Marker($result['generation'], $result['to_seq']), $range];
    }

    /** @param array<string, mixed> $block @param array<string, mixed> $range @return array{text: string, marks: list<string>|null} */
    private function checks(array $block, array $range, int $start): array
    {
        [$text, $runs] = TextReplacement::runs($block);
        $length = $range['length_utf16'];
        if ($length === 0) {
            return ['text' => '', 'marks' => []];
        }
        $encoded = mb_convert_encoding($text, 'UTF-16LE', 'UTF-8');
        $actual = mb_convert_encoding(substr($encoded, $start * 2, $length * 2), 'UTF-8', 'UTF-16LE');
        foreach ($runs as $run) {
            $from = TextReplacement::length(substr($text, 0, $run['start']));
            $to = TextReplacement::length(substr($text, 0, $run['end']));
            if ($from <= $start && $to >= $start + $length && $run['marks'] !== null) {
                return ['text' => $actual, 'marks' => array_values(array_column($run['marks'], 'type'))];
            }
        }

        return ['text' => $actual, 'marks' => null];
    }

    private function actor(CallContext $context, ResourceEntry $resource): CommandActor
    {
        if (! $context->actor instanceof Authorizable) {
            throw OfficeException::authenticationRequired();
        }
        $drive = $this->access->drive($context, $resource)->snapshot();

        return new CommandActor(Actor::user($context->actor)->ref(), $drive->tenantId, $context->projectScope,
            $context->effectiveSubject, $drive->id, $resource->resourceId);
    }

    /** @param array<string, mixed>|null $record */
    private function outcome(string $command, ?array $record): CommandOutcome
    {
        return match ($record['state'] ?? 'unknown') {
            'running' => new CommandOutcome($command, 'running'),
            'committed' => new CommandOutcome($command, 'committed', $record['receipt_id'], new Marker($record['generation'], $record['to_seq'])),
            'rejected' => new CommandOutcome($command, 'rejected', reason: match ($record['rejection_code']) {
                'precondition_failed' => ErrorCode::PreconditionFailed,
                'generation_changed' => ErrorCode::GenerationChanged,
                'schema_changed' => ErrorCode::SchemaChanged,
                default => ErrorCode::ResourceUnavailable,
            }),
            default => new CommandOutcome($command, 'unknown'),
        };
    }

    private function translate(ProblemException $failure): OfficeException
    {
        $reason = $failure->problemDetails()['context']['reason'] ?? null;

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
                    ? OfficeException::generationChanged() : OfficeException::temporarilyUnavailable(),
                default => OfficeException::temporarilyUnavailable(),
            },
        };
    }
}
