-- Package axiomasoft/vaulter-ai-documents. OWNER connection, after Vaulter Documents.
-- Resource in an external command = v_nodes.id. Local document FK = v_documents.id.
BEGIN;

CREATE TABLE v_ai_text_commands (
    row_id uuid PRIMARY KEY DEFAULT uuidv7(),
    tenant_id bigint NOT NULL,
    project_scope varchar(255) COLLATE "C" NOT NULL CHECK (octet_length(project_scope) BETWEEN 1 AND 255),
    effective_subject varchar(255) COLLATE "C" NOT NULL CHECK (octet_length(effective_subject) BETWEEN 1 AND 255),
    resource_id uuid NOT NULL,
    document_id uuid REFERENCES v_documents(id) ON DELETE SET NULL,
    command_id varchar(255) COLLATE "C" NOT NULL CHECK (octet_length(command_id) BETWEEN 1 AND 255),
    proposal_id uuid NOT NULL,
    request_fingerprint bytea NOT NULL CHECK (octet_length(request_fingerprint) = 32),
    state varchar(16) NOT NULL CHECK (state IN ('committed','rejected')),
    reason varchar(64),
    receipt_id uuid UNIQUE,
    source_generation bigint CHECK (source_generation BETWEEN 0 AND 9007199254740991),
    source_position bigint CHECK (source_position BETWEEN 0 AND 9007199254740991),
    decided_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
    dedupe_until timestamptz NOT NULL CHECK (dedupe_until > decided_at),
    CHECK ((state = 'committed' AND reason IS NULL AND receipt_id IS NOT NULL
               AND source_generation IS NOT NULL AND source_position IS NOT NULL)
        OR (state = 'rejected' AND reason IS NOT NULL AND receipt_id IS NULL
               AND source_generation IS NULL AND source_position IS NULL)),
    UNIQUE (tenant_id, project_scope, effective_subject, resource_id, command_id),
    UNIQUE (tenant_id, project_scope, effective_subject, resource_id, proposal_id)
);
CREATE INDEX v_ai_text_commands_document ON v_ai_text_commands (document_id);
CREATE INDEX v_ai_text_commands_dedupe_until ON v_ai_text_commands (dedupe_until);

CREATE TABLE v_ai_text_command_evidence (
    receipt_id uuid PRIMARY KEY REFERENCES v_ai_text_commands(receipt_id) ON DELETE CASCADE,
    profile varchar(32) NOT NULL CHECK (profile = 'text_and_marks'),
    key_id varchar(128) NOT NULL CHECK (key_id <> ''),
    encrypted_evidence bytea NOT NULL CHECK (octet_length(encrypted_evidence) > 0),
    expires_at timestamptz NOT NULL
);
CREATE INDEX v_ai_text_command_evidence_expiry ON v_ai_text_command_evidence (expires_at);

CREATE TABLE v_ai_text_projection_heads (
    tenant_id bigint NOT NULL,
    project_scope varchar(255) COLLATE "C" NOT NULL CHECK (octet_length(project_scope) BETWEEN 1 AND 255),
    document_id uuid NOT NULL REFERENCES v_documents(id) ON DELETE CASCADE,
    source_generation bigint NOT NULL CHECK (source_generation BETWEEN 0 AND 9007199254740991),
    source_position bigint NOT NULL CHECK (source_position BETWEEN 0 AND 9007199254740991),
    build_id uuid NOT NULL,
    projector_version integer NOT NULL CHECK (projector_version > 0),
    published_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (tenant_id, project_scope, document_id)
);
CREATE INDEX v_ai_text_heads_document ON v_ai_text_projection_heads (document_id);

CREATE TABLE v_ai_text_blocks (
    tenant_id bigint NOT NULL,
    project_scope varchar(255) COLLATE "C" NOT NULL CHECK (octet_length(project_scope) BETWEEN 1 AND 255),
    document_id uuid NOT NULL REFERENCES v_documents(id) ON DELETE CASCADE,
    source_generation bigint NOT NULL CHECK (source_generation BETWEEN 0 AND 9007199254740991),
    build_id uuid NOT NULL,
    block_id varchar(255) COLLATE "C" NOT NULL CHECK (octet_length(block_id) BETWEEN 1 AND 255),
    parent_id varchar(255) COLLATE "C" CHECK (octet_length(parent_id) BETWEEN 1 AND 255),
    ordinal integer NOT NULL CHECK (ordinal >= 0),
    node_kind varchar(16) NOT NULL CHECK (node_kind IN ('paragraph','heading')),
    heading_path jsonb NOT NULL CHECK (jsonb_typeof(heading_path) = 'array'),
    search_text text NOT NULL,
    formatted_node jsonb NOT NULL CHECK (jsonb_typeof(formatted_node) = 'object'),
    source_hash bytea NOT NULL CHECK (octet_length(source_hash) = 32),
    created_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (tenant_id, project_scope, document_id, source_generation, build_id, block_id),
    UNIQUE (tenant_id, project_scope, document_id, source_generation, build_id, ordinal)
);
CREATE INDEX v_ai_text_blocks_document ON v_ai_text_blocks (document_id);
CREATE INDEX v_ai_text_blocks_lexemes ON v_ai_text_blocks USING gin (to_tsvector('simple'::regconfig, search_text));

COMMIT;
