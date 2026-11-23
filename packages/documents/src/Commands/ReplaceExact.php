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
use Vaulter\Documents\Content\TipTap\TipTapContent;
use Vaulter\Documents\Contracts\LockedDocumentMutation;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;

final readonly class ReplaceExact implements OfficeCommand
{
    public const string COMMAND = 'text.replace_exact@1';

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
            name: 'text.replace_exact',
            version: 1,
            resourceType: ResourceType::Text,
            effect: 'single_resource_write',
            inputSchema: (object) [
                'type' => 'object',
                'required' => ['block_id', 'expected', 'replacement'],
                'properties' => (object) [
                    'block_id' => (object) ['type' => 'string', 'minLength' => 1],
                    'expected' => (object) ['type' => 'string', 'minLength' => 1],
                    'replacement' => (object) ['type' => 'string'],
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
        $blockId = $arguments->block_id ?? null;
        $expected = $arguments->expected ?? null;
        $replacement = $arguments->replacement ?? null;
        if (! is_string($blockId) || ! is_string($expected) || ! is_string($replacement)) {
            throw OfficeException::invalidInput();
        }
        TextReplacement::assertArguments($blockId, $expected, $replacement);
        if (! $token->completeScope || $token->selectorKind !== 'text_block'
            || ($token->dependencies['block_id'] ?? null) !== $blockId) {
            throw OfficeException::preconditionFailed('Read the complete target block first.');
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
        $position = $state->position;
        if ($owner instanceof LiveTextJournal) {
            $marker = $owner->projected($context, $resource, $state->generation, $state->position) ?? throw OfficeException::unsupportedContent();
            $position = $marker->position;
        }
        if ($position < $token->marker->position) {
            throw OfficeException::projectionPending();
        }
        $docArray = $state->content->toArray();
        [, $block] = TextReplacement::find($docArray, $blockId);
        $sectionPath = $this->sectionPathFor($docArray, $blockId);
        $identity = TextReplacement::identity($block);
        if ($identity !== TextReplacement::identity(TextReplacement::node($token->dependencies['formatted_node'] ?? null))) {
            throw OfficeException::preconditionFailed('The target block changed since the read.');
        }
        $target = TextReplacement::target($block, $expected);
        [$text] = TextReplacement::runs($block);
        $ttl = $this->config->get('vaulter-ai.limits.proposal_ttl_seconds', 3600);
        $expires = $this->clock->now()->modify('+'.(is_int($ttl) && $ttl > 0 ? $ttl : 3600).' seconds');

        return new PreparedPlan(
            proposalId: (string) Str::uuid7(),
            command: self::COMMAND,
            resourceId: $resource->resourceId,
            base: $token->marker,
            approval: $this->policy->profile($context)->approval,
            summary: $replacement === '' ? "Delete “{$expected}”" : "Replace “{$expected}” with “{$replacement}”",
            targets: [$blockId],
            expiresAt: $expires->format('Y-m-d\TH:i:s\Z'),
            digest: '',
            ownerKey: $owner->key(),
            preconditions: [
                'type' => $state->type,
                'schema' => $state->schemaVersion,
                'write_mode' => $state->writeMode,
                'block_id' => $blockId,
                'section_path' => $sectionPath,
                'block_identity' => $identity,
                'expected' => $expected,
                'replacement' => $replacement,
                'start_utf16' => TextReplacement::length(substr($text, 0, $target['start'])),
                'length_utf16' => TextReplacement::length($expected),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $docArray
     * @return list<string>
     */
    private function sectionPathFor(array $docArray, string $blockId): array
    {
        foreach ((new RichTextBlockExtractor)->extract($docArray) as $extracted) {
            if ($extracted->blockId === $blockId) {
                return $extracted->headingPath;
            }
        }

        return [];
    }
}
