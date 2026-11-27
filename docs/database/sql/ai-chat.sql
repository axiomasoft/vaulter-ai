-- Package axiomasoft/vaulter-ai-core (V4 / Phase 09 extension).
-- Apply after ai-core.sql on the standalone CORE connection, PostgreSQL 18.
BEGIN;

CREATE TABLE v_ai_chat_sessions (
    session_id uuid PRIMARY KEY DEFAULT uuidv7(),
    tenant_id varchar(255) COLLATE "C" NOT NULL CHECK (octet_length(tenant_id) BETWEEN 1 AND 255),
    project_scope varchar(255) COLLATE "C" NOT NULL CHECK (octet_length(project_scope) BETWEEN 1 AND 255),
    owner_subject varchar(255) COLLATE "C" NOT NULL CHECK (octet_length(owner_subject) BETWEEN 1 AND 255),
    profile_revision varchar(128) NOT NULL CHECK (profile_revision <> ''),
    state varchar(16) NOT NULL DEFAULT 'active' CHECK (state IN ('active', 'archived')),
    last_seq bigint NOT NULL DEFAULT 0 CHECK (last_seq >= 0),
    retention_until timestamptz NOT NULL,
    created_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK (retention_until > created_at)
);
CREATE INDEX v_ai_chat_sessions_scope
    ON v_ai_chat_sessions (tenant_id, project_scope, owner_subject, updated_at DESC, session_id);

CREATE TABLE v_ai_chat_messages (
    session_id uuid NOT NULL REFERENCES v_ai_chat_sessions(session_id) ON DELETE CASCADE,
    message_id varchar(255) COLLATE "C" NOT NULL CHECK (octet_length(message_id) BETWEEN 1 AND 255),
    role varchar(16) NOT NULL CHECK (role IN ('user', 'assistant', 'system')),
    author_subject varchar(255) COLLATE "C" CHECK (octet_length(author_subject) BETWEEN 1 AND 255),
    content_digest bytea NOT NULL CHECK (octet_length(content_digest) = 32),
    run_id uuid REFERENCES v_ai_runs(run_id) ON DELETE RESTRICT,
    resource_scope_json jsonb NOT NULL DEFAULT '[]'::jsonb CHECK (jsonb_typeof(resource_scope_json) = 'array'),
    key_id varchar(128) NOT NULL CHECK (key_id <> ''),
    encrypted_body bytea NOT NULL CHECK (octet_length(encrypted_body) > 0),
    created_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (session_id, message_id)
);
CREATE UNIQUE INDEX v_ai_chat_messages_run
    ON v_ai_chat_messages (run_id) WHERE role = 'user' AND run_id IS NOT NULL;
CREATE INDEX v_ai_chat_messages_run_fk_idx
    ON v_ai_chat_messages (run_id);

CREATE TABLE v_ai_chat_events (
    session_id uuid NOT NULL REFERENCES v_ai_chat_sessions(session_id) ON DELETE CASCADE,
    seq bigint NOT NULL CHECK (seq > 0),
    event_id uuid NOT NULL UNIQUE DEFAULT uuidv7(),
    run_id uuid REFERENCES v_ai_runs(run_id) ON DELETE SET NULL,
    event_type varchar(32) NOT NULL CHECK (event_type IN (
        'assistant.delta', 'assistant.interrupted', 'tool.requested',
        'approval.required', 'tool.committed', 'tool.verified', 'run.finished'
    )),
    resource_scope_json jsonb NOT NULL DEFAULT '[]'::jsonb CHECK (jsonb_typeof(resource_scope_json) = 'array'),
    key_id varchar(128),
    encrypted_payload bytea,
    created_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (session_id, seq),
    CHECK ((key_id IS NULL) = (encrypted_payload IS NULL))
);
CREATE INDEX v_ai_chat_events_run ON v_ai_chat_events (run_id, seq);

CREATE TABLE v_ai_tool_calls (
    run_id uuid NOT NULL REFERENCES v_ai_runs(run_id) ON DELETE CASCADE,
    call_id varchar(255) COLLATE "C" NOT NULL CHECK (octet_length(call_id) BETWEEN 1 AND 255),
    step_id integer NOT NULL CHECK (step_id > 0),
    tool_name varchar(128) NOT NULL CHECK (tool_name <> ''),
    tool_version varchar(32) NOT NULL CHECK (tool_version <> ''),
    input_digest bytea NOT NULL CHECK (octet_length(input_digest) = 32),
    state varchar(20) NOT NULL DEFAULT 'requested' CHECK (state IN (
        'requested', 'awaiting_approval', 'dispatched', 'committed', 'verified', 'rejected', 'failed'
    )),
    proposal_id uuid REFERENCES v_ai_proposals(proposal_id) ON DELETE RESTRICT,
    command_id varchar(255) COLLATE "C" CHECK (octet_length(command_id) BETWEEN 1 AND 255),
    receipt_id varchar(255) COLLATE "C" CHECK (octet_length(receipt_id) BETWEEN 1 AND 255),
    lease_token uuid,
    lease_until timestamptz,
    created_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (run_id, call_id),
    FOREIGN KEY (run_id, step_id) REFERENCES v_ai_run_steps(run_id, step_id) ON DELETE CASCADE,
    CHECK ((lease_token IS NULL) = (lease_until IS NULL))
);
CREATE INDEX v_ai_tool_calls_lease
    ON v_ai_tool_calls (lease_until, run_id, call_id) WHERE lease_until IS NOT NULL;
CREATE INDEX v_ai_tool_calls_proposal_fk_idx
    ON v_ai_tool_calls (proposal_id);
CREATE INDEX v_ai_tool_calls_step_fk_idx
    ON v_ai_tool_calls (run_id, step_id);

COMMIT;
