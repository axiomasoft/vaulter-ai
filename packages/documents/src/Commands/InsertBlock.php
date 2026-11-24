<?php

declare(strict_types=1);

namespace Vaulter\Ai\Documents\Commands;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Str;
use stdClass;
use Vaulter\Ai\Core\Contracts\Clock;
use Vaulter\Ai\Core\Contracts\HostPolicy;
use Vaulter\Ai\Core\Contracts\OfficeCommand;
use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Core\Data\CommandDescriptor;
use Vaulter\Ai\Core\Data\ContentProtectionPolicy;
use Vaulter\Ai\Core\Data\PreparedPlan;
use Vaulter\Ai\Core\Data\ReadTokenRecord;
use Vaulter\Ai\Core\Data\ResourceEntry;
use Vaulter\Ai\Core\Enums\ResourceType;
use Vaulter\Ai\Core\Exceptions\OfficeException;
use Vaulter\Ai\Core\Registry\OfficeRegistry;
use Vaulter\Ai\Documents\Contracts\LiveTextJournal;
use Vaulter\Ai\Documents\Host\TextDocumentAccess;
use Vaulter\Ai\Documents\Projection\RichTextBlockExtractor;
use Vaulter\Ai\Documents\Writing\DriveDocumentMutation;
use Vaulter\Ai\Documents\Writing\TextReplacement;
use Vaulter\Documents\Content\TipTap\BlockIdentity;
use Vaulter\Documents\Content\TipTap\TipTapContent;
use Vaulter\Documents\Contracts\LockedDocumentMutation;
use Vaulter\Documents\Exceptions\InvalidDocumentContentException;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Support\Identity;

