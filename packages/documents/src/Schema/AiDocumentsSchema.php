<?php

declare(strict_types=1);

namespace Vaulter\Ai\Documents\Schema;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Arr;
use RuntimeException;
use Vaulter\Ai\Core\Persistence\OfficeDatabase;
use Vaulter\Configuration\DatabaseOptions;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Schema\HostKeyColumns;
use Vaulter\Schema\SchemaHelpers;

/**
 * Schema builder for the 4 AI Documents tables (plan/spec/database/sql/ai-documents.sql)
 * on the Vaulter OWNER database connection, aligned with Vaulter's IdStrategy and HostKeyColumns.
 */
final readonly class AiDocumentsSchema
{
    public function __construct(
        private VaulterDatabase $database,
        private DatabaseOptions $vaulterDbOptions,
        private SchemaHelpers $helpers,
        private OfficeDatabase $officeDb,
    ) {}

    /**
     * Refuse writer activation unless the documents schema connection and OfficeDatabase
     * resolve to the same name. There is no DocumentsDatabase type: this schema's
     * VaulterDatabase connection is the owner connection DocumentStateWriter uses.
     */
    public function assertSameConnectionAsCore(): void
    {
        $documents = $this->database->connection()->getName();
        $office = $this->officeDb->connection()->getName();
        if ($documents === $office) {
            return;
        }

        throw new RuntimeException(sprintf(
            'DocumentsDatabase connection [%s] differs from OfficeDatabase connection [%s]; writer activation refused.',
            $documents ?? 'default',
            $office ?? 'default',
        ));
    }

    public function createAll(): void
    {
        $schema = $this->helpers->schemaConnection();
        $conn = $this->database->connection();
        $driver = $conn->getDriverName();
        $ids = $this->helpers->ids();

        $commandsTable = $this->vaulterDbOptions->table('ai_text_commands');
        $evidenceTable = $this->vaulterDbOptions->table('ai_text_command_evidence');
        $headsTable = $this->vaulterDbOptions->table('ai_text_projection_heads');
        $blocksTable = $this->vaulterDbOptions->table('ai_text_blocks');
        $documentsTable = $this->vaulterDbOptions->table('documents');

        // 1. v_ai_text_commands
        $schema->create($commandsTable, function (Blueprint $table) use ($driver, $ids, $commandsTable, $documentsTable): void {
            if ($driver === 'pgsql') {
                $table->uuid('row_id')->primary();
            } else {
                $table->string('row_id', 36)->primary();
            }

            HostKeyColumns::add($table, 'tenant_id', nullable: false, index: false);
            $table->string('project_scope', 255);
            $table->string('effective_subject', 255);
            $ids->reference($table, 'resource_id');
            $ids->reference($table, 'document_id', nullable: true);
            Arr::last($table->getColumns())?->index(false);
            $table->string('command_id', 255);

            if ($driver === 'pgsql') {
                $table->uuid('proposal_id');
                $table->binary('request_fingerprint');
            } else {
                $table->string('proposal_id', 36);
                $table->binary('request_fingerprint');
            }

            $table->string('state', 16);
            $table->string('reason', 64)->nullable();

            if ($driver === 'pgsql') {
                $table->uuid('receipt_id')->nullable()->unique($commandsTable.'_receipt_uniq');
            } else {
                $table->string('receipt_id', 36)->nullable()->unique($commandsTable.'_receipt_uniq');
            }

            $table->bigInteger('source_generation')->nullable();
            $table->bigInteger('source_position')->nullable();
            $table->timestampTz('decided_at')->useCurrent();
            $table->timestampTz('dedupe_until');

            $table->unique(
                ['tenant_id', 'project_scope', 'effective_subject', 'resource_id', 'command_id'],
                $commandsTable.'_scope_cmd_uniq',
            );
            $table->unique(
                ['tenant_id', 'project_scope', 'effective_subject', 'resource_id', 'proposal_id'],
                $commandsTable.'_scope_prop_uniq',
            );
            $table->index('document_id', $commandsTable.'_document');
            $table->index('dedupe_until', $commandsTable.'_dedupe_until');

            $table->foreign('document_id', $commandsTable.'_document_fk')
                ->references('id')
                ->on($documentsTable)
                ->nullOnDelete();
        });

        // 2. v_ai_text_command_evidence
        $schema->create($evidenceTable, function (Blueprint $table) use ($driver, $commandsTable, $evidenceTable): void {
            if ($driver === 'pgsql') {
                $table->uuid('receipt_id')->primary();
            } else {
                $table->string('receipt_id', 36)->primary();
            }

            $table->string('profile', 32);
            $table->string('key_id', 128);
            $table->binary('encrypted_evidence');
            $table->timestampTz('expires_at');

            $table->index('expires_at', $evidenceTable.'_expiry');
            $table->foreign('receipt_id', $evidenceTable.'_receipt_fk')
                ->references('receipt_id')
                ->on($commandsTable)
                ->cascadeOnDelete();
        });

        // 3. v_ai_text_projection_heads
        $schema->create($headsTable, function (Blueprint $table) use ($driver, $ids, $headsTable, $documentsTable): void {
            HostKeyColumns::add($table, 'tenant_id', nullable: false, index: false);
            $table->string('project_scope', 255);
            $ids->reference($table, 'document_id');
            Arr::last($table->getColumns())?->index(false);

            $table->bigInteger('source_generation');
            $table->bigInteger('source_position');
            if ($driver === 'pgsql') {
                $table->uuid('build_id');
            } else {
                $table->string('build_id', 36);
            }
            $table->integer('projector_version');
            $table->timestampTz('published_at')->useCurrent();

            $table->primary(['tenant_id', 'project_scope', 'document_id'], $headsTable.'_pk');
            $table->index('document_id', $headsTable.'_document');

            $table->foreign('document_id', $headsTable.'_document_fk')
                ->references('id')
                ->on($documentsTable)
                ->cascadeOnDelete();
        });

        // 4. v_ai_text_blocks
        $schema->create($blocksTable, function (Blueprint $table) use ($driver, $ids, $blocksTable, $documentsTable): void {
            HostKeyColumns::add($table, 'tenant_id', nullable: false, index: false);
            $table->string('project_scope', 255);
            $ids->reference($table, 'document_id');
            Arr::last($table->getColumns())?->index(false);

            $table->bigInteger('source_generation');
            if ($driver === 'pgsql') {
                $table->uuid('build_id');
            } else {
                $table->string('build_id', 36);
            }
            $table->string('block_id', 255);
            $table->string('parent_id', 255)->nullable();
            $table->integer('ordinal');
            $table->string('node_kind', 16);
            $table->jsonb('heading_path');
            $table->text('search_text');
            $table->jsonb('formatted_node');
            $table->binary('source_hash');
            $table->timestampTz('created_at')->useCurrent();

            $table->primary(
                ['tenant_id', 'project_scope', 'document_id', 'source_generation', 'build_id', 'block_id'],
                $blocksTable.'_pk',
            );
            $table->unique(
                ['tenant_id', 'project_scope', 'document_id', 'source_generation', 'build_id', 'ordinal'],
                $blocksTable.'_ordinal_uniq',
            );
            $table->index('document_id', $blocksTable.'_document');

            $table->foreign('document_id', $blocksTable.'_document_fk')
                ->references('id')
                ->on($documentsTable)
                ->cascadeOnDelete();
        });

        $this->applyEngineConstraintsAndIndexes();
    }

    public function dropAll(): void
    {
        $schema = $this->helpers->schemaConnection();
        foreach ([
            'ai_text_blocks',
            'ai_text_projection_heads',
            'ai_text_command_evidence',
            'ai_text_commands',
        ] as $entity) {
            $schema->dropIfExists($this->vaulterDbOptions->table($entity));
        }
    }

    private function applyEngineConstraintsAndIndexes(): void
    {
        $conn = $this->database->connection();
        $grammar = $conn->getQueryGrammar();

        $commands = $grammar->wrapTable($this->vaulterDbOptions->table('ai_text_commands'));
        $evidence = $grammar->wrapTable($this->vaulterDbOptions->table('ai_text_command_evidence'));
        $heads = $grammar->wrapTable($this->vaulterDbOptions->table('ai_text_projection_heads'));
        $blocks = $grammar->wrapTable($this->vaulterDbOptions->table('ai_text_blocks'));

        if ($conn->getDriverName() === 'pgsql') {
            $conn->statement("ALTER TABLE {$commands} ALTER COLUMN row_id SET DEFAULT uuidv7()");
            $conn->statement("ALTER TABLE {$commands} ADD CHECK (state IN ('committed','rejected'))");
            $conn->statement("ALTER TABLE {$commands} ADD CHECK (octet_length(request_fingerprint) = 32)");
            $conn->statement("ALTER TABLE {$commands} ADD CHECK (source_generation IS NULL OR source_generation BETWEEN 0 AND 9007199254740991)");
            $conn->statement("ALTER TABLE {$commands} ADD CHECK (source_position IS NULL OR source_position BETWEEN 0 AND 9007199254740991)");
            $conn->statement("ALTER TABLE {$commands} ADD CHECK (dedupe_until > decided_at)");
            $conn->statement("ALTER TABLE {$commands} ADD CHECK ((state = 'committed' AND reason IS NULL AND receipt_id IS NOT NULL AND source_generation IS NOT NULL AND source_position IS NOT NULL) OR (state = 'rejected' AND reason IS NOT NULL AND receipt_id IS NULL AND source_generation IS NULL AND source_position IS NULL))");

            $conn->statement("ALTER TABLE {$evidence} ADD CHECK (profile = 'text_and_marks')");
            $conn->statement("ALTER TABLE {$evidence} ADD CHECK (key_id <> '')");
            $conn->statement("ALTER TABLE {$evidence} ADD CHECK (octet_length(encrypted_evidence) > 0)");

            $conn->statement("ALTER TABLE {$heads} ADD CHECK (source_generation BETWEEN 0 AND 9007199254740991)");
            $conn->statement("ALTER TABLE {$heads} ADD CHECK (source_position BETWEEN 0 AND 9007199254740991)");
            $conn->statement("ALTER TABLE {$heads} ADD CHECK (projector_version > 0)");

            $conn->statement("ALTER TABLE {$blocks} ADD CHECK (source_generation BETWEEN 0 AND 9007199254740991)");
            $conn->statement("ALTER TABLE {$blocks} ADD CHECK (ordinal >= 0)");
            $conn->statement("ALTER TABLE {$blocks} ADD CHECK (node_kind IN ('paragraph','heading'))");
            $conn->statement("ALTER TABLE {$blocks} ADD CHECK (jsonb_typeof(heading_path) = 'array')");
            $conn->statement("ALTER TABLE {$blocks} ADD CHECK (jsonb_typeof(formatted_node) = 'object')");
            $conn->statement("ALTER TABLE {$blocks} ADD CHECK (octet_length(source_hash) = 32)");

            $ginIdx = $grammar->wrap($this->vaulterDbOptions->table('ai_text_blocks_lexemes'));
            $conn->statement("CREATE INDEX {$ginIdx} ON {$blocks} USING gin (to_tsvector('simple'::regconfig, search_text))");

            return;
        }

        // SQLite still enforces CHECKs cleanly via triggers or table definitions where needed;
        // Core check parity runs directly against PostgreSQL 18.
    }
}
