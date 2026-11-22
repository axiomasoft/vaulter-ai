<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core\Schema;

use Vaulter\Ai\Core\Persistence\OfficeDatabase;

/**
 * Physical schema builder for the 10 AI Core tables defined in plan/spec/database/sql/ai-core.sql.
 * Supports PostgreSQL 18+ (native uuidv7(), COLLATE "C", bytea, CHECKs, partial indexes) and SQLite for unit tests.
 */
final readonly class OfficeCoreSchema
{
    private const array DROP_ORDER = [
        'ai_run_steps',
        'ai_run_inputs',
        'ai_runs',
        'ai_dispatches',
        'ai_owner_routes',
        'ai_proposal_payloads',
        'ai_proposals',
        'ai_search_items',
        'ai_search_pages',
        'ai_read_tokens',
    ];

    public function __construct(
        private OfficeDatabase $db,
    ) {}

    public function createAll(): void
    {
        $conn = $this->db->connection();
        $driver = $conn->getDriverName();

        if ($driver === 'pgsql') {
            foreach ($this->pgsqlStatements() as $statement) {
                $conn->statement($statement);
            }

            return;
        }

        foreach ($this->sqliteStatements() as $statement) {
            $conn->statement($statement);
        }
    }

    public function dropAll(): void
    {
        $conn = $this->db->connection();
        $grammar = $conn->getQueryGrammar();
        foreach (self::DROP_ORDER as $entity) {
            $table = $grammar->wrapTable($this->db->table($entity));
            $conn->statement("DROP TABLE IF EXISTS {$table}");
        }
    }

    /** @return list<string> */
    private function pgsqlStatements(): array
    {
        $readTokens = $this->q('ai_read_tokens');
        $searchPages = $this->q('ai_search_pages');
        $searchItems = $this->q('ai_search_items');
        $proposals = $this->q('ai_proposals');
        $proposalPayloads = $this->q('ai_proposal_payloads');
        $ownerRoutes = $this->q('ai_owner_routes');
        $dispatches = $this->q('ai_dispatches');
        $runs = $this->q('ai_runs');
        $runInputs = $this->q('ai_run_inputs');
        $runSteps = $this->q('ai_run_steps');

        return [
            <<<SQL
            CREATE TABLE {$readTokens} (
                token_hash bytea PRIMARY KEY CHECK (octet_length(token_hash) = 32),
                tenant_id varchar(255) COLLATE "C" NOT NULL CHECK (octet_length(tenant_id) BETWEEN 1 AND 255),
                project_scope varchar(255) COLLATE "C" NOT NULL CHECK (octet_length(project_scope) BETWEEN 1 AND 255),
                effective_subject varchar(255) COLLATE "C" NOT NULL CHECK (octet_length(effective_subject) BETWEEN 1 AND 255),
                resource_id varchar(255) COLLATE "C" NOT NULL CHECK (octet_length(resource_id) BETWEEN 1 AND 255),
                selector_kind varchar(16) NOT NULL CHECK (selector_kind IN ('text_outline','text_block','sheet_catalog','sheet_table')),
                source_generation bigint NOT NULL CHECK (source_generation BETWEEN 0 AND 9007199254740991),
                source_position bigint NOT NULL CHECK (source_position BETWEEN 0 AND 9007199254740991),
                schema_revision varchar(128) NOT NULL CHECK (schema_revision <> ''),
                complete_scope boolean NOT NULL,
                issued_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
                expires_at timestamptz NOT NULL CHECK (expires_at > issued_at),
                key_id varchar(128) NOT NULL CHECK (key_id <> ''),
                encrypted_selector_dependencies bytea NOT NULL CHECK (octet_length(encrypted_selector_dependencies) > 0)
            )
            SQL,
            "CREATE INDEX {$this->idx('ai_read_tokens_expiry')} ON {$readTokens} (expires_at)",
            <<<SQL
            CREATE TABLE {$searchPages} (
                snapshot_id uuid PRIMARY KEY DEFAULT uuidv7(),
                tenant_id varchar(255) COLLATE "C" NOT NULL CHECK (octet_length(tenant_id) BETWEEN 1 AND 255),
                project_scope varchar(255) COLLATE "C" NOT NULL CHECK (octet_length(project_scope) BETWEEN 1 AND 255),
                effective_subject varchar(255) COLLATE "C" NOT NULL CHECK (octet_length(effective_subject) BETWEEN 1 AND 255),
                query_hash bytea NOT NULL CHECK (octet_length(query_hash) = 32),
                sort_version integer NOT NULL CHECK (sort_version > 0),
                issued_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
                expires_at timestamptz NOT NULL CHECK (expires_at > issued_at)
            )
            SQL,
            "CREATE INDEX {$this->idx('ai_search_pages_expiry')} ON {$searchPages} (expires_at)",
            <<<SQL
            CREATE TABLE {$searchItems} (
                snapshot_id uuid NOT NULL REFERENCES {$searchPages}(snapshot_id) ON DELETE CASCADE,
                ordinal integer NOT NULL CHECK (ordinal >= 0),
                resource_id varchar(255) COLLATE "C" NOT NULL CHECK (octet_length(resource_id) BETWEEN 1 AND 255),
                resource_type varchar(8) NOT NULL CHECK (resource_type IN ('text','sheet')),
                PRIMARY KEY (snapshot_id, ordinal),
                UNIQUE (snapshot_id, resource_id)
            )
            SQL,
            <<<SQL
            CREATE TABLE {$proposals} (
                proposal_id uuid PRIMARY KEY DEFAULT uuidv7(),
                tenant_id varchar(255) COLLATE "C" NOT NULL CHECK (octet_length(tenant_id) BETWEEN 1 AND 255),
                project_scope varchar(255) COLLATE "C" NOT NULL CHECK (octet_length(project_scope) BETWEEN 1 AND 255),
                effective_subject varchar(255) COLLATE "C" NOT NULL CHECK (octet_length(effective_subject) BETWEEN 1 AND 255),
                resource_id varchar(255) COLLATE "C" NOT NULL CHECK (octet_length(resource_id) BETWEEN 1 AND 255),
                command_name varchar(128) NOT NULL CHECK (command_name <> ''),
                read_token_hash bytea NOT NULL CHECK (octet_length(read_token_hash) = 32),
                base_generation bigint NOT NULL CHECK (base_generation BETWEEN 0 AND 9007199254740991),
                base_position bigint NOT NULL CHECK (base_position BETWEEN 0 AND 9007199254740991),
                policy_revision varchar(128) NOT NULL CHECK (policy_revision <> ''),
                schema_revision varchar(128) NOT NULL CHECK (schema_revision <> ''),
                digest bytea NOT NULL CHECK (octet_length(digest) = 32),
                approval_mode varchar(12) NOT NULL CHECK (approval_mode IN ('automatic','human')),
                decision varchar(12) NOT NULL CHECK (decision IN ('pending','approved','rejected')),
                approver_subject varchar(255) COLLATE "C" CHECK (octet_length(approver_subject) BETWEEN 1 AND 255),
                decided_at timestamptz,
                created_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
                expires_at timestamptz NOT NULL CHECK (expires_at > created_at),
                CHECK ((approval_mode = 'automatic' AND decision = 'approved' AND approver_subject IS NULL AND decided_at IS NULL)
                    OR (approval_mode = 'human' AND ((decision = 'pending' AND approver_subject IS NULL AND decided_at IS NULL)
                    OR (decision IN ('approved','rejected') AND approver_subject IS NOT NULL AND decided_at IS NOT NULL)))),
                UNIQUE (tenant_id, project_scope, effective_subject, resource_id, proposal_id)
            )
            SQL,
            "CREATE INDEX {$this->idx('ai_proposals_scope_list')} ON {$proposals} (tenant_id, project_scope, effective_subject, decision, created_at, proposal_id)",
            "CREATE INDEX {$this->idx('ai_proposals_expiry')} ON {$proposals} (expires_at)",
            <<<SQL
            CREATE TABLE {$proposalPayloads} (
                proposal_id uuid PRIMARY KEY REFERENCES {$proposals}(proposal_id) ON DELETE CASCADE,
                key_id varchar(128) NOT NULL CHECK (key_id <> ''),
                encrypted_plan bytea NOT NULL CHECK (octet_length(encrypted_plan) > 0)
            )
            SQL,
            <<<SQL
            CREATE TABLE {$ownerRoutes} (
                tenant_id varchar(255) COLLATE "C" NOT NULL CHECK (octet_length(tenant_id) BETWEEN 1 AND 255),
                project_scope varchar(255) COLLATE "C" NOT NULL CHECK (octet_length(project_scope) BETWEEN 1 AND 255),
                effective_subject varchar(255) COLLATE "C" NOT NULL CHECK (octet_length(effective_subject) BETWEEN 1 AND 255),
                resource_id varchar(255) COLLATE "C" NOT NULL CHECK (octet_length(resource_id) BETWEEN 1 AND 255),
                command_id varchar(255) COLLATE "C" NOT NULL CHECK (octet_length(command_id) BETWEEN 1 AND 255),
                proposal_id uuid NOT NULL UNIQUE,
                request_fingerprint bytea NOT NULL CHECK (octet_length(request_fingerprint) = 32),
                owner_key varchar(128) NOT NULL CHECK (owner_key <> ''),
                receipt_id varchar(255) COLLATE "C" CHECK (octet_length(receipt_id) BETWEEN 1 AND 255),
                created_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
                dedupe_until timestamptz NOT NULL CHECK (dedupe_until > created_at),
                PRIMARY KEY (tenant_id, project_scope, effective_subject, resource_id, command_id),
                FOREIGN KEY (tenant_id, project_scope, effective_subject, resource_id, proposal_id)
                    REFERENCES {$proposals}(tenant_id, project_scope, effective_subject, resource_id, proposal_id) ON DELETE RESTRICT
            )
            SQL,
            "CREATE UNIQUE INDEX {$this->idx('ai_routes_by_receipt')} ON {$ownerRoutes} (tenant_id, project_scope, effective_subject, resource_id, receipt_id) WHERE receipt_id IS NOT NULL",
            "CREATE INDEX {$this->idx('ai_routes_dedupe_until')} ON {$ownerRoutes} (dedupe_until)",
            "CREATE INDEX {$this->idx('ai_owner_routes_proposal_fk_idx')} ON {$ownerRoutes} (tenant_id, project_scope, effective_subject, resource_id, proposal_id)",
            <<<SQL
            CREATE TABLE {$dispatches} (
                event_id uuid PRIMARY KEY DEFAULT uuidv7(),
                proposal_id uuid NOT NULL UNIQUE REFERENCES {$ownerRoutes}(proposal_id) ON DELETE RESTRICT,
                state varchar(12) NOT NULL DEFAULT 'ready' CHECK (state IN ('ready','leased','delivered','exhausted')),
                attempts integer NOT NULL DEFAULT 0 CHECK (attempts >= 0),
                available_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
                lease_token uuid,
                lease_until timestamptz,
                CHECK ((state = 'leased' AND lease_token IS NOT NULL AND lease_until IS NOT NULL)
                    OR (state <> 'leased' AND lease_token IS NULL AND lease_until IS NULL))
            )
            SQL,
            "CREATE INDEX {$this->idx('ai_dispatches_ready')} ON {$dispatches} (available_at, event_id) WHERE state = 'ready'",
            "CREATE INDEX {$this->idx('ai_dispatches_lease')} ON {$dispatches} (lease_until, event_id) WHERE state = 'leased'",
            <<<SQL
            CREATE TABLE {$runs} (
                run_id uuid PRIMARY KEY DEFAULT uuidv7(),
                tenant_id varchar(255) COLLATE "C" NOT NULL CHECK (octet_length(tenant_id) BETWEEN 1 AND 255),
                project_scope varchar(255) COLLATE "C" NOT NULL CHECK (octet_length(project_scope) BETWEEN 1 AND 255),
                effective_subject varchar(255) COLLATE "C" NOT NULL CHECK (octet_length(effective_subject) BETWEEN 1 AND 255),
                channel varchar(64) NOT NULL CHECK (channel <> ''),
                source_event_id varchar(255) COLLATE "C" NOT NULL CHECK (octet_length(source_event_id) BETWEEN 1 AND 255),
                profile_revision varchar(128) NOT NULL CHECK (profile_revision <> ''),
                state varchar(24) NOT NULL DEFAULT 'queued' CHECK (state IN ('queued','running','awaiting_approval','partially_applied','completed','rejected','reconcile')),
                created_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
                next_attempt_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
                lease_token uuid,
                lease_until timestamptz,
                CHECK ((lease_token IS NULL) = (lease_until IS NULL)),
                UNIQUE (tenant_id, project_scope, effective_subject, channel, source_event_id)
            )
            SQL,
            "CREATE INDEX {$this->idx('ai_runs_ready')} ON {$runs} (next_attempt_at, run_id) WHERE state IN ('queued','running','partially_applied')",
            "CREATE INDEX {$this->idx('ai_runs_lease')} ON {$runs} (lease_until, run_id) WHERE lease_until IS NOT NULL",
            <<<SQL
            CREATE TABLE {$runInputs} (
                run_id uuid PRIMARY KEY REFERENCES {$runs}(run_id) ON DELETE CASCADE,
                key_id varchar(128) NOT NULL CHECK (key_id <> ''),
                encrypted_request bytea NOT NULL CHECK (octet_length(encrypted_request) > 0),
                expires_at timestamptz NOT NULL
            )
            SQL,
            "CREATE INDEX {$this->idx('ai_run_inputs_expiry')} ON {$runInputs} (expires_at)",
            <<<SQL
            CREATE TABLE {$runSteps} (
                run_id uuid NOT NULL REFERENCES {$runs}(run_id) ON DELETE CASCADE,
                step_id integer NOT NULL CHECK (step_id > 0),
                operation varchar(32) NOT NULL CHECK (operation IN ('office.search','office.read','office.describe','office.prepare','office.commit','office.status','office.verify')),
                resource_id varchar(255) COLLATE "C" CHECK (octet_length(resource_id) BETWEEN 1 AND 255),
                proposal_id uuid,
                command_id varchar(255) COLLATE "C" CHECK (octet_length(command_id) BETWEEN 1 AND 255),
                receipt_id varchar(255) COLLATE "C" CHECK (octet_length(receipt_id) BETWEEN 1 AND 255),
                executor_action_key varchar(255) COLLATE "C" CHECK (octet_length(executor_action_key) BETWEEN 1 AND 255),
                state varchar(16) NOT NULL CHECK (state IN ('pending','dispatched','waiting','verified','failed','finished')),
                input_tokens bigint CHECK (input_tokens >= 0),
                output_tokens bigint CHECK (output_tokens >= 0),
                response_bytes bigint CHECK (response_bytes >= 0),
                latency_ms bigint CHECK (latency_ms >= 0),
                safe_error varchar(64),
                created_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
                key_id varchar(128),
                encrypted_resume_input bytea,
                encrypted_compact_result bytea,
                CHECK ((key_id IS NULL) = (encrypted_resume_input IS NULL AND encrypted_compact_result IS NULL)),
                PRIMARY KEY (run_id, step_id)
            )
            SQL,
            "CREATE UNIQUE INDEX {$this->idx('ai_steps_action_key')} ON {$runSteps} (run_id, executor_action_key) WHERE executor_action_key IS NOT NULL",
        ];
    }

    /** @return list<string> */
    private function sqliteStatements(): array
    {
        $readTokens = $this->q('ai_read_tokens');
        $searchPages = $this->q('ai_search_pages');
        $searchItems = $this->q('ai_search_items');
        $proposals = $this->q('ai_proposals');
        $proposalPayloads = $this->q('ai_proposal_payloads');
        $ownerRoutes = $this->q('ai_owner_routes');
        $dispatches = $this->q('ai_dispatches');
        $runs = $this->q('ai_runs');
        $runInputs = $this->q('ai_run_inputs');
        $runSteps = $this->q('ai_run_steps');

        return [
            <<<SQL
            CREATE TABLE {$readTokens} (
                token_hash blob PRIMARY KEY CHECK (length(CAST(token_hash AS BLOB)) = 32),
                tenant_id text NOT NULL CHECK (length(CAST(tenant_id AS BLOB)) BETWEEN 1 AND 255),
                project_scope text NOT NULL CHECK (length(CAST(project_scope AS BLOB)) BETWEEN 1 AND 255),
                effective_subject text NOT NULL CHECK (length(CAST(effective_subject AS BLOB)) BETWEEN 1 AND 255),
                resource_id text NOT NULL CHECK (length(CAST(resource_id AS BLOB)) BETWEEN 1 AND 255),
                selector_kind text NOT NULL CHECK (selector_kind IN ('text_outline','text_block','sheet_catalog','sheet_table')),
                source_generation integer NOT NULL CHECK (source_generation BETWEEN 0 AND 9007199254740991),
                source_position integer NOT NULL CHECK (source_position BETWEEN 0 AND 9007199254740991),
                schema_revision text NOT NULL CHECK (schema_revision <> ''),
                complete_scope integer NOT NULL,
                issued_at text NOT NULL DEFAULT CURRENT_TIMESTAMP,
                expires_at text NOT NULL CHECK (expires_at > issued_at),
                key_id text NOT NULL CHECK (key_id <> ''),
                encrypted_selector_dependencies blob NOT NULL CHECK (length(CAST(encrypted_selector_dependencies AS BLOB)) > 0)
            )
            SQL,
            "CREATE INDEX {$this->idx('ai_read_tokens_expiry')} ON {$readTokens} (expires_at)",
            <<<SQL
            CREATE TABLE {$searchPages} (
                snapshot_id text PRIMARY KEY,
                tenant_id text NOT NULL CHECK (length(CAST(tenant_id AS BLOB)) BETWEEN 1 AND 255),
                project_scope text NOT NULL CHECK (length(CAST(project_scope AS BLOB)) BETWEEN 1 AND 255),
                effective_subject text NOT NULL CHECK (length(CAST(effective_subject AS BLOB)) BETWEEN 1 AND 255),
                query_hash blob NOT NULL CHECK (length(CAST(query_hash AS BLOB)) = 32),
                sort_version integer NOT NULL CHECK (sort_version > 0),
                issued_at text NOT NULL DEFAULT CURRENT_TIMESTAMP,
                expires_at text NOT NULL CHECK (expires_at > issued_at)
            )
            SQL,
            "CREATE INDEX {$this->idx('ai_search_pages_expiry')} ON {$searchPages} (expires_at)",
            <<<SQL
            CREATE TABLE {$searchItems} (
                snapshot_id text NOT NULL REFERENCES {$searchPages}(snapshot_id) ON DELETE CASCADE,
                ordinal integer NOT NULL CHECK (ordinal >= 0),
                resource_id text NOT NULL CHECK (length(CAST(resource_id AS BLOB)) BETWEEN 1 AND 255),
                resource_type text NOT NULL CHECK (resource_type IN ('text','sheet')),
                PRIMARY KEY (snapshot_id, ordinal),
                UNIQUE (snapshot_id, resource_id)
            )
            SQL,
            <<<SQL
            CREATE TABLE {$proposals} (
                proposal_id text PRIMARY KEY,
                tenant_id text NOT NULL CHECK (length(CAST(tenant_id AS BLOB)) BETWEEN 1 AND 255),
                project_scope text NOT NULL CHECK (length(CAST(project_scope AS BLOB)) BETWEEN 1 AND 255),
                effective_subject text NOT NULL CHECK (length(CAST(effective_subject AS BLOB)) BETWEEN 1 AND 255),
                resource_id text NOT NULL CHECK (length(CAST(resource_id AS BLOB)) BETWEEN 1 AND 255),
                command_name text NOT NULL CHECK (command_name <> ''),
                read_token_hash blob NOT NULL CHECK (length(CAST(read_token_hash AS BLOB)) = 32),
                base_generation integer NOT NULL CHECK (base_generation BETWEEN 0 AND 9007199254740991),
                base_position integer NOT NULL CHECK (base_position BETWEEN 0 AND 9007199254740991),
                policy_revision text NOT NULL CHECK (policy_revision <> ''),
                schema_revision text NOT NULL CHECK (schema_revision <> ''),
                digest blob NOT NULL CHECK (length(CAST(digest AS BLOB)) = 32),
                approval_mode text NOT NULL CHECK (approval_mode IN ('automatic','human')),
                decision text NOT NULL CHECK (decision IN ('pending','approved','rejected')),
                approver_subject text CHECK (approver_subject IS NULL OR length(CAST(approver_subject AS BLOB)) BETWEEN 1 AND 255),
                decided_at text,
                created_at text NOT NULL DEFAULT CURRENT_TIMESTAMP,
                expires_at text NOT NULL CHECK (expires_at > created_at),
                CHECK ((approval_mode = 'automatic' AND decision = 'approved' AND approver_subject IS NULL AND decided_at IS NULL)
                    OR (approval_mode = 'human' AND ((decision = 'pending' AND approver_subject IS NULL AND decided_at IS NULL)
                    OR (decision IN ('approved','rejected') AND approver_subject IS NOT NULL AND decided_at IS NOT NULL)))),
                UNIQUE (tenant_id, project_scope, effective_subject, resource_id, proposal_id)
            )
            SQL,
            "CREATE INDEX {$this->idx('ai_proposals_scope_list')} ON {$proposals} (tenant_id, project_scope, effective_subject, decision, created_at, proposal_id)",
            "CREATE INDEX {$this->idx('ai_proposals_expiry')} ON {$proposals} (expires_at)",
            <<<SQL
            CREATE TABLE {$proposalPayloads} (
                proposal_id text PRIMARY KEY REFERENCES {$proposals}(proposal_id) ON DELETE CASCADE,
                key_id text NOT NULL CHECK (key_id <> ''),
                encrypted_plan blob NOT NULL CHECK (length(CAST(encrypted_plan AS BLOB)) > 0)
            )
            SQL,
            <<<SQL
            CREATE TABLE {$ownerRoutes} (
                tenant_id text NOT NULL CHECK (length(CAST(tenant_id AS BLOB)) BETWEEN 1 AND 255),
                project_scope text NOT NULL CHECK (length(CAST(project_scope AS BLOB)) BETWEEN 1 AND 255),
                effective_subject text NOT NULL CHECK (length(CAST(effective_subject AS BLOB)) BETWEEN 1 AND 255),
                resource_id text NOT NULL CHECK (length(CAST(resource_id AS BLOB)) BETWEEN 1 AND 255),
                command_id text NOT NULL CHECK (length(CAST(command_id AS BLOB)) BETWEEN 1 AND 255),
                proposal_id text NOT NULL UNIQUE,
                request_fingerprint blob NOT NULL CHECK (length(CAST(request_fingerprint AS BLOB)) = 32),
                owner_key text NOT NULL CHECK (owner_key <> ''),
                receipt_id text CHECK (receipt_id IS NULL OR length(CAST(receipt_id AS BLOB)) BETWEEN 1 AND 255),
                created_at text NOT NULL DEFAULT CURRENT_TIMESTAMP,
                dedupe_until text NOT NULL CHECK (dedupe_until > created_at),
                PRIMARY KEY (tenant_id, project_scope, effective_subject, resource_id, command_id),
                FOREIGN KEY (tenant_id, project_scope, effective_subject, resource_id, proposal_id)
                    REFERENCES {$proposals}(tenant_id, project_scope, effective_subject, resource_id, proposal_id) ON DELETE RESTRICT
            )
            SQL,
            "CREATE UNIQUE INDEX {$this->idx('ai_routes_by_receipt')} ON {$ownerRoutes} (tenant_id, project_scope, effective_subject, resource_id, receipt_id) WHERE receipt_id IS NOT NULL",
            "CREATE INDEX {$this->idx('ai_routes_dedupe_until')} ON {$ownerRoutes} (dedupe_until)",
            "CREATE INDEX {$this->idx('ai_owner_routes_proposal_fk_idx')} ON {$ownerRoutes} (tenant_id, project_scope, effective_subject, resource_id, proposal_id)",
            <<<SQL
            CREATE TABLE {$dispatches} (
                event_id text PRIMARY KEY,
                proposal_id text NOT NULL UNIQUE REFERENCES {$ownerRoutes}(proposal_id) ON DELETE RESTRICT,
                state text NOT NULL DEFAULT 'ready' CHECK (state IN ('ready','leased','delivered','exhausted')),
                attempts integer NOT NULL DEFAULT 0 CHECK (attempts >= 0),
                available_at text NOT NULL DEFAULT CURRENT_TIMESTAMP,
                lease_token text,
                lease_until text,
                CHECK ((state = 'leased' AND lease_token IS NOT NULL AND lease_until IS NOT NULL)
                    OR (state <> 'leased' AND lease_token IS NULL AND lease_until IS NULL))
            )
            SQL,
            "CREATE INDEX {$this->idx('ai_dispatches_ready')} ON {$dispatches} (available_at, event_id) WHERE state = 'ready'",
            "CREATE INDEX {$this->idx('ai_dispatches_lease')} ON {$dispatches} (lease_until, event_id) WHERE state = 'leased'",
            <<<SQL
            CREATE TABLE {$runs} (
                run_id text PRIMARY KEY,
                tenant_id text NOT NULL CHECK (length(CAST(tenant_id AS BLOB)) BETWEEN 1 AND 255),
                project_scope text NOT NULL CHECK (length(CAST(project_scope AS BLOB)) BETWEEN 1 AND 255),
                effective_subject text NOT NULL CHECK (length(CAST(effective_subject AS BLOB)) BETWEEN 1 AND 255),
                channel text NOT NULL CHECK (channel <> ''),
                source_event_id text NOT NULL CHECK (length(CAST(source_event_id AS BLOB)) BETWEEN 1 AND 255),
                profile_revision text NOT NULL CHECK (profile_revision <> ''),
                state text NOT NULL DEFAULT 'queued' CHECK (state IN ('queued','running','awaiting_approval','partially_applied','completed','rejected','reconcile')),
                created_at text NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at text NOT NULL DEFAULT CURRENT_TIMESTAMP,
                next_attempt_at text NOT NULL DEFAULT CURRENT_TIMESTAMP,
                lease_token text,
                lease_until text,
                CHECK ((lease_token IS NULL) = (lease_until IS NULL)),
                UNIQUE (tenant_id, project_scope, effective_subject, channel, source_event_id)
            )
            SQL,
            "CREATE INDEX {$this->idx('ai_runs_ready')} ON {$runs} (next_attempt_at, run_id) WHERE state IN ('queued','running','partially_applied')",
            "CREATE INDEX {$this->idx('ai_runs_lease')} ON {$runs} (lease_until, run_id) WHERE lease_until IS NOT NULL",
            <<<SQL
            CREATE TABLE {$runInputs} (
                run_id text PRIMARY KEY REFERENCES {$runs}(run_id) ON DELETE CASCADE,
                key_id text NOT NULL CHECK (key_id <> ''),
                encrypted_request blob NOT NULL CHECK (length(CAST(encrypted_request AS BLOB)) > 0),
                expires_at text NOT NULL
            )
            SQL,
            "CREATE INDEX {$this->idx('ai_run_inputs_expiry')} ON {$runInputs} (expires_at)",
            <<<SQL
            CREATE TABLE {$runSteps} (
                run_id text NOT NULL REFERENCES {$runs}(run_id) ON DELETE CASCADE,
                step_id integer NOT NULL CHECK (step_id > 0),
                operation text NOT NULL CHECK (operation IN ('office.search','office.read','office.describe','office.prepare','office.commit','office.status','office.verify')),
                resource_id text CHECK (resource_id IS NULL OR length(CAST(resource_id AS BLOB)) BETWEEN 1 AND 255),
                proposal_id text,
                command_id text CHECK (command_id IS NULL OR length(CAST(command_id AS BLOB)) BETWEEN 1 AND 255),
                receipt_id text CHECK (receipt_id IS NULL OR length(CAST(receipt_id AS BLOB)) BETWEEN 1 AND 255),
                executor_action_key text CHECK (executor_action_key IS NULL OR length(CAST(executor_action_key AS BLOB)) BETWEEN 1 AND 255),
                state text NOT NULL CHECK (state IN ('pending','dispatched','waiting','verified','failed','finished')),
                input_tokens integer CHECK (input_tokens >= 0),
                output_tokens integer CHECK (output_tokens >= 0),
                response_bytes integer CHECK (response_bytes >= 0),
                latency_ms integer CHECK (latency_ms >= 0),
                safe_error text,
                created_at text NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at text NOT NULL DEFAULT CURRENT_TIMESTAMP,
                key_id text,
                encrypted_resume_input blob,
                encrypted_compact_result blob,
                CHECK ((key_id IS NULL) = (encrypted_resume_input IS NULL AND encrypted_compact_result IS NULL)),
                PRIMARY KEY (run_id, step_id)
            )
            SQL,
            "CREATE UNIQUE INDEX {$this->idx('ai_steps_action_key')} ON {$runSteps} (run_id, executor_action_key) WHERE executor_action_key IS NOT NULL",
        ];
    }

    private function q(string $logical): string
    {
        return $this->db->connection()->getQueryGrammar()->wrapTable($this->db->table($logical));
    }

    private function idx(string $suffix): string
    {
        $prefix = $this->db->table('');
        $raw = $prefix.$suffix;

        return $this->db->connection()->getQueryGrammar()->wrap($raw);
    }
}