final readonly class InsertBlock implements OfficeCommand
{
    public const string COMMAND = 'text.insert_block@1';

    /** @var list<string> */
    private const array ALLOWED_MARKS = ['bold', 'italic', 'strike', 'code', 'underline'];

    public function __construct(
        private TextDocumentAccess $access,
        private OfficeRegistry $registry,
        private HostPolicy $policy,
        private Clock $clock,
        private Config $config,
    ) {}

    public function descriptor(): CommandDescriptor
    {
        return new CommandDescriptor(
            command: self::COMMAND,
            name: 'text.insert_block',
            version: 1,
            resourceType: ResourceType::Text,
            effect: 'single_resource_write',
            inputSchema: (object) [
                'type' => 'object',
                'required' => ['anchor_block_id', 'position', 'block_type', 'text'],
                'properties' => (object) [
                    'anchor_block_id' => (object) ['type' => 'string', 'minLength' => 1],
                    'position' => (object) ['enum' => ['before', 'after']],
                    'block_type' => (object) ['enum' => ['paragraph', 'heading']],
                    'text' => (object) ['type' => 'string'],
                    'level' => (object) ['type' => 'integer', 'minimum' => 1, 'maximum' => 6],
                    'marks' => (object) [
                        'type' => 'array',
                        'items' => (object) ['enum' => self::ALLOWED_MARKS],
                        'uniqueItems' => true,
                    ],
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
        $anchorBlockId = $arguments->anchor_block_id ?? null;
        $position = $arguments->position ?? null;
        $blockType = $arguments->block_type ?? null;
        $text = $arguments->text ?? null;
        $rawLevel = property_exists($arguments, 'level') ? $arguments->level : null;
        $rawMarks = property_exists($arguments, 'marks') ? $arguments->marks : [];

        if (! is_string($anchorBlockId) || $anchorBlockId === '' || ! mb_check_encoding($anchorBlockId, 'UTF-8')
            || ! is_string($position) || ! in_array($position, ['before', 'after'], true)
            || ! is_string($blockType) || ! in_array($blockType, ['paragraph', 'heading'], true)
            || ! is_string($text) || ! mb_check_encoding($text, 'UTF-8')
            || str_contains($text, RichTextBlockExtractor::LEAF_TOKEN)) {
            throw OfficeException::invalidInput('invalid_arguments');
        }

        if ($blockType === 'paragraph' && $rawLevel !== null) {
            throw OfficeException::invalidInput('Paragraph blocks do not accept a heading level.');
        }

        $level = null;
        if ($blockType === 'heading') {
            $level = $rawLevel ?? 1;
            if (! is_int($level) || $level < 1 || $level > 6) {
                throw OfficeException::invalidInput('Heading level must be an integer between 1 and 6.');
            }
        }

        $marks = $this->normalizeMarks($rawMarks, $text);

        $validBlockToken = $token->completeScope && $token->selectorKind === 'text_block'
            && ($token->dependencies['block_id'] ?? null) === $anchorBlockId;
        $validOutlineToken = $token->completeScope && $token->selectorKind === 'text_outline'
            && ($token->dependencies['query'] ?? null) === null;
        if (! $validBlockToken && ! $validOutlineToken) {
            throw OfficeException::preconditionFailed('Read the anchor block or complete outline first.');
        }

        try {
            $state = $this->access->drive($context, $resource)->extension(DriveDocumentMutation::class)
                ->run($resource->resourceId, static fn (LockedDocumentMutation $locked) => $locked->snapshot());
        } catch (NodeNotFoundException) {
            throw OfficeException::resourceUnavailable();
        }
        if ($state->generation !== $token->marker->generation) {
            throw OfficeException::generationChanged();
        }
        if ((string) $state->schemaVersion !== $token->schemaRevision) {
            throw OfficeException::schemaChanged();
        }
        if ($state->pending) {
            throw OfficeException::projectionPending();
        }
        if ($state->type !== 'vaulter/rich-text' || ! $state->content instanceof TipTapContent) {
            throw OfficeException::unsupportedContent();
        }

        $owner = $this->registry->ownerFor(self::COMMAND, $state->writeMode) ?? throw OfficeException::unsupportedContent();
        $docPosition = $state->position;
        if ($owner instanceof LiveTextJournal) {
            $marker = $owner->projected($context, $resource, $state->generation, $state->position) ?? throw OfficeException::unsupportedContent();
            $docPosition = $marker->position;
        }
        if ($docPosition < $token->marker->position) {
            throw OfficeException::projectionPending();
        }

        $docArray = $state->content->toArray();
        [$anchorPath, $anchorBlock] = TextReplacement::find($docArray, $anchorBlockId);
        if ($validBlockToken && TextReplacement::identity($anchorBlock) !== TextReplacement::identity(TextReplacement::node($token->dependencies['formatted_node'] ?? null))) {
            throw OfficeException::preconditionFailed('The anchor block changed since the read.');
        }

        $existingIds = array_fill_keys(TextReplacement::extractBlockIds($docArray), true);
        do {
            $newBlockId = BlockIdentity::generate();
        } while (isset($existingIds[$newBlockId]));

        $newBlock = TextReplacement::buildBlock($newBlockId, $blockType, $text, $level, $marks);

        $protection = ContentProtectionPolicy::resolve($context, $resource);
        if (! $protection->isEmpty()) {
            $found = RichTextBlockExtractor::findBlockWithAncestors($docArray, $anchorBlockId);
            if ($found !== null) {
                if ($protection->modeForPath([...$found['ancestors'], $found['rawBlock']]) === 'hidden') {
                    throw OfficeException::resourceUnavailable();
                }
                if ($protection->modeForPath($found['ancestors']) === 'read_only') {
                    throw OfficeException::unsupportedContent('content_protected');
                }
                $newMode = $protection->modeForPath([...$found['ancestors'], $newBlock]);
                if ($newMode === 'hidden') {
                    throw OfficeException::resourceUnavailable();
                }
                if ($newMode === 'read_only') {
                    throw OfficeException::unsupportedContent('content_protected');
                }
            }
        }

        $candidateDoc = TextReplacement::insertRelative($docArray, $anchorPath, $position, $newBlock);
        try {
            TipTapContent::fromArray($candidateDoc);
        } catch (InvalidDocumentContentException) {
            throw OfficeException::unsupportedContent();
        }

        $ttl = $this->config->get('vaulter-ai.limits.proposal_ttl_seconds', 3600);
        $expires = $this->clock->now()->modify('+'.(is_int($ttl) && $ttl > 0 ? $ttl : 3600).' seconds');

        return new PreparedPlan(
            proposalId: (string) Str::uuid7(),
            command: self::COMMAND,
            resourceId: $resource->resourceId,
            base: $token->marker,
            approval: $this->policy->profile($context)->approval,
            summary: "Insert {$blockType} {$position} {$anchorBlockId}",
            targets: [$anchorBlockId, $newBlockId],
            expiresAt: $expires->format('Y-m-d\TH:i:s\Z'),
            digest: '',
            ownerKey: $owner->key(),
            preconditions: [
                'document_id' => Identity::key($state->documentId) ?? $state->documentId,
                'type' => $state->type,
                'schema' => $state->schemaVersion,
                'write_mode' => $state->writeMode,
                'anchor_block_id' => $anchorBlockId,
                'anchor_identity' => TextReplacement::identity($anchorBlock),
                'position' => $position,
                'new_block_id' => $newBlockId,
                'block_type' => $blockType,
                'level' => $level,
                'text' => $text,
                'marks' => $marks,
            ],
        );
    }

    /**
     * @return list<string>
     */
    private function normalizeMarks(mixed $rawMarks, string $text): array
    {
        if (! is_array($rawMarks) || ! array_is_list($rawMarks)) {
            throw OfficeException::invalidInput('marks must be a list of allowed mark names.');
        }

        $marks = [];
        foreach ($rawMarks as $mark) {
            if (! is_string($mark) || ! in_array($mark, self::ALLOWED_MARKS, true) || in_array($mark, $marks, true)) {
                throw OfficeException::invalidInput('marks must contain unique allowed mark names.');
            }
            $marks[] = $mark;
        }

        if ($text === '' && $marks !== []) {
            throw OfficeException::invalidInput('marks cannot be applied to an empty block.');
        }

        return $marks;
    }
}
