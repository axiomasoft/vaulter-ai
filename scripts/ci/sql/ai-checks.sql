-- On an isolated reference database with base + AI DDL installed.
BEGIN;
SET LOCAL plpgsql.check_asserts = on;

DO $check$
DECLARE
    selected_drive uuid := uuidv7();
    selected_node uuid := uuidv7();
    selected_doc uuid := uuidv7();
    selected_proposal uuid := uuidv7();
    other_proposal uuid := uuidv7();
    selected_receipt uuid := uuidv7();
    selected_snapshot uuid := uuidv7();
    selected_run uuid := uuidv7();
    h bytea := decode(repeat('a4', 32), 'hex');
BEGIN
    INSERT INTO v_drives (id, tenant_id, owner_type, owner_id, name)
    VALUES (selected_drive, 61, 'ai_schema_fixture', 61, 'office');
    INSERT INTO v_nodes (id, tenant_id, drive_id, kind, name, name_key)
    VALUES (selected_node, 61, selected_drive, 'document', 'Test', 'test');
    INSERT INTO v_documents (id, node_id, tenant_id, type, schema_version)
    VALUES (selected_doc, selected_node, 61, 'vaulter/rich-text', 1);
    INSERT INTO v_ai_proposals (
        proposal_id, tenant_id, project_scope, effective_subject, resource_id, command_name,
        read_token_hash, base_generation, base_position, policy_revision, schema_revision,
        digest, approval_mode, decision, expires_at
    ) VALUES
        (selected_proposal, '61','project','user/42',selected_node::text,'text.replace_exact@1',
         h,3,27,'1','1',h,'human','pending',now()+interval '1 hour'),
        (other_proposal, '62','project','user/42',selected_node::text,'text.replace_exact@1',
         h,3,27,'1','1',h,'automatic','approved',now()+interval '1 hour');

    BEGIN
        UPDATE v_ai_proposals SET decision='approved' WHERE proposal_id=selected_proposal;
        RAISE EXCEPTION 'Missing reviewer passed manual approval CHECK';
    EXCEPTION WHEN check_violation THEN NULL;
    END;
    UPDATE v_ai_proposals SET decision='approved', approver_subject='reviewer/15', decided_at=now()
    WHERE proposal_id=selected_proposal AND decision='pending';
    ASSERT FOUND;
    UPDATE v_ai_proposals SET decision='rejected', approver_subject='reviewer/16', decided_at=now()
    WHERE proposal_id=selected_proposal AND decision='pending';
    ASSERT NOT FOUND;
    BEGIN
        INSERT INTO v_ai_owner_routes VALUES
            ('62','project','user/42',selected_node::text,'c1',selected_proposal,h,'single_text',NULL,now(),now()+interval '1 day');
        RAISE EXCEPTION 'Cross-tenant proposal was routable';
    EXCEPTION WHEN foreign_key_violation THEN NULL;
    END;
    INSERT INTO v_ai_owner_routes VALUES
        ('61','project','user/42',selected_node::text,'c1',selected_proposal,h,'single_text',NULL,now(),now()+interval '1 day'),
        ('62','project','user/42',selected_node::text,'c1',other_proposal,h,'single_text',NULL,now(),now()+interval '1 day');
    BEGIN
        INSERT INTO v_ai_owner_routes VALUES
            ('61','project','user/42',selected_node::text,'c2',selected_proposal,h,'single_text',NULL,now(),now()+interval '1 day');
        RAISE EXCEPTION 'Second key accepted for the same proposal';
    EXCEPTION WHEN unique_violation THEN NULL;
    END;
    INSERT INTO v_ai_dispatches(proposal_id) VALUES (selected_proposal);
    INSERT INTO v_ai_text_commands (
        tenant_id,project_scope,effective_subject,resource_id,document_id,command_id,proposal_id,request_fingerprint,
        state,receipt_id,source_generation,source_position,dedupe_until
    ) VALUES (61,'project','user/42',selected_node,selected_doc,'c1',selected_proposal,h,'committed',selected_receipt,3,29,now()+interval '7 days');
    INSERT INTO v_ai_text_command_evidence VALUES (selected_receipt,'text_and_marks','fixture',h,now()+interval '1 day');
    BEGIN
        INSERT INTO v_ai_text_commands (
            tenant_id,project_scope,effective_subject,resource_id,document_id,command_id,proposal_id,request_fingerprint,
            state,receipt_id,source_generation,source_position,dedupe_until
        ) VALUES (61,'project','user/42',selected_node,selected_doc,'c1',uuidv7(),h,'committed',uuidv7(),3,30,now()+interval '7 days');
        RAISE EXCEPTION 'Duplicate domain command accepted';
    EXCEPTION WHEN unique_violation THEN NULL;
    END;

    INSERT INTO v_ai_search_pages VALUES (selected_snapshot,'61','project','user/42',h,1,now(),now()+interval '1 hour');
    INSERT INTO v_ai_search_items VALUES (selected_snapshot,0,selected_node::text,'text');
    DELETE FROM v_ai_search_pages WHERE snapshot_id=selected_snapshot;
    ASSERT NOT EXISTS (SELECT FROM v_ai_search_items WHERE snapshot_id=selected_snapshot);

    INSERT INTO v_ai_runs (run_id,tenant_id,project_scope,effective_subject,channel,source_event_id,profile_revision)
    VALUES (selected_run,'61','project','user/42','test','prompt-one','1');
    INSERT INTO v_ai_run_inputs VALUES (selected_run,'fixture',h,now()+interval '1 day');
    INSERT INTO v_ai_run_steps (run_id,step_id,operation,state) VALUES (selected_run,1,'office.search','finished');
    DELETE FROM v_ai_runs WHERE run_id=selected_run;
    ASSERT NOT EXISTS (SELECT FROM v_ai_run_steps WHERE run_id=selected_run);
    ASSERT NOT EXISTS (SELECT FROM v_ai_run_inputs WHERE run_id=selected_run);

    INSERT INTO v_ai_text_projection_heads (tenant_id,project_scope,document_id,source_generation,source_position,build_id,projector_version)
    VALUES (61,'project',selected_doc,3,29,uuidv7(),1);
    INSERT INTO v_ai_text_blocks
        (tenant_id,project_scope,document_id,source_generation,build_id,block_id,ordinal,node_kind,heading_path,search_text,formatted_node,source_hash)
    SELECT tenant_id,project_scope,document_id,source_generation,build_id,'p42',0,'paragraph','[]','30','{"type":"paragraph"}',h
    FROM v_ai_text_projection_heads WHERE document_id=selected_doc;
    DELETE FROM v_ai_text_command_evidence WHERE receipt_id=selected_receipt;
    ASSERT EXISTS (SELECT FROM v_ai_text_commands WHERE receipt_id=selected_receipt);
    DELETE FROM v_documents WHERE id=selected_doc;
    ASSERT NOT EXISTS (SELECT FROM v_ai_text_projection_heads WHERE document_id=selected_doc);
    ASSERT NOT EXISTS (SELECT FROM v_ai_text_blocks WHERE document_id=selected_doc);
    ASSERT EXISTS (SELECT FROM v_ai_text_commands WHERE receipt_id=selected_receipt AND document_id IS NULL AND resource_id=selected_node);
END
$check$;
ROLLBACK;
