-- Package axiomasoft/vaulter-ai-core. Standalone CORE connection, PostgreSQL 18.
-- Opaque client identifiers are case sensitive. Scope is tenant/project/typed subject.
-- Public node IDs crossing a process boundary are opaque strings, without a remote FK.
BEGIN;

CREATE TABLE v_ai_read_tokens (
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
);
CREATE INDEX v_ai_read_tokens_expiry ON v_ai_read_tokens (expires_at);

CREATE TABLE v_ai_search_pages (
    snapshot_id uuid PRIMARY KEY DEFAULT uuidv7(),
    tenant_id varchar(255) COLLATE "C" NOT NULL CHECK (octet_length(tenant_id) BETWEEN 1 AND 255),
    project_scope varchar(255) COLLATE "C" NOT NULL CHECK (octet_length(project_scope) BETWEEN 1 AND 255),
    effective_subject varchar(255) COLLATE "C" NOT NULL CHECK (octet_length(effective_subject) BETWEEN 1 AND 255),
    query_hash bytea NOT NULL CHECK (octet_length(query_hash) = 32),
    sort_version integer NOT NULL CHECK (sort_version > 0),
    issued_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at timestamptz NOT NULL CHECK (expires_at > issued_at)
);
CREATE INDEX v_ai_search_pages_expiry ON v_ai_search_pages (expires_at);

CREATE TABLE v_ai_search_items (
    snapshot_id uuid NOT NULL REFERENCES v_ai_search_pages(snapshot_id) ON DELETE CASCADE,
    ordinal integer NOT NULL CHECK (ordinal >= 0),
    resource_id varchar(255) COLLATE "C" NOT NULL CHECK (octet_length(resource_id) BETWEEN 1 AND 255),
    resource_type varchar(8) NOT NULL CHECK (resource_type IN ('text','sheet')),
    PRIMARY KEY (snapshot_id, ordinal),
    UNIQUE (snapshot_id, resource_id)
);

CREATE TABLE v_ai_proposals (
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
);
CREATE INDEX v_ai_proposals_scope_list ON v_ai_proposals (tenant_id, project_scope, effective_subject, decision, created_at, proposal_id);
CREATE INDEX v_ai_proposals_expiry ON v_ai_proposals (expires_at);

CREATE TABLE v_ai_proposal_payloads (
    proposal_id uuid PRIMARY KEY REFERENCES v_ai_proposals(proposal_id) ON DELETE CASCADE,
    key_id varchar(128) NOT NULL CHECK (key_id <> ''),
    encrypted_plan bytea NOT NULL CHECK (octet_length(encrypted_plan) > 0)
);

CREATE TABLE v_ai_owner_routes (
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
        REFERENCES v_ai_proposals(tenant_id, project_scope, effective_subject, resource_id, proposal_id) ON DELETE RESTRICT
);
CREATE UNIQUE INDEX v_ai_routes_by_receipt
    ON v_ai_owner_routes (tenant_id, project_scope, effective_subject, resource_id, receipt_id) WHERE receipt_id IS NOT NULL;
CREATE INDEX v_ai_routes_dedupe_until ON v_ai_owner_routes (dedupe_until);
CREATE INDEX v_ai_owner_routes_proposal_fk_idx
    ON v_ai_owner_routes (tenant_id, project_scope, effective_subject, resource_id, proposal_id);

CREATE TABLE v_ai_dispatches (
    event_id uuid PRIMARY KEY DEFAULT uuidv7(),
    proposal_id uuid NOT NULL UNIQUE REFERENCES v_ai_owner_routes(proposal_id) ON DELETE RESTRICT,
    state varchar(12) NOT NULL DEFAULT 'ready' CHECK (state IN ('ready','leased','delivered','exhausted')),
    attempts integer NOT NULL DEFAULT 0 CHECK (attempts >= 0),
    available_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
    lease_token uuid,
    lease_until timestamptz,
    CHECK ((state = 'leased' AND lease_token IS NOT NULL AND lease_until IS NOT NULL)
        OR (state <> 'leased' AND lease_token IS NULL AND lease_until IS NULL))
);
CREATE INDEX v_ai_dispatches_ready ON v_ai_dispatches (available_at, event_id) WHERE state = 'ready';
CREATE INDEX v_ai_dispatches_lease ON v_ai_dispatches (lease_until, event_id) WHERE state = 'leased';

CREATE TABLE v_ai_runs (
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
);
CREATE INDEX v_ai_runs_ready ON v_ai_runs (next_attempt_at,run_id) WHERE state IN ('queued','running','partially_applied');
CREATE INDEX v_ai_runs_lease ON v_ai_runs (lease_until,run_id) WHERE lease_until IS NOT NULL;

CREATE TABLE v_ai_run_inputs (
    run_id uuid PRIMARY KEY REFERENCES v_ai_runs(run_id) ON DELETE CASCADE,
    key_id varchar(128) NOT NULL CHECK (key_id <> ''),
    encrypted_request bytea NOT NULL CHECK (octet_length(encrypted_request) > 0),
    expires_at timestamptz NOT NULL
);
CREATE INDEX v_ai_run_inputs_expiry ON v_ai_run_inputs (expires_at);

CREATE TABLE v_ai_run_steps (
    run_id uuid NOT NULL REFERENCES v_ai_runs(run_id) ON DELETE CASCADE,
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
);
CREATE UNIQUE INDEX v_ai_steps_action_key ON v_ai_run_steps (run_id, executor_action_key) WHERE executor_action_key IS NOT NULL;

COMMIT;
