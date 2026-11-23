<?php

declare(strict_types=1);

namespace Vaulter\Ai\Collaboration;

use JsonException;
use stdClass;
use Vaulter\Ai\Collaboration\Internal\JournalAccess;
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
use Vaulter\Ai\Documents\Contracts\LiveTextJournal;
use Vaulter\Ai\Documents\Host\TextDocumentAccess;
use Vaulter\Ai\Documents\Writing\TextReplacement;
use Vaulter\Collaboration\Persistence\CommandJournal;
use Vaulter\Exceptions\Contracts\ProblemException;

/** Adapts Office text commands to the strict journal's trusted PHP entry points. */
final readonly class LiveTextCommandOwner implements CommandOwner, LiveTextJournal, TextVerificationEvidence
{
    private JournalAccess $journalAccess;

    private LiveTextReader $reader;

    public function __construct(private CommandJournal $journal, private TextDocumentAccess $access)
    {
        $this->journalAccess = new JournalAccess($access);
        $this->reader = new LiveTextReader($journal, $access);
    }

    public function key(): string
    {
        return 'documents.live_text@1';
    }

    public function supports(string $command, ?string $writeMode = null): bool
    {
        return $command === ReplaceExact::COMMAND && ($writeMode === null || $writeMode === 'journal');
    }

    /**
     * Verify whether a live-journal resource meets the 3.5 strict-profile journal requirement.
     */
    public function supportsLiveJournal(CallContext $context, ResourceEntry $resource, int $generation, int $version): bool
    {
        try {
            return $this->projected($context, $resource, $generation, $version) !== null;
        } catch (OfficeException $exception) {
            return $exception->errorCode === ErrorCode::ProjectionPending;
        }
    }

    public function commit(CommandScope $scope, PreparedPlan $plan): CommandOutcome
    {
        $resource = $this->access->resource($scope->context, $scope->resourceId);
        $actor = $this->journalAccess->actor($scope->context, $resource);
        $condition = $plan->preconditions;
        $schema = $this->requireInt($condition, 'schema');
        $blockId = $this->requireString($condition, 'block_id');
        $expected = $this->requireString($condition, 'expected');
        $replacement = $this->requireString($condition, 'replacement');
        $blockIdentity = $this->requireString($condition, 'block_identity');

        try {
            $expectedBlock = json_decode($blockIdentity, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw OfficeException::preconditionFailed();
        }

        try {
            $record = $this->journal->register(
                $actor,
                $scope->commandId,
                $plan->proposalId,
                $plan->digest,
                $plan->base->generation,
                $schema,
                $plan->base->position,
                [
                    'preconditions' => [[
                        'block_id' => $blockId,
                        'expected_block' => $expectedBlock,
                    ]],
                    'atomic_effects' => [[
                        'effect' => 'replace_exact',
                        'block_id' => $blockId,
                        'expected' => $expected,
                        'replacement' => $replacement,
                    ]],
                ],
            );
        } catch (ProblemException $failure) {
            throw JournalAccess::translate($failure);
        }

        return $this->outcome($scope->commandId, $record);
    }

    public function status(CommandScope $scope): CommandOutcome
    {
        $resource = $this->access->resource($scope->context, $scope->resourceId);
        try {
            return $this->outcome($scope->commandId, $this->journal->status($this->journalAccess->actor($scope->context, $resource), $scope->commandId));
        } catch (ProblemException $failure) {
            throw JournalAccess::translate($failure);
        }
    }

    public function receiptMarker(CallContext $context, ResourceEntry $resource, string $receipt): ?Marker
    {
        return $this->reader->receiptMarker($context, $resource, $receipt);
    }

    public function projected(CallContext $context, ResourceEntry $resource, int $generation, int $version): ?Marker
    {
        return $this->reader->projected($context, $resource, $generation, $version);
    }

    public function verify(CallContext $context, string $resourceId, string $receiptId, string $mode): stdClass
    {
        $resource = $this->access->resource($context, $resourceId);
        [$marker, $range] = $this->evidence($context, $resource, $receiptId);
        $block = $this->requireArray($range, 'block');
        $start = $this->requireInt($range, 'start_utf16');
        $replacement = $this->requireString($range, 'replacement');
        $expected = $this->checks($block, $range, $start);
        $actual = [
            'text' => $expected['text'],
            'textPassed' => $expected['text'] === $replacement,
            'marks' => $expected['marks'] ?? [],
            'marksPassed' => $expected['marks'] !== null,
        ];
        if ($mode === 'current') {
            try {
                $current = $this->journal->verifyCurrent($this->journalAccess->actor($context, $resource), $receiptId);
            } catch (ProblemException $failure) {
                throw JournalAccess::translate($failure);
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
            'receipt_id' => $receiptId,
            'mode' => $mode,
            'marker' => $marker->toStdClass(),
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
        $block = $this->requireArray($range, 'block');
        $start = $this->requireInt($range, 'start_utf16');
        $checks = $this->checks($block, $range, $start);

        /** @var list<string> $marks */
        $marks = $checks['marks'] ?? [];

        return ['text_exact' => $checks['text'], 'marks' => $marks];
    }

    /** @return array{Marker, array<string, mixed>} */
    private function evidence(CallContext $context, ResourceEntry $resource, string $receipt): array
    {
        try {
            $result = $this->journal->evidence($this->journalAccess->actor($context, $resource), $receipt);
        } catch (ProblemException $failure) {
            throw JournalAccess::translate($failure);
        }

        $ranges = $result['evidence']['ranges'] ?? null;
        $range = is_array($ranges) ? ($ranges[0] ?? null) : null;
        if (! is_array($range) || ! is_array($range['block'] ?? null)) {
            throw new OfficeException(ErrorCode::EvidenceUnavailable);
        }

        /** @var array<string, mixed> $typedRange */
        $typedRange = $range;

        return [
            new Marker($this->requireInt($result, 'generation'), $this->requireInt($result, 'to_seq')),
            $typedRange,
        ];
    }

    /**
     * @param  array<string, mixed>  $block
     * @param  array<string, mixed>  $range
     * @return array{text: string, marks: list<string>|null}
     */
    private function checks(array $block, array $range, int $start): array
    {
        [$text, $runs] = TextReplacement::runs($block);
        $length = $this->requireInt($range, 'length_utf16');
        if ($length === 0) {
            return ['text' => '', 'marks' => []];
        }
        $encoded = mb_convert_encoding($text, 'UTF-16LE', 'UTF-8');
        $actual = mb_convert_encoding(substr($encoded, $start * 2, $length * 2), 'UTF-8', 'UTF-16LE');
        foreach ($runs as $run) {
            $from = TextReplacement::length(substr($text, 0, $run['start']));
            $to = TextReplacement::length(substr($text, 0, $run['end']));
            if ($from <= $start && $to >= $start + $length && $run['marks'] !== null) {
                $marks = [];
                foreach ($run['marks'] as $mark) {
                    if (is_string($mark['type'] ?? null)) {
                        $marks[] = $mark['type'];
                    }
                }

                return ['text' => $actual, 'marks' => $marks];
            }
        }

        return ['text' => $actual, 'marks' => null];
    }

    /** @param array<array-key, mixed>|null $record */
    private function outcome(string $command, ?array $record): CommandOutcome
    {
        $state = is_array($record) && is_string($record['state'] ?? null) ? $record['state'] : 'unknown';

        return match ($state) {
            'running' => new CommandOutcome($command, 'running'),
            'committed' => new CommandOutcome(
                $command,
                'committed',
                $this->nullableString($record ?? [], 'receipt_id'),
                new Marker($this->requireInt($record ?? [], 'generation'), $this->requireInt($record ?? [], 'to_seq')),
            ),
            'rejected' => new CommandOutcome($command, 'rejected', reason: match ($record['rejection_code'] ?? null) {
                'precondition_failed' => ErrorCode::PreconditionFailed,
                'generation_changed' => ErrorCode::GenerationChanged,
                'schema_changed' => ErrorCode::SchemaChanged,
                default => ErrorCode::ResourceUnavailable,
            }),
            default => new CommandOutcome($command, 'unknown'),
        };
    }

    /** @param array<array-key, mixed> $source */
    private function requireInt(array $source, string $key): int
    {
        $value = $source[$key] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/\A-?\d+\z/', $value) === 1) {
            return (int) $value;
        }

        throw new OfficeException(ErrorCode::EvidenceUnavailable);
    }

    /** @param array<array-key, mixed> $source */
    private function requireString(array $source, string $key): string
    {
        $value = $source[$key] ?? null;
        if (is_string($value)) {
            return $value;
        }

        throw new OfficeException(ErrorCode::EvidenceUnavailable);
    }

    /** @param array<array-key, mixed> $source */
    private function nullableString(array $source, string $key): ?string
    {
        $value = $source[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * @param  array<array-key, mixed>  $source
     * @return array<string, mixed>
     */
    private function requireArray(array $source, string $key): array
    {
        $value = $source[$key] ?? null;
        if (! is_array($value)) {
            throw new OfficeException(ErrorCode::EvidenceUnavailable);
        }

        /** @var array<string, mixed> $value */
        return $value;
    }
}
