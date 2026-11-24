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
use Vaulter\Documents\Content\TipTap\TipTapContent;
use Vaulter\Documents\Contracts\LockedDocumentMutation;
use Vaulter\Documents\Exceptions\InvalidDocumentContentException;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Support\Identity;

final readonly class DeleteBlock implements OfficeCommand
{
    public const string COMMAND = 'text.delete_block@1';

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
            name: 'text.delete_block',
            version: 1,
            resourceType: ResourceType::Text,
            effect: 'single_resource_write',
            inputSchema: (object) [
                'type' => 'object',
                'required' => ['block_id'],
                'properties' => (object) [
                    'block_id' => (object) ['type' => 'string', 'minLength' => 1],
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
        if (! is_string($blockId) || $blockId === '' || ! mb_check_encoding($blockId, 'UTF-8')) {
            throw OfficeException::invalidInput('invalid_arguments');
        }
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
        $protection = ContentProtectionPolicy::resolve($context, $resource);
        RichTextBlockExtractor::assertBlockEditable($docArray, $blockId, $protection);
        [$path, $block] = TextReplacement::find($docArray, $blockId);
        $identity = TextReplacement::identity($block);
        if ($identity !== TextReplacement::identity(TextReplacement::node($token->dependencies['formatted_node'] ?? null))) {
            throw OfficeException::preconditionFailed('The target block changed since the read.');
        }

        $candidateDoc = TextReplacement::deleteAt($docArray, $path);
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
            summary: "Delete block {$blockId}",
            targets: [$blockId],
            expiresAt: $expires->format('Y-m-d\TH:i:s\Z'),
            digest: '',
            ownerKey: $owner->key(),
            preconditions: [
                'document_id' => Identity::key($state->documentId) ?? $state->documentId,
                'type' => $state->type,
                'schema' => $state->schemaVersion,
                'write_mode' => $state->writeMode,
                'block_id' => $blockId,
                'section_path' => TextReplacement::sectionPathFor($docArray, $blockId),
                'block_identity' => $identity,
            ],
        );
    }
}
