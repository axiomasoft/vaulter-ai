<?php

declare(strict_types=1);

namespace Vaulter\Ai\Documents\Console;

use Illuminate\Console\Command;
use Vaulter\Ai\Documents\Contracts\DocumentScopeResolver;
use Vaulter\Ai\Documents\Projection\IndexText;

/**
 * Rebuilds AI text projections from authoritative Vaulter Documents content.
 */
final class RebuildTextIndex extends Command
{
    protected $signature = 'vaulter-ai:documents:rebuild-text-index
        {drive : The Drive identifier}
        {--node= : Optional single Document node identifier}
        {--tenant= : Optional tenant key override}
        {--project= : Optional project scope override}';

    protected $description = 'Rebuild published AI addressable text projections from Vaulter Documents.';

    public function handle(IndexText $indexer, DocumentScopeResolver $scopes): int
    {
        $driveId = $this->argument('drive');
        if ($driveId === '') {
            $this->error('A valid drive ID is required.');

            return self::FAILURE;
        }

        $rawTenant = $this->option('tenant');
        $eventTenant = is_string($rawTenant) && $rawTenant !== '' ? $rawTenant : null;
        $resolved = $scopes->forDriveEvent($eventTenant, $driveId);

        $rawProject = $this->option('project');
        $projectScope = is_string($rawProject) && $rawProject !== '' ? $rawProject : $resolved['project_scope'];

        $nodeId = $this->option('node');
        if (is_string($nodeId) && $nodeId !== '') {
            $buildId = $indexer->projectNode($driveId, $nodeId, $resolved['tenant_id'], $projectScope);
            $this->info($buildId !== null ? "Rebuilt projection build [{$buildId}]." : 'Skipped non-rich-text or missing node.');

            return self::SUCCESS;
        }

        $count = $indexer->rebuildDrive($driveId, $resolved['tenant_id'], $projectScope);
        $this->info("Rebuilt text projections for {$count} document(s).");

        return self::SUCCESS;
    }
}
