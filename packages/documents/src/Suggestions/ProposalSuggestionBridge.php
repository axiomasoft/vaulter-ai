<?php

declare(strict_types=1);

namespace Vaulter\Ai\Documents\Suggestions;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionMethod;
use Vaulter\Ai\Core\Contracts\Clock;
use Vaulter\Ai\Core\Contracts\ProposalSuggestionPort;
use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Core\Data\CommandOutcome;
use Vaulter\Ai\Core\Data\CommandScope;
use Vaulter\Ai\Core\Data\PreparedPlan;
use Vaulter\Ai\Core\Data\ResourceEntry;
use Vaulter\Ai\Core\Data\StoredProposal;
use Vaulter\Ai\Documents\Host\TextDocumentAccess;
use Vaulter\Ai\Documents\Writing\TextReplacement;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\DocumentsManager;
use Vaulter\Support\Identity;

/**
 * Optional bridge synchronizing v_ai_proposals lifecycle with v_document_suggestions
 * when vaulter-editor-pro/packages/review is installed (no hard dependency in Core).
 */
final readonly class ProposalSuggestionBridge implements ProposalSuggestionPort
{
    private const string SUGGESTION_OPERATIONS_CLASS = 'Vaulter\\EditorPro\\Review\\Operations\\SuggestionOperations';

    private const string TEXT_SUGGESTION_INPUT_CLASS = 'Vaulter\\EditorPro\\Review\\Data\\TextSuggestionInput';

    public function __construct(
        private DocumentsManager $documents,
        private TextDocumentAccess $access,
        private VaulterDatabase $vaulterDb,
        private Clock $clock,
        private Container $container,
    ) {}

    public function onProposalPrepared(CallContext $context, ResourceEntry $resource, PreparedPlan $plan): void
    {
        $pre = $plan->preconditions;
        $blockId = $pre['block_id'] ?? null;
        $expected = $pre['expected'] ?? null;
        $replacement = $pre['replacement'] ?? null;
        if (! is_string($blockId) || $blockId === '' || ! is_string($expected) || ! is_string($replacement)) {
            return;
        }
        if (! $this->isSuggestionsTableAvailable()) {
            return;
        }

        $startUtf16 = is_int($pre['start_utf16'] ?? null) ? max(0, $pre['start_utf16']) : 0;
        $toUtf16 = $expected === '' ? $startUtf16 : ($startUtf16 + TextReplacement::length($expected));

        $table = 'v_document_suggestions';
        if ($this->vaulterDb->connection()->table($table)->where('proposal_id', $plan->proposalId)->exists()) {
            return;
        }

        $docId = is_string($pre['document_id'] ?? null) && $pre['document_id'] !== ''
            ? $pre['document_id']
            : null;
        $schemaVersion = is_int($pre['schema'] ?? null) ? $pre['schema'] : 2;
        $docType = is_string($pre['type'] ?? null) ? $pre['type'] : 'vaulter/rich-text';

        if ($this->canUseSuggestionOperations($context, $docType)) {
            $drive = $this->access->drive($context, $resource);
            $opsClass = self::SUGGESTION_OPERATIONS_CLASS;
            $inputClass = self::TEXT_SUGGESTION_INPUT_CLASS;
            if (class_exists($opsClass) && class_exists($inputClass)) {
                $ops = (new ReflectionMethod($drive, 'extension'))->invoke($drive, $opsClass);
                /** @var class-string<object> $inputClassName */
                $inputClassName = $inputClass;
                $input = (new ReflectionClass($inputClassName))->newInstanceArgs([
                    'id' => (string) Str::uuid7(),
                    'groupId' => (string) Str::uuid7(),
                    'generation' => $plan->base->generation,
                    'basePosition' => $plan->base->position,
                    'schemaVersion' => $schemaVersion,
                    'targetBlockId' => $blockId,
                    'from' => $startUtf16,
                    'to' => $toUtf16,
                    'before' => $expected,
                    'after' => $replacement,
                    'proposalId' => $plan->proposalId,
                    'parentId' => null,
                ]);
                if (is_object($ops)) {
                    (new ReflectionMethod($ops, 'save'))->invoke($ops, $resource->resourceId, $input, 0);
                }

                return;
            }
        }

        if ($docId === null && $context->actor instanceof Authorizable) {
            $meta = $this->documents->in($this->access->drive($context, $resource))->metadata($resource->resourceId);
            $docId = Identity::key($meta->documentId) ?? $meta->documentId;
            $schemaVersion = $meta->schemaVersion;
        }
        if ($docId === null) {
            return;
        }

        $now = $this->clock->now()->format('Y-m-d H:i:s');
        $kind = $expected === '' ? 'insert' : ($replacement === '' ? 'delete' : 'replace');

        $this->vaulterDb->transaction(function () use (
            $table,
            $context,
            $docId,
            $plan,
            $schemaVersion,
            $blockId,
            $startUtf16,
            $toUtf16,
            $expected,
            $replacement,
            $kind,
            $now,
        ): void {
            if ($this->vaulterDb->connection()->table($table)->where('proposal_id', $plan->proposalId)->exists()) {
                return;
            }

            $this->vaulterDb->connection()->table($table)->insert([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $context->tenantId,
                'document_id' => $docId,
                'group_id' => (string) Str::uuid7(),
                'proposal_id' => $plan->proposalId,
                'generation' => $plan->base->generation,
                'base_position' => $plan->base->position,
                'schema_version' => $schemaVersion,
                'author_subject' => $context->effectiveSubject,
                'provenance_json' => json_encode([
                    'schema' => 1,
                    'source' => 'vaulter-ai',
                    'proposal_id' => $plan->proposalId,
                ], JSON_THROW_ON_ERROR),
                'kind' => $kind,
                'target_block_id' => $blockId,
                'target_selector' => json_encode([
                    'schema' => 1,
                    'from_utf16' => $startUtf16,
                    'to_utf16' => $toUtf16,
                    'parentId' => null,
                    'conflicted' => false,
                ], JSON_THROW_ON_ERROR),
                'payload' => json_encode([
                    'before' => $expected,
                    'after' => $replacement,
                ], JSON_THROW_ON_ERROR),
                'state' => 'open',
                'decided_by_subject' => null,
                'decided_at' => null,
                'receipt_id' => null,
                'version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });
    }

    public function onProposalDecided(CallContext $context, StoredProposal $proposal, string $decision): void
    {
        if ($decision !== 'rejected' || ! $this->isSuggestionsTableAvailable()) {
            return;
        }

        $table = 'v_document_suggestions';
        $stamp = $this->clock->now()->format('Y-m-d H:i:s');
        $subject = $proposal->approverSubject ?? $context->effectiveSubject;

        $this->vaulterDb->transaction(function () use ($table, $proposal, $subject, $stamp): void {
            $this->vaulterDb->connection()->table($table)
                ->where('proposal_id', $proposal->plan->proposalId)
                ->where('state', 'open')
                ->update([
                    'state' => 'rejected',
                    'decided_by_subject' => $subject,
                    'decided_at' => $stamp,
                    'receipt_id' => null,
                    'version' => $this->vaulterDb->connection()->raw('version + 1'),
                    'updated_at' => $stamp,
                ]);
        });
    }

    public function onProposalCommitted(CommandScope $scope, StoredProposal $proposal, CommandOutcome $outcome): void
    {
        if (! $this->isSuggestionsTableAvailable()) {
            return;
        }

        $table = 'v_document_suggestions';
        $stamp = $this->clock->now()->format('Y-m-d H:i:s');

        if ($outcome->state === 'committed' && $outcome->receiptId !== null) {
            $subject = $proposal->approverSubject ?? $proposal->effectiveSubject ?? $scope->context->effectiveSubject;
            $receiptId = $outcome->receiptId;
            $this->vaulterDb->transaction(function () use ($table, $proposal, $subject, $stamp, $receiptId): void {
                $this->vaulterDb->connection()->table($table)
                    ->where('proposal_id', $proposal->plan->proposalId)
                    ->where('state', 'open')
                    ->update([
                        'state' => 'accepted',
                        'decided_by_subject' => $subject,
                        'decided_at' => $stamp,
                        'receipt_id' => $receiptId,
                        'version' => $this->vaulterDb->connection()->raw('version + 1'),
                        'updated_at' => $stamp,
                    ]);
            });

            return;
        }

        if ($outcome->state === 'rejected') {
            $subject = $proposal->approverSubject ?? $scope->context->effectiveSubject;
            $this->vaulterDb->transaction(function () use ($table, $proposal, $subject, $stamp): void {
                $this->vaulterDb->connection()->table($table)
                    ->where('proposal_id', $proposal->plan->proposalId)
                    ->where('state', 'open')
                    ->update([
                        'state' => 'rejected',
                        'decided_by_subject' => $subject,
                        'decided_at' => $stamp,
                        'receipt_id' => null,
                        'version' => $this->vaulterDb->connection()->raw('version + 1'),
                        'updated_at' => $stamp,
                    ]);
            });
        }
    }

    private function isSuggestionsTableAvailable(): bool
    {
        return $this->vaulterDb->connection()
            ->getSchemaBuilder()
            ->hasTable('v_document_suggestions');
    }

    private function canUseSuggestionOperations(CallContext $context, string $docType): bool
    {
        return $docType === 'vaulter/rich-text'
            && $context->actor instanceof Authorizable
            && class_exists(self::SUGGESTION_OPERATIONS_CLASS)
            && class_exists(self::TEXT_SUGGESTION_INPUT_CLASS)
            && $this->container->bound(self::SUGGESTION_OPERATIONS_CLASS);
    }
}
