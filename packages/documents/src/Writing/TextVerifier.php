<?php

declare(strict_types=1);

namespace Vaulter\Ai\Documents\Writing;

use Illuminate\Database\Connection;
use RuntimeException;
use stdClass;
use Vaulter\Ai\Core\Contracts\Clock;
use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Core\Data\CommandScope;
use Vaulter\Ai\Core\Data\Marker;
use Vaulter\Ai\Core\Enums\ErrorCode;
use Vaulter\Ai\Core\Exceptions\OfficeException;
use Vaulter\Ai\Core\Persistence\BinaryExpression;
use Vaulter\Ai\Core\Persistence\OfficeDatabase;
use Vaulter\Ai\Core\Persistence\PayloadCipher;
use Vaulter\Ai\Core\Persistence\QueryRecord;
use Vaulter\Ai\Documents\Host\TextDocumentAccess;
use Vaulter\Ai\Documents\Persistence\TextCommands;
use Vaulter\Ai\Documents\Projection\RichTextBlockExtractor;
use Vaulter\Configuration\DatabaseOptions;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Content\TipTap\TipTapContent;

final readonly class TextVerifier
{
    private const string PROFILE = 'text_and_marks';

    public function __construct(
        private TextDocumentAccess $access,
        private TextCommands $commands,
        private VaulterDatabase $owner,
        private DatabaseOptions $tables,
        private OfficeDatabase $db,
        private PayloadCipher $cipher,
        private Clock $clock,
    ) {}

    /** @param array<string, mixed> $evidence */
    public function record(Connection $connection, CommandScope $scope, string $receiptId, Marker $marker, array $evidence): void
    {
        $encrypted = $this->cipher->encrypt($evidence, $this->aad($scope->context, $scope->resourceId, $receiptId, $marker));
        $driver = $connection->getDriverName();
        $connection->table($this->table())->insert([
            'receipt_id' => $receiptId,
            'profile' => self::PROFILE,
            'key_id' => $encrypted['key_id'],
            'encrypted_evidence' => BinaryExpression::forDriver($driver, $encrypted['ciphertext']),
            'expires_at' => $this->db->utcTimestamp($this->clock->now()->modify('+24 hours'), $driver),
        ]);
    }

    public function verify(CallContext $context, string $resourceId, string $receiptId, string $mode): stdClass
    {
        $resource = $this->access->resource($context, $resourceId);
        $receipt = $this->commands->findCommittedReceipt($context, $resourceId, $receiptId)
            ?? throw OfficeException::resourceUnavailable();
        $marker = $receipt['marker'];
        $evidence = $this->load($context, $resourceId, $receiptId, $marker);
        $blockId = $evidence['block_id'] ?? null;
        if (! is_string($blockId)) {
            throw new OfficeException(ErrorCode::EvidenceUnavailable);
        }

        $block = TextReplacement::node($evidence['block'] ?? null);
        if ($mode === 'current') {
            $snapshot = $this->access->content($context, $resource);
            if ($snapshot->epoch > $marker->generation) {
                throw OfficeException::generationChanged();
            }
            if ($snapshot->epoch < $marker->generation || $snapshot->stateVersion < $marker->position) {
                throw OfficeException::temporarilyUnavailable();
            }
            if ((string) $receipt['document_id'] !== $snapshot->documentId) {
                throw OfficeException::resourceUnavailable();
            }
            if (! $snapshot->content instanceof TipTapContent) {
                throw OfficeException::unsupportedContent();
            }
            $marker = new Marker($snapshot->epoch, $snapshot->stateVersion);
            try {
                [, $block] = TextReplacement::find($snapshot->content->toArray(), $blockId);
            } catch (OfficeException $failure) {
                if ($failure->errorCode !== ErrorCode::PreconditionFailed) {
                    throw $failure;
                }
                $block = [];
            }
        }

        $checks = $this->checks($block, $evidence);

        return (object) [
            'receipt_id' => $receiptId,
            'mode' => $mode,
            'outcome' => $checks[0]->passed && $checks[1]->passed ? 'passed' : 'failed',
            'marker' => $marker->toStdClass(),
            'checks' => $checks,
        ];
    }

    /**
     * @param  array<string, mixed>  $block
     * @param  array<string, mixed>  $evidence
     * @return array{object{name: string, passed: bool, actual: string}, object{name: string, passed: bool, actual: list<string>}}
     */
    private function checks(array $block, array $evidence): array
    {
        $start = $evidence['start_utf16'] ?? null;
        $length = $evidence['length_utf16'] ?? null;
        if (! is_int($start) || $start < 0 || ! is_int($length) || $length < 0) {
            throw new OfficeException(ErrorCode::EvidenceUnavailable);
        }
        [$text, $runs] = TextReplacement::runs($block);
        $encoded = mb_convert_encoding($text, 'UTF-16LE', 'UTF-8');
        $actualText = mb_convert_encoding(substr($encoded, 2 * $start, 2 * $length), 'UTF-8', 'UTF-16LE');
        $expected = RichTextBlockExtractor::markSignature(TextReplacement::nodes($evidence['marks'] ?? null));
        $marksPass = $block !== [] && ($length === 0 || $start + $length <= intdiv(strlen($encoded), 2));
        $actualMarks = [];
        $runEnd = 0;
        foreach ($runs as $run) {
            $runStart = $runEnd;
            $runEnd += TextReplacement::length(substr($text, $run['start'], $run['end'] - $run['start']));
            if ($length === 0 || $runEnd <= $start || $runStart >= $start + $length) {
                continue;
            }
            $marks = $run['marks'];
            $marksPass = $marksPass && $marks !== null && RichTextBlockExtractor::markSignature($marks) === $expected;
            foreach ($marks ?? [] as $mark) {
                $name = $mark['type'] ?? null;
                if (is_string($name) && $name !== '') {
                    $actualMarks[$name] = $name;
                }
            }
        }

        return [
            (object) [
                'name' => 'text_exact',
                'passed' => $block !== [] && $text === ($evidence['committed_text'] ?? null) && $actualText === ($evidence['replacement'] ?? null),
                'actual' => $actualText,
            ],
            (object) ['name' => 'marks', 'passed' => $marksPass, 'actual' => array_values($actualMarks)],
        ];
    }

    /** @return array<string, mixed> */
    private function load(CallContext $context, string $resourceId, string $receiptId, Marker $marker): array
    {
        $row = $this->owner->connection()->table($this->table())->where('receipt_id', $receiptId)
            ->where('profile', self::PROFILE)->first();
        $expires = $row === null ? null : $this->db->parseUtcTimestamp(QueryRecord::raw($row, 'expires_at'));
        if ($row === null || $expires === null || $expires <= $this->clock->now()) {
            throw new OfficeException(ErrorCode::EvidenceUnavailable);
        }

        try {
            return $this->cipher->decrypt(
                QueryRecord::str($row, 'key_id'),
                $this->db->decodeBinary(QueryRecord::raw($row, 'encrypted_evidence')),
                $this->aad($context, $resourceId, $receiptId, $marker),
            );
        } catch (RuntimeException $error) {
            throw new OfficeException(ErrorCode::EvidenceUnavailable, previous: $error);
        }
    }

    private function table(): string
    {
        return $this->tables->table('ai_text_command_evidence');
    }

    /** @return list<string> */
    private function aad(CallContext $context, string $resourceId, string $receiptId, Marker $marker): array
    {
        return [
            $this->table(), $context->tenantId, $context->projectScope, $context->effectiveSubject,
            $resourceId, $receiptId, self::PROFILE, (string) $marker->generation, (string) $marker->position,
        ];
    }
}
