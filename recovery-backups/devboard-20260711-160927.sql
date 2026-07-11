--
-- PostgreSQL database dump
--

\restrict e3vtLNWba5L2eS5Vxic9H92X7a8bLJfxo8FRupXzjOo2fwMt5l9WibPYEhOcxI4

-- Dumped from database version 16.14 (Debian 16.14-1.pgdg12+1)
-- Dumped by pg_dump version 16.14 (Debian 16.14-1.pgdg12+1)

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

--
-- Name: vector; Type: EXTENSION; Schema: -; Owner: -
--

CREATE EXTENSION IF NOT EXISTS vector WITH SCHEMA public;


--
-- Name: EXTENSION vector; Type: COMMENT; Schema: -; Owner: 
--

COMMENT ON EXTENSION vector IS 'vector data type and ivfflat and hnsw access methods';


--
-- Name: hades_search_documents_tsvector_trigger(); Type: FUNCTION; Schema: public; Owner: devboard
--

CREATE FUNCTION public.hades_search_documents_tsvector_trigger() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
            BEGIN
                NEW.search_vector := to_tsvector('english', coalesce(NEW.title, '') || ' ' || coalesce(NEW.body, '') || ' ' || coalesce(NEW.source_schema, ''));
                RETURN NEW;
            END;
            $$;


ALTER FUNCTION public.hades_search_documents_tsvector_trigger() OWNER TO devboard;

SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- Name: agent_chat_messages; Type: TABLE; Schema: public; Owner: devboard
--

CREATE TABLE public.agent_chat_messages (
    id character(26) NOT NULL,
    agent_chat_thread_id character(26) NOT NULL,
    author_user_id bigint,
    assistant_run_id character(26),
    agent_work_item_id character(26),
    role character varying(255) NOT NULL,
    content text NOT NULL,
    metadata json NOT NULL,
    created_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


ALTER TABLE public.agent_chat_messages OWNER TO devboard;

--
-- Name: agent_chat_threads; Type: TABLE; Schema: public; Owner: devboard
--

CREATE TABLE public.agent_chat_threads (
    id character(26) NOT NULL,
    project_id character(26) NOT NULL,
    repository_id character(26),
    task_id character(26),
    created_by_user_id bigint,
    agent_key character varying(255) NOT NULL,
    title character varying(255) NOT NULL,
    status character varying(255) DEFAULT 'active'::character varying NOT NULL,
    latest_agent_work_item_id character(26),
    latest_assistant_run_id character(26),
    last_message_at timestamp(0) without time zone,
    metadata json NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    archived_at timestamp(0) without time zone,
    archived_by_user_id bigint,
    archive_reason text
);


ALTER TABLE public.agent_chat_threads OWNER TO devboard;

--
-- Name: agent_work_item_events; Type: TABLE; Schema: public; Owner: devboard
--

CREATE TABLE public.agent_work_item_events (
    id character(26) NOT NULL,
    agent_work_item_id character(26) NOT NULL,
    actor_user_id bigint,
    actor_device_id character(26),
    event_type character varying(255) NOT NULL,
    message text,
    payload json NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.agent_work_item_events OWNER TO devboard;

--
-- Name: agent_work_item_leases; Type: TABLE; Schema: public; Owner: devboard
--

CREATE TABLE public.agent_work_item_leases (
    id character(26) NOT NULL,
    agent_work_item_id character(26) NOT NULL,
    device_id character(26) NOT NULL,
    lease_token_hash character varying(255) NOT NULL,
    expires_at timestamp(0) without time zone NOT NULL,
    released_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.agent_work_item_leases OWNER TO devboard;

--
-- Name: agent_work_items; Type: TABLE; Schema: public; Owner: devboard
--

CREATE TABLE public.agent_work_items (
    id character(26) NOT NULL,
    project_id character(26) NOT NULL,
    repository_id character(26),
    task_id character(26),
    requested_by_user_id bigint,
    assigned_agent_key character varying(255) NOT NULL,
    status character varying(255) DEFAULT 'draft'::character varying NOT NULL,
    priority character varying(255) DEFAULT 'normal'::character varying NOT NULL,
    title character varying(255) NOT NULL,
    prompt text NOT NULL,
    payload json NOT NULL,
    requires_memory_entry boolean DEFAULT true NOT NULL,
    result_memory_entry_id character(26),
    claimed_by_device_id character(26),
    claimed_at timestamp(0) without time zone,
    heartbeat_at timestamp(0) without time zone,
    completed_at timestamp(0) without time zone,
    failed_at timestamp(0) without time zone,
    canceled_at timestamp(0) without time zone,
    failure_reason text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    archived_at timestamp(0) without time zone,
    archived_by_user_id bigint,
    archive_reason text
);


ALTER TABLE public.agent_work_items OWNER TO devboard;

--
-- Name: ai_agent_profiles; Type: TABLE; Schema: public; Owner: devboard
--

CREATE TABLE public.ai_agent_profiles (
    id character(26) NOT NULL,
    agent_key character varying(255) NOT NULL,
    display_name character varying(255) NOT NULL,
    description text NOT NULL,
    agent_type character varying(255) NOT NULL,
    delegation_mode character varying(255) DEFAULT 'controlled_registry'::character varying NOT NULL,
    parent_agent_key character varying(255),
    default_model_profile_id character(26),
    requires_human_approval boolean DEFAULT true NOT NULL,
    enabled boolean DEFAULT true NOT NULL,
    allowed_tools json NOT NULL,
    output_schema json NOT NULL,
    trigger_events json NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    visibility_scope character varying(255) DEFAULT 'global'::character varying NOT NULL
);


ALTER TABLE public.ai_agent_profiles OWNER TO devboard;

--
-- Name: ai_agent_project_visibility; Type: TABLE; Schema: public; Owner: devboard
--

CREATE TABLE public.ai_agent_project_visibility (
    id character(26) NOT NULL,
    ai_agent_profile_id character(26) NOT NULL,
    project_id character(26) NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.ai_agent_project_visibility OWNER TO devboard;

--
-- Name: ai_model_profiles; Type: TABLE; Schema: public; Owner: devboard
--

CREATE TABLE public.ai_model_profiles (
    id character(26) NOT NULL,
    provider_id character(26) NOT NULL,
    profile_key character varying(255) NOT NULL,
    display_name character varying(255) NOT NULL,
    model_name character varying(255) NOT NULL,
    runtime_profile character varying(255) DEFAULT 'compact_readonly'::character varying NOT NULL,
    max_context integer,
    max_output_tokens integer DEFAULT 2048 NOT NULL,
    temperature numeric(4,2) DEFAULT '0'::numeric NOT NULL,
    timeout_seconds integer DEFAULT 30 NOT NULL,
    enabled boolean DEFAULT false NOT NULL,
    metadata json NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.ai_model_profiles OWNER TO devboard;

--
-- Name: ai_model_providers; Type: TABLE; Schema: public; Owner: devboard
--

CREATE TABLE public.ai_model_providers (
    id character(26) NOT NULL,
    provider_key character varying(255) NOT NULL,
    display_name character varying(255) NOT NULL,
    provider_type character varying(255) DEFAULT 'openai_compatible'::character varying NOT NULL,
    base_url character varying(255),
    encrypted_api_key text,
    api_key_last_four character varying(16),
    api_key_updated_at timestamp(0) without time zone,
    enabled boolean DEFAULT false NOT NULL,
    metadata json NOT NULL,
    created_by_user_id bigint,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.ai_model_providers OWNER TO devboard;

--
-- Name: api_tokens; Type: TABLE; Schema: public; Owner: devboard
--

CREATE TABLE public.api_tokens (
    id character(26) NOT NULL,
    token_prefix character varying(255) NOT NULL,
    token_hash character varying(255) NOT NULL,
    user_id bigint NOT NULL,
    device_id character(26),
    name character varying(255) NOT NULL,
    scopes json NOT NULL,
    expires_at timestamp(0) without time zone,
    revoked_at timestamp(0) without time zone,
    last_used_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    project_id character(26),
    hades_agent_id character(26),
    device_signing_secret_hash character varying(255)
);


ALTER TABLE public.api_tokens OWNER TO devboard;

--
-- Name: artifacts; Type: TABLE; Schema: public; Owner: devboard
--

CREATE TABLE public.artifacts (
    id character(26) NOT NULL,
    project_id character(26) NOT NULL,
    repository_id character(26),
    run_id character(26),
    artifact_type character varying(255) NOT NULL,
    storage_path character varying(255) NOT NULL,
    sha256 character varying(64) NOT NULL,
    size_bytes bigint NOT NULL,
    mime_type character varying(255) NOT NULL,
    schema_version character varying(255) NOT NULL,
    status character varying(255) DEFAULT 'uploading'::character varying NOT NULL,
    producer character varying(255) NOT NULL,
    metadata json NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.artifacts OWNER TO devboard;

--
-- Name: assistant_messages; Type: TABLE; Schema: public; Owner: devboard
--

CREATE TABLE public.assistant_messages (
    id character(26) NOT NULL,
    assistant_run_id character(26) NOT NULL,
    role character varying(255) NOT NULL,
    content text NOT NULL,
    metadata json NOT NULL,
    created_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


ALTER TABLE public.assistant_messages OWNER TO devboard;

--
-- Name: assistant_runs; Type: TABLE; Schema: public; Owner: devboard
--

CREATE TABLE public.assistant_runs (
    id character(26) NOT NULL,
    project_id character(26) NOT NULL,
    agent_profile_id character(26),
    target_type character varying(255) NOT NULL,
    target_id character varying(255) NOT NULL,
    triggered_by_user_id bigint,
    status character varying(255) NOT NULL,
    model_provider_id character(26),
    model_profile_id character(26),
    context_hash character varying(64) NOT NULL,
    context_snapshot json NOT NULL,
    result_summary text,
    metadata json NOT NULL,
    started_at timestamp(0) without time zone NOT NULL,
    finished_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.assistant_runs OWNER TO devboard;

--
-- Name: assistant_suggestions; Type: TABLE; Schema: public; Owner: devboard
--

CREATE TABLE public.assistant_suggestions (
    id character(26) NOT NULL,
    assistant_run_id character(26) NOT NULL,
    project_id character(26) NOT NULL,
    target_type character varying(255) NOT NULL,
    target_id character varying(255) NOT NULL,
    suggestion_type character varying(255) NOT NULL,
    title character varying(255) NOT NULL,
    body_markdown text NOT NULL,
    structured_payload json NOT NULL,
    evidence_refs json NOT NULL,
    confidence numeric(4,2) DEFAULT '0'::numeric NOT NULL,
    approval_required boolean DEFAULT true NOT NULL,
    status character varying(255) DEFAULT 'pending'::character varying NOT NULL,
    created_by_user_id bigint,
    resolved_by_user_id bigint,
    resolved_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.assistant_suggestions OWNER TO devboard;

--
-- Name: audit_chain_heads; Type: TABLE; Schema: public; Owner: devboard
--

CREATE TABLE public.audit_chain_heads (
    chain_key character varying(255) NOT NULL,
    last_sequence bigint NOT NULL,
    last_hash character(64),
    updated_at timestamp(0) without time zone NOT NULL
);


ALTER TABLE public.audit_chain_heads OWNER TO devboard;

--
-- Name: audit_logs; Type: TABLE; Schema: public; Owner: devboard
--

CREATE TABLE public.audit_logs (
    id character(26) NOT NULL,
    actor_user_id bigint,
    actor_device_id character(26),
    actor_type character varying(255) NOT NULL,
    action character varying(255) NOT NULL,
    target_type character varying(255),
    target_id character varying(255),
    ip_address character varying(45),
    user_agent text,
    payload json NOT NULL,
    created_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    prev_hash character varying(64),
    row_hash character varying(64) NOT NULL,
    sequence bigint NOT NULL,
    chain_version smallint NOT NULL,
    actor_user_ref character varying(255),
    actor_device_ref character varying(255),
    CONSTRAINT audit_logs_first_prev_hash_check CHECK ((((sequence = 1) AND (prev_hash IS NULL)) OR ((sequence > 1) AND (prev_hash IS NOT NULL)))),
    CONSTRAINT audit_logs_row_hash_length_check CHECK ((length((row_hash)::text) = 64))
);


ALTER TABLE public.audit_logs OWNER TO devboard;

--
-- Name: cache; Type: TABLE; Schema: public; Owner: devboard
--

CREATE TABLE public.cache (
    key character varying(255) NOT NULL,
    value text NOT NULL,
    expiration bigint NOT NULL
);


ALTER TABLE public.cache OWNER TO devboard;

--
-- Name: cache_locks; Type: TABLE; Schema: public; Owner: devboard
--

CREATE TABLE public.cache_locks (
    key character varying(255) NOT NULL,
    owner character varying(255) NOT NULL,
    expiration bigint NOT NULL
);


ALTER TABLE public.cache_locks OWNER TO devboard;

--
-- Name: delta_syncs; Type: TABLE; Schema: public; Owner: devboard
--

CREATE TABLE public.delta_syncs (
    id character(26) NOT NULL,
    project_id character(26) NOT NULL,
    repository_id character(26) NOT NULL,
    local_workspace_id character(26) NOT NULL,
    run_id character(26) NOT NULL,
    status character varying(255) DEFAULT 'started'::character varying NOT NULL,
    base_snapshot_id character(26),
    new_snapshot_id character(26),
    branch character varying(255) NOT NULL,
    base_sha character varying(255) NOT NULL,
    head_sha character varying(255),
    dirty_status character varying(255) NOT NULL,
    changed_file_count integer DEFAULT 0 NOT NULL,
    risk_level character varying(255) DEFAULT 'low'::character varying NOT NULL,
    started_at timestamp(0) without time zone NOT NULL,
    finished_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.delta_syncs OWNER TO devboard;

--
-- Name: devices; Type: TABLE; Schema: public; Owner: devboard
--

CREATE TABLE public.devices (
    id character(26) NOT NULL,
    user_id bigint NOT NULL,
    name character varying(255) NOT NULL,
    fingerprint_hash character varying(255) NOT NULL,
    platform_os character varying(255) NOT NULL,
    platform_arch character varying(255) NOT NULL,
    plugin_version character varying(255) NOT NULL,
    last_seen_at timestamp(0) without time zone,
    status character varying(255) DEFAULT 'pending'::character varying NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    signing_secret_hash character varying(64)
);


ALTER TABLE public.devices OWNER TO devboard;

--
-- Name: failed_jobs; Type: TABLE; Schema: public; Owner: devboard
--

CREATE TABLE public.failed_jobs (
    id bigint NOT NULL,
    uuid character varying(255) NOT NULL,
    connection character varying(255) NOT NULL,
    queue character varying(255) NOT NULL,
    payload text NOT NULL,
    exception text NOT NULL,
    failed_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


ALTER TABLE public.failed_jobs OWNER TO devboard;

--
-- Name: failed_jobs_id_seq; Type: SEQUENCE; Schema: public; Owner: devboard
--

CREATE SEQUENCE public.failed_jobs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.failed_jobs_id_seq OWNER TO devboard;

--
-- Name: failed_jobs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: devboard
--

ALTER SEQUENCE public.failed_jobs_id_seq OWNED BY public.failed_jobs.id;


--
-- Name: genesis_imports; Type: TABLE; Schema: public; Owner: devboard
--

CREATE TABLE public.genesis_imports (
    id character(26) NOT NULL,
    project_id character(26) NOT NULL,
    repository_id character(26) NOT NULL,
    local_workspace_id character(26) NOT NULL,
    run_id character(26) NOT NULL,
    status character varying(255) DEFAULT 'started'::character varying NOT NULL,
    manifest_artifact_id character(26),
    snapshot_id character(26),
    base_branch character varying(255) NOT NULL,
    base_sha character varying(255) NOT NULL,
    head_sha character varying(255) NOT NULL,
    started_at timestamp(0) without time zone NOT NULL,
    finished_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.genesis_imports OWNER TO devboard;

--
-- Name: hades_agent_artifacts; Type: TABLE; Schema: public; Owner: devboard
--

CREATE TABLE public.hades_agent_artifacts (
    id character(26) NOT NULL,
    project_id character(26) NOT NULL,
    hades_agent_id character(26),
    workspace_binding_id character(26) NOT NULL,
    job_id character(26),
    schema character varying(255) NOT NULL,
    artifact json NOT NULL,
    sha256 character varying(64),
    truncated boolean DEFAULT false NOT NULL,
    redactions integer DEFAULT 0 NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.hades_agent_artifacts OWNER TO devboard;

--
-- Name: hades_agent_job_events; Type: TABLE; Schema: public; Owner: devboard
--

CREATE TABLE public.hades_agent_job_events (
    id character(26) NOT NULL,
    job_id character(26) NOT NULL,
    event_type character varying(255) NOT NULL,
    status character varying(255),
    payload json NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.hades_agent_job_events OWNER TO devboard;

--
-- Name: hades_agent_jobs; Type: TABLE; Schema: public; Owner: devboard
--

CREATE TABLE public.hades_agent_jobs (
    id character(26) NOT NULL,
    project_id character(26) NOT NULL,
    hades_agent_id character(26),
    workspace_binding_id character(26) NOT NULL,
    idempotency_key character varying(255),
    capability character varying(255) NOT NULL,
    status character varying(255) DEFAULT 'queued'::character varying NOT NULL,
    policy character varying(255) DEFAULT 'auto'::character varying NOT NULL,
    priority character varying(255) DEFAULT 'normal'::character varying NOT NULL,
    payload json NOT NULL,
    result json,
    requires_confirmation boolean DEFAULT false NOT NULL,
    deadline_at timestamp(0) without time zone,
    available_at timestamp(0) without time zone,
    claimed_at timestamp(0) without time zone,
    started_at timestamp(0) without time zone,
    completed_at timestamp(0) without time zone,
    failed_at timestamp(0) without time zone,
    cancelled_at timestamp(0) without time zone,
    error_code character varying(255),
    error_message text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    repository_id character(26),
    requested_by_user_id bigint,
    job_type character varying(255),
    result_applied_at timestamp(0) without time zone
);


ALTER TABLE public.hades_agent_jobs OWNER TO devboard;

--
-- Name: hades_agent_tokens; Type: TABLE; Schema: public; Owner: devboard
--

CREATE TABLE public.hades_agent_tokens (
    id character(26) NOT NULL,
    project_id character(26) NOT NULL,
    hades_agent_id character(26) NOT NULL,
    token_prefix character varying(255) NOT NULL,
    token_hash character varying(255) NOT NULL,
    name character varying(255) NOT NULL,
    scopes json NOT NULL,
    expires_at timestamp(0) without time zone,
    revoked_at timestamp(0) without time zone,
    last_used_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.hades_agent_tokens OWNER TO devboard;

--
-- Name: hades_agents; Type: TABLE; Schema: public; Owner: devboard
--

CREATE TABLE public.hades_agents (
    id character(26) NOT NULL,
    project_id character(26) NOT NULL,
    external_agent_id character varying(255) NOT NULL,
    label character varying(255) NOT NULL,
    platform character varying(255) DEFAULT 'unknown'::character varying NOT NULL,
    version character varying(255) DEFAULT 'unknown'::character varying NOT NULL,
    declared_capabilities json NOT NULL,
    effective_capabilities json NOT NULL,
    last_seen_at timestamp(0) without time zone,
    status character varying(255) DEFAULT 'active'::character varying NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.hades_agents OWNER TO devboard;

--
-- Name: hades_bootstrap_tokens; Type: TABLE; Schema: public; Owner: devboard
--

CREATE TABLE public.hades_bootstrap_tokens (
    id character(26) NOT NULL,
    project_id character(26) NOT NULL,
    token_prefix character varying(255) NOT NULL,
    token_hash character varying(255) NOT NULL,
    name character varying(255) NOT NULL,
    scopes json NOT NULL,
    allowed_capabilities json,
    expires_at timestamp(0) without time zone,
    revoked_at timestamp(0) without time zone,
    last_used_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.hades_bootstrap_tokens OWNER TO devboard;

--
-- Name: hades_bug_evidence_items; Type: TABLE; Schema: public; Owner: devboard
--

CREATE TABLE public.hades_bug_evidence_items (
    id character(26) NOT NULL,
    project_id character(26) NOT NULL,
    bug_report_id character(26),
    hades_agent_id character(26),
    workspace_binding_id character(26) NOT NULL,
    kind character varying(255) NOT NULL,
    summary text NOT NULL,
    payload json NOT NULL,
    source character varying(255),
    sha256 character varying(64) NOT NULL,
    redactions integer DEFAULT 0 NOT NULL,
    retention_class character varying(255) DEFAULT 'runtime_evidence'::character varying NOT NULL,
    occurred_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.hades_bug_evidence_items OWNER TO devboard;

--
-- Name: hades_bug_reports; Type: TABLE; Schema: public; Owner: devboard
--

CREATE TABLE public.hades_bug_reports (
    id character(26) NOT NULL,
    project_id character(26) NOT NULL,
    hades_agent_id character(26),
    workspace_binding_id character(26) NOT NULL,
    title character varying(255) NOT NULL,
    symptom text NOT NULL,
    severity character varying(255) DEFAULT 'unknown'::character varying NOT NULL,
    status character varying(255) DEFAULT 'open'::character varying NOT NULL,
    environment json,
    affected_refs json,
    observed_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.hades_bug_reports OWNER TO devboard;

--
-- Name: hades_causal_packs; Type: TABLE; Schema: public; Owner: devboard
--

CREATE TABLE public.hades_causal_packs (
    id character(26) NOT NULL,
    project_id character(26) NOT NULL,
    bug_report_id character(26),
    hades_agent_id character(26),
    workspace_binding_id character(26) NOT NULL,
    pack_key character varying(64) NOT NULL,
    bug_id character varying(191),
    root_cause_id character varying(191) NOT NULL,
    bug_class character varying(128),
    failure_classification character varying(128),
    affected_refs json,
    freshness json,
    awareness json,
    evidence_refs json,
    graph_refs json,
    source_slice_refs json,
    replay json,
    status character varying(64) DEFAULT 'invalid'::character varying NOT NULL,
    blockers json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.hades_causal_packs OWNER TO devboard;

--
-- Name: hades_diagnosis_reports; Type: TABLE; Schema: public; Owner: devboard
--

CREATE TABLE public.hades_diagnosis_reports (
    id character(26) NOT NULL,
    project_id character(26) NOT NULL,
    bug_report_id character(26),
    hades_agent_id character(26),
    workspace_binding_id character(26) NOT NULL,
    status character varying(255) DEFAULT 'draft'::character varying NOT NULL,
    confidence character varying(255) DEFAULT 'insufficient'::character varying NOT NULL,
    root_cause text NOT NULL,
    mechanism text,
    evidence_refs json,
    freshness json,
    payload json,
    redactions integer DEFAULT 0 NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.hades_diagnosis_reports OWNER TO devboard;

--
-- Name: hades_doctor_reports; Type: TABLE; Schema: public; Owner: devboard
--

CREATE TABLE public.hades_doctor_reports (
    id character(26) NOT NULL,
    project_id character(26) NOT NULL,
    hades_agent_id character(26),
    workspace_binding_id character(26),
    status character varying(255) NOT NULL,
    payload json NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.hades_doctor_reports OWNER TO devboard;

--
-- Name: hades_evidence_packs; Type: TABLE; Schema: public; Owner: devboard
--

CREATE TABLE public.hades_evidence_packs (
    id character(26) NOT NULL,
    project_id character(26) NOT NULL,
    bug_report_id character(26),
    hades_agent_id character(26),
    workspace_binding_id character(26) NOT NULL,
    title character varying(512) NOT NULL,
    summary text NOT NULL,
    evidence_refs json,
    graph_refs json,
    source_slice_ids json,
    payload json,
    sha256 character varying(64) NOT NULL,
    redactions integer DEFAULT 0 NOT NULL,
    retention_class character varying(255) DEFAULT 'diagnosis_evidence'::character varying NOT NULL,
    head_commit character varying(80),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.hades_evidence_packs OWNER TO devboard;

--
-- Name: hades_memory_proposals; Type: TABLE; Schema: public; Owner: devboard
--

CREATE TABLE public.hades_memory_proposals (
    id character(26) NOT NULL,
    project_id character(26) NOT NULL,
    hades_agent_id character(26) NOT NULL,
    workspace_binding_id character(26) NOT NULL,
    local_proposal_id character varying(255),
    action character varying(255) NOT NULL,
    intent character varying(255) NOT NULL,
    summary text NOT NULL,
    provenance json NOT NULL,
    base_version character varying(255),
    target_memory_entry_id character(26),
    memory_entry_id character(26),
    status character varying(255) DEFAULT 'pending'::character varying NOT NULL,
    reason_code character varying(255),
    reason_message text,
    decided_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.hades_memory_proposals OWNER TO devboard;

--
-- Name: hades_persephone_agent_messages; Type: TABLE; Schema: public; Owner: devboard
--

CREATE TABLE public.hades_persephone_agent_messages (
    id character(26) NOT NULL,
    project_id character(26) NOT NULL,
    sender_agent_id character varying(191) NOT NULL,
    target_agent_id character varying(191) NOT NULL,
    target_workspace_binding_id character(26),
    schema character varying(191) NOT NULL,
    message_id character varying(191) NOT NULL,
    correlation_id character varying(191) NOT NULL,
    causation_id character varying(191),
    remote_task_id character varying(191),
    remote_task_version character varying(191),
    message_type character varying(64) NOT NULL,
    effect character varying(64) NOT NULL,
    capability character varying(191) NOT NULL,
    expires_at bigint NOT NULL,
    payload jsonb NOT NULL,
    envelope jsonb NOT NULL,
    envelope_hash character(64) NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.hades_persephone_agent_messages OWNER TO devboard;

--
-- Name: hades_persephone_events; Type: TABLE; Schema: public; Owner: devboard
--

CREATE TABLE public.hades_persephone_events (
    id character(26) NOT NULL,
    project_id character(26) NOT NULL,
    hades_agent_id character(26),
    workspace_binding_id character(26),
    event_type character varying(255) NOT NULL,
    payload json NOT NULL,
    read_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.hades_persephone_events OWNER TO devboard;

--
-- Name: hades_search_documents; Type: TABLE; Schema: public; Owner: devboard
--

CREATE TABLE public.hades_search_documents (
    id character(26) NOT NULL,
    project_id character(26) NOT NULL,
    workspace_binding_id character(26),
    domain character varying(255) NOT NULL,
    kind character varying(255) NOT NULL,
    source_table character varying(255) NOT NULL,
    source_id character varying(255) NOT NULL,
    source_schema character varying(255),
    title character varying(255) DEFAULT ''::character varying NOT NULL,
    body text NOT NULL,
    metadata json,
    checksum character varying(64),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    search_vector tsvector,
    embedding public.vector(1536),
    embedding_status character varying(255),
    embedding_model character varying(255),
    embedding_dimensions integer,
    embedding_checksum character varying(64),
    embedding_updated_at timestamp(0) without time zone
);


ALTER TABLE public.hades_search_documents OWNER TO devboard;

--
-- Name: hades_source_slice_candidates; Type: TABLE; Schema: public; Owner: devboard
--

CREATE TABLE public.hades_source_slice_candidates (
    id bigint NOT NULL,
    project_id character(26) NOT NULL,
    workspace_binding_id character(26) NOT NULL,
    candidate_key character varying(64) NOT NULL,
    path character varying(1024) NOT NULL,
    start_line integer NOT NULL,
    end_line integer NOT NULL,
    symbol character varying(512),
    reason character varying(128) NOT NULL,
    priority integer DEFAULT 500 NOT NULL,
    head_commit character varying(80),
    status character varying(64) DEFAULT 'pending'::character varying NOT NULL,
    job_id character(26),
    source_slice_id character(26),
    metadata json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.hades_source_slice_candidates OWNER TO devboard;

--
-- Name: hades_source_slice_candidates_id_seq; Type: SEQUENCE; Schema: public; Owner: devboard
--

CREATE SEQUENCE public.hades_source_slice_candidates_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.hades_source_slice_candidates_id_seq OWNER TO devboard;

--
-- Name: hades_source_slice_candidates_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: devboard
--

ALTER SEQUENCE public.hades_source_slice_candidates_id_seq OWNED BY public.hades_source_slice_candidates.id;


--
-- Name: hades_source_slices; Type: TABLE; Schema: public; Owner: devboard
--

CREATE TABLE public.hades_source_slices (
    id character(26) NOT NULL,
    project_id character(26) NOT NULL,
    hades_agent_id character(26),
    workspace_binding_id character(26) NOT NULL,
    job_id character(26),
    path character varying(1024) NOT NULL,
    start_line integer NOT NULL,
    end_line integer NOT NULL,
    language character varying(64),
    symbol character varying(512),
    head_commit character varying(80),
    sha256 character varying(64) NOT NULL,
    content_redacted text NOT NULL,
    redactions integer DEFAULT 0 NOT NULL,
    truncated boolean DEFAULT false NOT NULL,
    retention_class character varying(255) DEFAULT 'source_slice'::character varying NOT NULL,
    policy character varying(255) DEFAULT 'manual_review'::character varying NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.hades_source_slices OWNER TO devboard;

--
-- Name: hades_workspace_bindings; Type: TABLE; Schema: public; Owner: devboard
--

CREATE TABLE public.hades_workspace_bindings (
    id character(26) NOT NULL,
    project_id character(26) NOT NULL,
    hades_agent_id character(26) NOT NULL,
    external_agent_id character varying(255) NOT NULL,
    local_project_id character varying(255),
    workspace_fingerprint character varying(255) NOT NULL,
    display_path character varying(512) NOT NULL,
    git_remote_display character varying(512),
    git_remote_hash character varying(255),
    head_commit character varying(80),
    platform character varying(255),
    status character varying(255) DEFAULT 'linked'::character varying NOT NULL,
    linked_at timestamp(0) without time zone,
    unlinked_at timestamp(0) without time zone,
    last_seen_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.hades_workspace_bindings OWNER TO devboard;

--
-- Name: job_batches; Type: TABLE; Schema: public; Owner: devboard
--

CREATE TABLE public.job_batches (
    id character varying(255) NOT NULL,
    name character varying(255) NOT NULL,
    total_jobs integer NOT NULL,
    pending_jobs integer NOT NULL,
    failed_jobs integer NOT NULL,
    failed_job_ids text NOT NULL,
    options text,
    cancelled_at integer,
    created_at integer NOT NULL,
    finished_at integer
);


ALTER TABLE public.job_batches OWNER TO devboard;

--
-- Name: jobs; Type: TABLE; Schema: public; Owner: devboard
--

CREATE TABLE public.jobs (
    id bigint NOT NULL,
    queue character varying(255) NOT NULL,
    payload text NOT NULL,
    attempts smallint NOT NULL,
    reserved_at integer,
    available_at integer NOT NULL,
    created_at integer NOT NULL
);


ALTER TABLE public.jobs OWNER TO devboard;

--
-- Name: jobs_id_seq; Type: SEQUENCE; Schema: public; Owner: devboard
--

CREATE SEQUENCE public.jobs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.jobs_id_seq OWNER TO devboard;

--
-- Name: jobs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: devboard
--

ALTER SEQUENCE public.jobs_id_seq OWNED BY public.jobs.id;


--
-- Name: kanban_boards; Type: TABLE; Schema: public; Owner: devboard
--

CREATE TABLE public.kanban_boards (
    id character(26) NOT NULL,
    project_id character(26) NOT NULL,
    name character varying(255) NOT NULL,
    is_default boolean DEFAULT false NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.kanban_boards OWNER TO devboard;

--
-- Name: kanban_columns; Type: TABLE; Schema: public; Owner: devboard
--

CREATE TABLE public.kanban_columns (
    id character(26) NOT NULL,
    board_id character(26) NOT NULL,
    name character varying(255) NOT NULL,
    "position" integer NOT NULL,
    status_key character varying(255) NOT NULL,
    wip_limit integer,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.kanban_columns OWNER TO devboard;

--
-- Name: local_workspaces; Type: TABLE; Schema: public; Owner: devboard
--

CREATE TABLE public.local_workspaces (
    id character(26) NOT NULL,
    repository_id character(26) NOT NULL,
    device_id character(26) NOT NULL,
    local_root_hash character varying(255) NOT NULL,
    display_path character varying(255) NOT NULL,
    current_branch character varying(255) NOT NULL,
    last_head_sha character varying(255),
    dirty_status character varying(255) DEFAULT 'unknown'::character varying NOT NULL,
    last_snapshot_id character(26),
    last_seen_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    remote_name character varying(255),
    remote_url_host character varying(255),
    remote_url_hash character varying(255),
    upstream_branch character varying(255),
    ahead_count integer,
    behind_count integer,
    git_state_observed_at timestamp(0) without time zone
);


ALTER TABLE public.local_workspaces OWNER TO devboard;

--
-- Name: memory_import_batches; Type: TABLE; Schema: public; Owner: devboard
--

CREATE TABLE public.memory_import_batches (
    id character(26) NOT NULL,
    project_id character(26) NOT NULL,
    source_workspace_binding_id character(26),
    target_workspace_binding_id character(26) NOT NULL,
    requested_by_user_id bigint,
    requested_by_hades_agent_id character(26),
    status character varying(255) DEFAULT 'pending'::character varying NOT NULL,
    mode character varying(255) DEFAULT 'copy_as_proposals'::character varying NOT NULL,
    dedupe_strategy character varying(255) DEFAULT 'source_hash'::character varying NOT NULL,
    conflict_policy character varying(255) DEFAULT 'skip'::character varying NOT NULL,
    reason text,
    source_payload json,
    completed_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    cancelled_at timestamp(0) without time zone,
    cancelled_by_user_id bigint,
    cancel_reason text
);


ALTER TABLE public.memory_import_batches OWNER TO devboard;

--
-- Name: memory_import_items; Type: TABLE; Schema: public; Owner: devboard
--

CREATE TABLE public.memory_import_items (
    id character(26) NOT NULL,
    batch_id character(26) NOT NULL,
    source_local_id character varying(255),
    source_hash character varying(255) NOT NULL,
    proposal_id character(26),
    target_memory_entry_id character(26),
    status character varying(255) NOT NULL,
    conflict_reason text,
    provenance json NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.memory_import_items OWNER TO devboard;

--
-- Name: migrations; Type: TABLE; Schema: public; Owner: devboard
--

CREATE TABLE public.migrations (
    id integer NOT NULL,
    migration character varying(255) NOT NULL,
    batch integer NOT NULL
);


ALTER TABLE public.migrations OWNER TO devboard;

--
-- Name: migrations_id_seq; Type: SEQUENCE; Schema: public; Owner: devboard
--

CREATE SEQUENCE public.migrations_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.migrations_id_seq OWNER TO devboard;

--
-- Name: migrations_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: devboard
--

ALTER SEQUENCE public.migrations_id_seq OWNED BY public.migrations.id;


--
-- Name: password_reset_tokens; Type: TABLE; Schema: public; Owner: devboard
--

CREATE TABLE public.password_reset_tokens (
    email character varying(255) NOT NULL,
    token character varying(255) NOT NULL,
    created_at timestamp(0) without time zone
);


ALTER TABLE public.password_reset_tokens OWNER TO devboard;

--
-- Name: permissions; Type: TABLE; Schema: public; Owner: devboard
--

CREATE TABLE public.permissions (
    id character(26) NOT NULL,
    name character varying(255) NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.permissions OWNER TO devboard;

--
-- Name: project_memory_entries; Type: TABLE; Schema: public; Owner: devboard
--

CREATE TABLE public.project_memory_entries (
    id character(26) NOT NULL,
    project_id character(26) NOT NULL,
    repository_id character(26),
    task_id character(26),
    run_id character(26),
    author_user_id bigint,
    agent_key character varying(255),
    source character varying(255) NOT NULL,
    kind character varying(255) NOT NULL,
    completeness character varying(255) DEFAULT 'complete'::character varying NOT NULL,
    summary text NOT NULL,
    payload json NOT NULL,
    occurred_at timestamp(0) without time zone NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.project_memory_entries OWNER TO devboard;

--
-- Name: project_memory_links; Type: TABLE; Schema: public; Owner: devboard
--

CREATE TABLE public.project_memory_links (
    id character(26) NOT NULL,
    memory_entry_id character(26) NOT NULL,
    target_type character varying(255) NOT NULL,
    target_id character varying(255) NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.project_memory_links OWNER TO devboard;

--
-- Name: projects; Type: TABLE; Schema: public; Owner: devboard
--

CREATE TABLE public.projects (
    id character(26) NOT NULL,
    name character varying(255) NOT NULL,
    slug character varying(255) NOT NULL,
    description text,
    status character varying(255) DEFAULT 'active'::character varying NOT NULL,
    default_code_exposure_policy character varying(255) DEFAULT 'full_code_artifacts'::character varying NOT NULL,
    created_by_user_id bigint NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    archived_at timestamp(0) without time zone,
    archived_by_user_id bigint,
    deleted_at timestamp(0) without time zone,
    deleted_by_user_id bigint,
    restored_at timestamp(0) without time zone,
    restored_by_user_id bigint
);


ALTER TABLE public.projects OWNER TO devboard;

--
-- Name: repositories; Type: TABLE; Schema: public; Owner: devboard
--

CREATE TABLE public.repositories (
    id character(26) NOT NULL,
    project_id character(26) NOT NULL,
    name character varying(255) NOT NULL,
    slug character varying(255) NOT NULL,
    default_branch character varying(255) DEFAULT 'main'::character varying NOT NULL,
    local_only boolean DEFAULT true NOT NULL,
    code_exposure_policy character varying(255) DEFAULT 'full_code_artifacts'::character varying NOT NULL,
    protected_paths json NOT NULL,
    excluded_paths json NOT NULL,
    stack_hints json NOT NULL,
    graph_enabled boolean DEFAULT true NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.repositories OWNER TO devboard;

--
-- Name: repository_task; Type: TABLE; Schema: public; Owner: devboard
--

CREATE TABLE public.repository_task (
    id character(26) NOT NULL,
    task_id character(26) NOT NULL,
    repository_id character(26) NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.repository_task OWNER TO devboard;

--
-- Name: role_user; Type: TABLE; Schema: public; Owner: devboard
--

CREATE TABLE public.role_user (
    id bigint NOT NULL,
    user_id bigint NOT NULL,
    role_id character(26) NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.role_user OWNER TO devboard;

--
-- Name: role_user_id_seq; Type: SEQUENCE; Schema: public; Owner: devboard
--

CREATE SEQUENCE public.role_user_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.role_user_id_seq OWNER TO devboard;

--
-- Name: role_user_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: devboard
--

ALTER SEQUENCE public.role_user_id_seq OWNED BY public.role_user.id;


--
-- Name: roles; Type: TABLE; Schema: public; Owner: devboard
--

CREATE TABLE public.roles (
    id character(26) NOT NULL,
    name character varying(255) NOT NULL,
    permissions json NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.roles OWNER TO devboard;

--
-- Name: run_events; Type: TABLE; Schema: public; Owner: devboard
--

CREATE TABLE public.run_events (
    id character(26) NOT NULL,
    run_id character(26) NOT NULL,
    event_type character varying(255) NOT NULL,
    severity character varying(255) DEFAULT 'info'::character varying NOT NULL,
    message text NOT NULL,
    payload json NOT NULL,
    created_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


ALTER TABLE public.run_events OWNER TO devboard;

--
-- Name: runs; Type: TABLE; Schema: public; Owner: devboard
--

CREATE TABLE public.runs (
    id character(26) NOT NULL,
    project_id character(26) NOT NULL,
    repository_id character(26),
    local_workspace_id character(26),
    task_id character(26),
    device_id character(26) NOT NULL,
    started_by_user_id bigint NOT NULL,
    runtime_profile character varying(255) NOT NULL,
    status character varying(255) NOT NULL,
    branch character varying(255) NOT NULL,
    base_branch character varying(255) NOT NULL,
    base_sha character varying(255) NOT NULL,
    head_sha character varying(255),
    summary text,
    risk_level character varying(255) DEFAULT 'low'::character varying NOT NULL,
    started_at timestamp(0) without time zone NOT NULL,
    finished_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.runs OWNER TO devboard;

--
-- Name: sessions; Type: TABLE; Schema: public; Owner: devboard
--

CREATE TABLE public.sessions (
    id character varying(255) NOT NULL,
    user_id bigint,
    ip_address character varying(45),
    user_agent text,
    payload text NOT NULL,
    last_activity integer NOT NULL
);


ALTER TABLE public.sessions OWNER TO devboard;

--
-- Name: snapshots; Type: TABLE; Schema: public; Owner: devboard
--

CREATE TABLE public.snapshots (
    id character(26) NOT NULL,
    project_id character(26) NOT NULL,
    repository_id character(26) NOT NULL,
    local_workspace_id character(26) NOT NULL,
    source_type character varying(255) NOT NULL,
    branch character varying(255) NOT NULL,
    base_sha character varying(255) NOT NULL,
    head_sha character varying(255),
    dirty_status character varying(255) NOT NULL,
    file_inventory_artifact_id character(26),
    graph_snapshot_artifact_id character(26),
    created_by_run_id character(26) NOT NULL,
    created_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


ALTER TABLE public.snapshots OWNER TO devboard;

--
-- Name: task_attachments; Type: TABLE; Schema: public; Owner: devboard
--

CREATE TABLE public.task_attachments (
    id character(26) NOT NULL,
    project_id character(26) NOT NULL,
    task_id character(26) NOT NULL,
    uploaded_by_user_id bigint,
    deleted_by_user_id bigint,
    original_name character varying(255) NOT NULL,
    stored_name character varying(255) NOT NULL,
    storage_path character varying(255) NOT NULL,
    sha256 character varying(64) NOT NULL,
    size_bytes bigint NOT NULL,
    mime_type character varying(255) NOT NULL,
    kind character varying(255) NOT NULL,
    status character varying(255) DEFAULT 'available'::character varying NOT NULL,
    scan_status character varying(255) DEFAULT 'not_scanned'::character varying NOT NULL,
    metadata json NOT NULL,
    deleted_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.task_attachments OWNER TO devboard;

--
-- Name: tasks; Type: TABLE; Schema: public; Owner: devboard
--

CREATE TABLE public.tasks (
    id character(26) NOT NULL,
    project_id character(26) NOT NULL,
    title character varying(255) NOT NULL,
    description text,
    status_column_id character(26) NOT NULL,
    priority character varying(255) DEFAULT 'normal'::character varying NOT NULL,
    risk_level character varying(255) DEFAULT 'low'::character varying NOT NULL,
    owner_user_id bigint,
    created_by_user_id bigint NOT NULL,
    due_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    acceptance_criteria json
);


ALTER TABLE public.tasks OWNER TO devboard;

--
-- Name: users; Type: TABLE; Schema: public; Owner: devboard
--

CREATE TABLE public.users (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    email character varying(255) NOT NULL,
    email_verified_at timestamp(0) without time zone,
    password character varying(255) NOT NULL,
    status character varying(255) DEFAULT 'active'::character varying NOT NULL,
    last_login_at timestamp(0) without time zone,
    remember_token character varying(100),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.users OWNER TO devboard;

--
-- Name: users_id_seq; Type: SEQUENCE; Schema: public; Owner: devboard
--

CREATE SEQUENCE public.users_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.users_id_seq OWNER TO devboard;

--
-- Name: users_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: devboard
--

ALTER SEQUENCE public.users_id_seq OWNED BY public.users.id;


--
-- Name: wiki_pages; Type: TABLE; Schema: public; Owner: devboard
--

CREATE TABLE public.wiki_pages (
    id character(26) NOT NULL,
    project_id character(26) NOT NULL,
    repository_id character(26),
    slug character varying(255) NOT NULL,
    title character varying(255) NOT NULL,
    page_type character varying(255) NOT NULL,
    current_revision_id character(26),
    source_status character varying(255) NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.wiki_pages OWNER TO devboard;

--
-- Name: wiki_revisions; Type: TABLE; Schema: public; Owner: devboard
--

CREATE TABLE public.wiki_revisions (
    id character(26) NOT NULL,
    wiki_page_id character(26) NOT NULL,
    author_user_id bigint,
    author_device_id character(26),
    producer character varying(255) NOT NULL,
    source_type character varying(255) NOT NULL,
    source_status character varying(255) NOT NULL,
    content_markdown text NOT NULL,
    evidence_refs json NOT NULL,
    created_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


ALTER TABLE public.wiki_revisions OWNER TO devboard;

--
-- Name: failed_jobs id; Type: DEFAULT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.failed_jobs ALTER COLUMN id SET DEFAULT nextval('public.failed_jobs_id_seq'::regclass);


--
-- Name: hades_source_slice_candidates id; Type: DEFAULT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.hades_source_slice_candidates ALTER COLUMN id SET DEFAULT nextval('public.hades_source_slice_candidates_id_seq'::regclass);


--
-- Name: jobs id; Type: DEFAULT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.jobs ALTER COLUMN id SET DEFAULT nextval('public.jobs_id_seq'::regclass);


--
-- Name: migrations id; Type: DEFAULT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.migrations ALTER COLUMN id SET DEFAULT nextval('public.migrations_id_seq'::regclass);


--
-- Name: role_user id; Type: DEFAULT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.role_user ALTER COLUMN id SET DEFAULT nextval('public.role_user_id_seq'::regclass);


--
-- Name: users id; Type: DEFAULT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.users ALTER COLUMN id SET DEFAULT nextval('public.users_id_seq'::regclass);


--
-- Data for Name: agent_chat_messages; Type: TABLE DATA; Schema: public; Owner: devboard
--

COPY public.agent_chat_messages (id, agent_chat_thread_id, author_user_id, assistant_run_id, agent_work_item_id, role, content, metadata, created_at) FROM stdin;
\.


--
-- Data for Name: agent_chat_threads; Type: TABLE DATA; Schema: public; Owner: devboard
--

COPY public.agent_chat_threads (id, project_id, repository_id, task_id, created_by_user_id, agent_key, title, status, latest_agent_work_item_id, latest_assistant_run_id, last_message_at, metadata, created_at, updated_at, archived_at, archived_by_user_id, archive_reason) FROM stdin;
\.


--
-- Data for Name: agent_work_item_events; Type: TABLE DATA; Schema: public; Owner: devboard
--

COPY public.agent_work_item_events (id, agent_work_item_id, actor_user_id, actor_device_id, event_type, message, payload, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: agent_work_item_leases; Type: TABLE DATA; Schema: public; Owner: devboard
--

COPY public.agent_work_item_leases (id, agent_work_item_id, device_id, lease_token_hash, expires_at, released_at, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: agent_work_items; Type: TABLE DATA; Schema: public; Owner: devboard
--

COPY public.agent_work_items (id, project_id, repository_id, task_id, requested_by_user_id, assigned_agent_key, status, priority, title, prompt, payload, requires_memory_entry, result_memory_entry_id, claimed_by_device_id, claimed_at, heartbeat_at, completed_at, failed_at, canceled_at, failure_reason, created_at, updated_at, archived_at, archived_by_user_id, archive_reason) FROM stdin;
\.


--
-- Data for Name: ai_agent_profiles; Type: TABLE DATA; Schema: public; Owner: devboard
--

COPY public.ai_agent_profiles (id, agent_key, display_name, description, agent_type, delegation_mode, parent_agent_key, default_model_profile_id, requires_human_approval, enabled, allowed_tools, output_schema, trigger_events, created_at, updated_at, visibility_scope) FROM stdin;
01KX8QH6GMH6J2MJQWNAXZSP77	socrate_supervisor	Socrate Supervisor	Project-level read-only supervisor that routes requests to controlled specialist flows.	supervisor	controlled_registry	\N	01KX8QWFPM167PS285X6VEBKBZ	t	t	["read_project_summary","search_project_memory","query_project_graph","read_agent_profile_registry","append_assistant_suggestion"]	{"type":"object","required":["answer","delegations","evidence_refs","approval_required"],"properties":{"answer":{"type":"string"},"delegations":{"type":"array"},"evidence_refs":{"type":"array"},"approval_required":{"type":"boolean"}}}	["manual_chat","project_summary_request"]	2026-07-11 13:58:06	2026-07-11 14:04:15	global
01KX8QH6GQGADMXKXR8XDBTZYS	task_clarifier	Task Clarifier	Reviews PM task drafts and proposes questions, acceptance criteria, risks, dependencies, and test hints.	specialist	controlled_registry	socrate_supervisor	01KX8QWFPM167PS285X6VEBKBZ	t	t	["read_task_detail","read_project_summary","search_project_memory","search_wiki_revisions","append_assistant_suggestion"]	{"type":"object","required":["questions","acceptance_criteria","risks","missing_context","confidence"],"properties":{"questions":{"type":"array"},"acceptance_criteria":{"type":"array"},"risks":{"type":"array"},"missing_context":{"type":"array"},"confidence":{"type":"number"}}}	["task_created","task_updated","manual_review"]	2026-07-11 13:58:06	2026-07-11 14:04:15	global
01KX8QH6GRZWCRCPP0ZDG64KYV	backlog_triage	Backlog Triage	Finds vague, duplicate, stale, oversized, or inconsistent backlog work and emits recommendations only.	specialist	controlled_registry	socrate_supervisor	01KX8QWFPM167PS285X6VEBKBZ	t	t	["read_project_tasks","read_project_summary","search_project_memory","append_assistant_suggestion"]	{"type":"object","required":["summary","groups","recommendations","risks","confidence"],"properties":{"summary":{"type":"string"},"groups":{"type":"array"},"recommendations":{"type":"array"},"risks":{"type":"array"},"confidence":{"type":"number"}}}	["manual_triage","scheduled_triage"]	2026-07-11 13:58:06	2026-07-11 14:04:15	global
01KX8QH6GSXC4XPWVG4S8QTP99	wiki_query	Wiki Query	Answers from DevBoard-held wiki evidence and flags stale or conflicting pages.	specialist	controlled_registry	socrate_supervisor	01KX8QWFPM167PS285X6VEBKBZ	t	t	["search_wiki_revisions","search_project_memory","query_project_graph","write_wiki_revision","read_artifact_metadata","append_assistant_suggestion"]	{"type":"object","required":["answer","citations","freshness_warnings","confidence"],"properties":{"answer":{"type":"string"},"citations":{"type":"array"},"freshness_warnings":{"type":"array"},"confidence":{"type":"number"}}}	["manual_chat","wiki_freshness_check"]	2026-07-11 13:58:06	2026-07-11 14:04:15	global
01KX8QH6GVTC8JKREWHMTDX4D5	watchman	Watchman	Correlates logbook, run, graph, wiki, artifact, and quality signals into warnings and follow-up suggestions.	specialist	controlled_registry	socrate_supervisor	01KX8QWFPM167PS285X6VEBKBZ	t	t	["read_logbook_entries","read_run_summary","read_quality_report_summaries","append_assistant_suggestion"]	{"type":"object","required":["warnings","follow_up_suggestions","evidence_refs","confidence"],"properties":{"warnings":{"type":"array"},"follow_up_suggestions":{"type":"array"},"evidence_refs":{"type":"array"},"confidence":{"type":"number"}}}	["logbook_entry_created","run_finished","manual_scan"]	2026-07-11 13:58:06	2026-07-11 14:04:15	global
01KX8QH6GWJ5K5EGG2GEDHZT8T	intake_normalizer	Intake Normalizer	Classifies raw free-text input (bug, task, feature, question) and extracts a normalized title, description, and clarifying questions.	specialist	controlled_registry	socrate_supervisor	01KX8QWFPM167PS285X6VEBKBZ	t	t	[]	{"type":"object","required":["task_type","suggested_title","suggested_description","clarifying_questions","confidence"],"properties":{"task_type":{"type":"string","enum":["bug","task","feature","question"]},"suggested_title":{"type":"string"},"suggested_description":{"type":"string"},"clarifying_questions":{"type":"array"},"confidence":{"type":"number"}}}	["manual_intake"]	2026-07-11 13:58:06	2026-07-11 14:04:15	global
\.


--
-- Data for Name: ai_agent_project_visibility; Type: TABLE DATA; Schema: public; Owner: devboard
--

COPY public.ai_agent_project_visibility (id, ai_agent_profile_id, project_id, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: ai_model_profiles; Type: TABLE DATA; Schema: public; Owner: devboard
--

COPY public.ai_model_profiles (id, provider_id, profile_key, display_name, model_name, runtime_profile, max_context, max_output_tokens, temperature, timeout_seconds, enabled, metadata, created_at, updated_at) FROM stdin;
01KX8QWFPM167PS285X6VEBKBZ	01KX8QWFPF6J6CTE6TCS7S8HRQ	openai_default_text	OpenAI Default Text	gpt-5.4	compact_readonly	\N	2048	0.00	30	t	{"source_status":"verified_from_code","notes":"Matches the installed Laravel AI SDK OpenAI default text model."}	2026-07-11 14:04:15	2026-07-11 14:04:15
\.


--
-- Data for Name: ai_model_providers; Type: TABLE DATA; Schema: public; Owner: devboard
--

COPY public.ai_model_providers (id, provider_key, display_name, provider_type, base_url, encrypted_api_key, api_key_last_four, api_key_updated_at, enabled, metadata, created_by_user_id, created_at, updated_at) FROM stdin;
01KX8QWFPF6J6CTE6TCS7S8HRQ	openai	OpenAI	openai_compatible	https://api.openai.com/v1	\N	\N	\N	f	{"source_status":"developer_provided","notes":"Admin-configured provider for future server-side DevBoard assistants."}	\N	2026-07-11 14:04:15	2026-07-11 14:04:15
01KX8QH66E65GS140ZV3RH67MW	opencode_go	OpenCode Go	openai_compatible	https://opencode.ai/zen/go/v1	\N	\N	\N	f	{"source_status":"verified_from_docs","notes":"First-class configurable provider slot for OpenCode Go. Credentials are supplied by an Admin."}	\N	2026-07-11 13:58:05	2026-07-11 14:04:15
\.


--
-- Data for Name: api_tokens; Type: TABLE DATA; Schema: public; Owner: devboard
--

COPY public.api_tokens (id, token_prefix, token_hash, user_id, device_id, name, scopes, expires_at, revoked_at, last_used_at, created_at, updated_at, project_id, hades_agent_id, device_signing_secret_hash) FROM stdin;
\.


--
-- Data for Name: artifacts; Type: TABLE DATA; Schema: public; Owner: devboard
--

COPY public.artifacts (id, project_id, repository_id, run_id, artifact_type, storage_path, sha256, size_bytes, mime_type, schema_version, status, producer, metadata, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: assistant_messages; Type: TABLE DATA; Schema: public; Owner: devboard
--

COPY public.assistant_messages (id, assistant_run_id, role, content, metadata, created_at) FROM stdin;
\.


--
-- Data for Name: assistant_runs; Type: TABLE DATA; Schema: public; Owner: devboard
--

COPY public.assistant_runs (id, project_id, agent_profile_id, target_type, target_id, triggered_by_user_id, status, model_provider_id, model_profile_id, context_hash, context_snapshot, result_summary, metadata, started_at, finished_at, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: assistant_suggestions; Type: TABLE DATA; Schema: public; Owner: devboard
--

COPY public.assistant_suggestions (id, assistant_run_id, project_id, target_type, target_id, suggestion_type, title, body_markdown, structured_payload, evidence_refs, confidence, approval_required, status, created_by_user_id, resolved_by_user_id, resolved_at, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: audit_chain_heads; Type: TABLE DATA; Schema: public; Owner: devboard
--

COPY public.audit_chain_heads (chain_key, last_sequence, last_hash, updated_at) FROM stdin;
global	0	\N	2026-07-11 13:58:06
\.


--
-- Data for Name: audit_logs; Type: TABLE DATA; Schema: public; Owner: devboard
--

COPY public.audit_logs (id, actor_user_id, actor_device_id, actor_type, action, target_type, target_id, ip_address, user_agent, payload, created_at, prev_hash, row_hash, sequence, chain_version, actor_user_ref, actor_device_ref) FROM stdin;
\.


--
-- Data for Name: cache; Type: TABLE DATA; Schema: public; Owner: devboard
--

COPY public.cache (key, value, expiration) FROM stdin;
laravel-cache-1b300f3f77b950b960175f415f3eb7df:timer	i:1783778493;	1783778493
laravel-cache-1b300f3f77b950b960175f415f3eb7df	i:1;	1783778493
\.


--
-- Data for Name: cache_locks; Type: TABLE DATA; Schema: public; Owner: devboard
--

COPY public.cache_locks (key, owner, expiration) FROM stdin;
\.


--
-- Data for Name: delta_syncs; Type: TABLE DATA; Schema: public; Owner: devboard
--

COPY public.delta_syncs (id, project_id, repository_id, local_workspace_id, run_id, status, base_snapshot_id, new_snapshot_id, branch, base_sha, head_sha, dirty_status, changed_file_count, risk_level, started_at, finished_at, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: devices; Type: TABLE DATA; Schema: public; Owner: devboard
--

COPY public.devices (id, user_id, name, fingerprint_hash, platform_os, platform_arch, plugin_version, last_seen_at, status, created_at, updated_at, signing_secret_hash) FROM stdin;
\.


--
-- Data for Name: failed_jobs; Type: TABLE DATA; Schema: public; Owner: devboard
--

COPY public.failed_jobs (id, uuid, connection, queue, payload, exception, failed_at) FROM stdin;
\.


--
-- Data for Name: genesis_imports; Type: TABLE DATA; Schema: public; Owner: devboard
--

COPY public.genesis_imports (id, project_id, repository_id, local_workspace_id, run_id, status, manifest_artifact_id, snapshot_id, base_branch, base_sha, head_sha, started_at, finished_at, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: hades_agent_artifacts; Type: TABLE DATA; Schema: public; Owner: devboard
--

COPY public.hades_agent_artifacts (id, project_id, hades_agent_id, workspace_binding_id, job_id, schema, artifact, sha256, truncated, redactions, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: hades_agent_job_events; Type: TABLE DATA; Schema: public; Owner: devboard
--

COPY public.hades_agent_job_events (id, job_id, event_type, status, payload, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: hades_agent_jobs; Type: TABLE DATA; Schema: public; Owner: devboard
--

COPY public.hades_agent_jobs (id, project_id, hades_agent_id, workspace_binding_id, idempotency_key, capability, status, policy, priority, payload, result, requires_confirmation, deadline_at, available_at, claimed_at, started_at, completed_at, failed_at, cancelled_at, error_code, error_message, created_at, updated_at, repository_id, requested_by_user_id, job_type, result_applied_at) FROM stdin;
\.


--
-- Data for Name: hades_agent_tokens; Type: TABLE DATA; Schema: public; Owner: devboard
--

COPY public.hades_agent_tokens (id, project_id, hades_agent_id, token_prefix, token_hash, name, scopes, expires_at, revoked_at, last_used_at, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: hades_agents; Type: TABLE DATA; Schema: public; Owner: devboard
--

COPY public.hades_agents (id, project_id, external_agent_id, label, platform, version, declared_capabilities, effective_capabilities, last_seen_at, status, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: hades_bootstrap_tokens; Type: TABLE DATA; Schema: public; Owner: devboard
--

COPY public.hades_bootstrap_tokens (id, project_id, token_prefix, token_hash, name, scopes, allowed_capabilities, expires_at, revoked_at, last_used_at, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: hades_bug_evidence_items; Type: TABLE DATA; Schema: public; Owner: devboard
--

COPY public.hades_bug_evidence_items (id, project_id, bug_report_id, hades_agent_id, workspace_binding_id, kind, summary, payload, source, sha256, redactions, retention_class, occurred_at, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: hades_bug_reports; Type: TABLE DATA; Schema: public; Owner: devboard
--

COPY public.hades_bug_reports (id, project_id, hades_agent_id, workspace_binding_id, title, symptom, severity, status, environment, affected_refs, observed_at, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: hades_causal_packs; Type: TABLE DATA; Schema: public; Owner: devboard
--

COPY public.hades_causal_packs (id, project_id, bug_report_id, hades_agent_id, workspace_binding_id, pack_key, bug_id, root_cause_id, bug_class, failure_classification, affected_refs, freshness, awareness, evidence_refs, graph_refs, source_slice_refs, replay, status, blockers, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: hades_diagnosis_reports; Type: TABLE DATA; Schema: public; Owner: devboard
--

COPY public.hades_diagnosis_reports (id, project_id, bug_report_id, hades_agent_id, workspace_binding_id, status, confidence, root_cause, mechanism, evidence_refs, freshness, payload, redactions, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: hades_doctor_reports; Type: TABLE DATA; Schema: public; Owner: devboard
--

COPY public.hades_doctor_reports (id, project_id, hades_agent_id, workspace_binding_id, status, payload, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: hades_evidence_packs; Type: TABLE DATA; Schema: public; Owner: devboard
--

COPY public.hades_evidence_packs (id, project_id, bug_report_id, hades_agent_id, workspace_binding_id, title, summary, evidence_refs, graph_refs, source_slice_ids, payload, sha256, redactions, retention_class, head_commit, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: hades_memory_proposals; Type: TABLE DATA; Schema: public; Owner: devboard
--

COPY public.hades_memory_proposals (id, project_id, hades_agent_id, workspace_binding_id, local_proposal_id, action, intent, summary, provenance, base_version, target_memory_entry_id, memory_entry_id, status, reason_code, reason_message, decided_at, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: hades_persephone_agent_messages; Type: TABLE DATA; Schema: public; Owner: devboard
--

COPY public.hades_persephone_agent_messages (id, project_id, sender_agent_id, target_agent_id, target_workspace_binding_id, schema, message_id, correlation_id, causation_id, remote_task_id, remote_task_version, message_type, effect, capability, expires_at, payload, envelope, envelope_hash, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: hades_persephone_events; Type: TABLE DATA; Schema: public; Owner: devboard
--

COPY public.hades_persephone_events (id, project_id, hades_agent_id, workspace_binding_id, event_type, payload, read_at, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: hades_search_documents; Type: TABLE DATA; Schema: public; Owner: devboard
--

COPY public.hades_search_documents (id, project_id, workspace_binding_id, domain, kind, source_table, source_id, source_schema, title, body, metadata, checksum, created_at, updated_at, search_vector, embedding, embedding_status, embedding_model, embedding_dimensions, embedding_checksum, embedding_updated_at) FROM stdin;
\.


--
-- Data for Name: hades_source_slice_candidates; Type: TABLE DATA; Schema: public; Owner: devboard
--

COPY public.hades_source_slice_candidates (id, project_id, workspace_binding_id, candidate_key, path, start_line, end_line, symbol, reason, priority, head_commit, status, job_id, source_slice_id, metadata, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: hades_source_slices; Type: TABLE DATA; Schema: public; Owner: devboard
--

COPY public.hades_source_slices (id, project_id, hades_agent_id, workspace_binding_id, job_id, path, start_line, end_line, language, symbol, head_commit, sha256, content_redacted, redactions, truncated, retention_class, policy, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: hades_workspace_bindings; Type: TABLE DATA; Schema: public; Owner: devboard
--

COPY public.hades_workspace_bindings (id, project_id, hades_agent_id, external_agent_id, local_project_id, workspace_fingerprint, display_path, git_remote_display, git_remote_hash, head_commit, platform, status, linked_at, unlinked_at, last_seen_at, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: job_batches; Type: TABLE DATA; Schema: public; Owner: devboard
--

COPY public.job_batches (id, name, total_jobs, pending_jobs, failed_jobs, failed_job_ids, options, cancelled_at, created_at, finished_at) FROM stdin;
\.


--
-- Data for Name: jobs; Type: TABLE DATA; Schema: public; Owner: devboard
--

COPY public.jobs (id, queue, payload, attempts, reserved_at, available_at, created_at) FROM stdin;
\.


--
-- Data for Name: kanban_boards; Type: TABLE DATA; Schema: public; Owner: devboard
--

COPY public.kanban_boards (id, project_id, name, is_default, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: kanban_columns; Type: TABLE DATA; Schema: public; Owner: devboard
--

COPY public.kanban_columns (id, board_id, name, "position", status_key, wip_limit, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: local_workspaces; Type: TABLE DATA; Schema: public; Owner: devboard
--

COPY public.local_workspaces (id, repository_id, device_id, local_root_hash, display_path, current_branch, last_head_sha, dirty_status, last_snapshot_id, last_seen_at, created_at, updated_at, remote_name, remote_url_host, remote_url_hash, upstream_branch, ahead_count, behind_count, git_state_observed_at) FROM stdin;
\.


--
-- Data for Name: memory_import_batches; Type: TABLE DATA; Schema: public; Owner: devboard
--

COPY public.memory_import_batches (id, project_id, source_workspace_binding_id, target_workspace_binding_id, requested_by_user_id, requested_by_hades_agent_id, status, mode, dedupe_strategy, conflict_policy, reason, source_payload, completed_at, created_at, updated_at, cancelled_at, cancelled_by_user_id, cancel_reason) FROM stdin;
\.


--
-- Data for Name: memory_import_items; Type: TABLE DATA; Schema: public; Owner: devboard
--

COPY public.memory_import_items (id, batch_id, source_local_id, source_hash, proposal_id, target_memory_entry_id, status, conflict_reason, provenance, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: migrations; Type: TABLE DATA; Schema: public; Owner: devboard
--

COPY public.migrations (id, migration, batch) FROM stdin;
1	0001_01_01_000000_create_users_table	1
2	0001_01_01_000001_create_cache_table	1
3	0001_01_01_000002_create_jobs_table	1
4	2026_06_16_000000_create_devboard_core_tables	1
5	2026_06_24_000001_add_project_lifecycle_metadata_to_projects	1
6	2026_06_24_000002_create_task_attachments_table	1
7	2026_06_25_000001_add_git_state_to_local_workspaces	1
8	2026_06_28_000001_create_ai_agent_registry_tables	1
9	2026_06_28_000002_create_assistant_run_tables	1
10	2026_06_29_000001_create_project_workspace_memory_queue_tables	1
11	2026_06_30_000001_create_hades_m1_tables	1
12	2026_07_01_000001_create_hades_m2_workspace_bindings_table	1
13	2026_07_01_000002_create_hades_m3_memory_proposals_table	1
14	2026_07_01_000003_create_hades_m4_agent_jobs_tables	1
15	2026_07_01_000004_create_hades_m5_mvp_tables	1
16	2026_07_01_000005_expand_project_memory_entry_summary_column	1
17	2026_07_01_000006_create_memory_import_batches_and_extend_hades_jobs	1
18	2026_07_01_000007_ensure_opencode_go_provider	1
19	2026_07_01_000008_add_memory_graph_wiki_tools_to_agent_profiles	1
20	2026_07_01_000009_create_agent_chat_thread_tables	1
21	2026_07_03_000001_add_archive_and_cancel_metadata_to_agent_memory_surfaces	1
22	2026_07_07_000001_create_hades_bug_evidence_tables	1
23	2026_07_07_000002_create_hades_source_slices_table	1
24	2026_07_07_000003_create_hades_diagnosis_reports_table	1
25	2026_07_07_000004_create_hades_evidence_packs_table	1
26	2026_07_07_000005_create_hades_search_documents_table	1
27	2026_07_07_000006_add_hades_search_documents_fulltext_index	1
28	2026_07_07_000007_add_hades_agent_artifacts_lookup_index	1
29	2026_07_08_000001_create_hades_source_slice_candidates_table	1
30	2026_07_09_000001_create_hades_causal_packs_table	1
31	2026_07_09_000002_repair_ai_agent_registry_defaults	1
32	2026_07_09_000003_add_project_visibility_to_ai_agent_profiles	1
33	2026_07_09_000004_add_signing_secret_to_devices	1
34	2026_07_09_000005_add_hash_chain_to_audit_logs	1
35	2026_07_09_000006_add_postgres_full_text_to_hades_search_documents	1
36	2026_07_09_000007_add_embeddings_to_memory_search	1
37	2026_07_10_000001_expand_audit_chain_metadata	1
38	2026_07_10_000002_enforce_audit_chain_constraints	1
39	2026_07_10_000003_add_embedding_metadata_to_hades_search_documents	1
40	2026_07_11_000001_create_hades_persephone_agent_messages_table	1
41	2026_07_11_000002_add_hades_scope_to_api_tokens_table	1
\.


--
-- Data for Name: password_reset_tokens; Type: TABLE DATA; Schema: public; Owner: devboard
--

COPY public.password_reset_tokens (email, token, created_at) FROM stdin;
\.


--
-- Data for Name: permissions; Type: TABLE DATA; Schema: public; Owner: devboard
--

COPY public.permissions (id, name, created_at, updated_at) FROM stdin;
01KX8QWFN1XFH2QPGME8MV5DTS	users.manage	2026-07-11 14:04:15	2026-07-11 14:04:15
01KX8QWFN408WCXK3K1W7D1J0K	roles.manage	2026-07-11 14:04:15	2026-07-11 14:04:15
01KX8QWFN6GVAT7SSP7KK61FKK	tokens.manage	2026-07-11 14:04:15	2026-07-11 14:04:15
01KX8QWFN7VKP8KKBE2907AJ00	projects.read	2026-07-11 14:04:15	2026-07-11 14:04:15
01KX8QWFN9VV83T3YR9RCKEARA	projects.write	2026-07-11 14:04:15	2026-07-11 14:04:15
01KX8QWFNB45P4FDMKS78D8QXX	repositories.read	2026-07-11 14:04:15	2026-07-11 14:04:15
01KX8QWFNCE7ZNKKDXHQTVYWMW	repositories.write	2026-07-11 14:04:15	2026-07-11 14:04:15
01KX8QWFNE6H5JEPC553TEMFTV	tasks.read	2026-07-11 14:04:15	2026-07-11 14:04:15
01KX8QWFNGXN5R3HBCS4W8K064	tasks.write	2026-07-11 14:04:15	2026-07-11 14:04:15
01KX8QWFNHS0J3YCZ55C6EBR4Z	runs.read	2026-07-11 14:04:15	2026-07-11 14:04:15
01KX8QWFNK2DMSQSZ5E6NM9JGG	runs.write	2026-07-11 14:04:15	2026-07-11 14:04:15
01KX8QWFNN31M1R2JTCAQ3N1EE	artifacts.read	2026-07-11 14:04:15	2026-07-11 14:04:15
01KX8QWFNPHRCT2NW4381CY7YJ	artifacts.write	2026-07-11 14:04:15	2026-07-11 14:04:15
01KX8QWFNRDHG20928Z3FN9JQR	wiki.read	2026-07-11 14:04:15	2026-07-11 14:04:15
01KX8QWFNTQAJYDM4VV18KYP4Y	wiki.write	2026-07-11 14:04:15	2026-07-11 14:04:15
01KX8QWFNVC4C2EWENSNH9MKEX	policies.read	2026-07-11 14:04:15	2026-07-11 14:04:15
01KX8QWFNW3VPA1VBW9D7RNTGA	policies.write	2026-07-11 14:04:15	2026-07-11 14:04:15
01KX8QWFNYW8Z0WA2Z5VP68FZH	graph.read	2026-07-11 14:04:15	2026-07-11 14:04:15
01KX8QWFP0G1A2DW8ZQ042C0E8	graph.write	2026-07-11 14:04:15	2026-07-11 14:04:15
01KX8QWFP15MRZEEHXBCSZ2YC4	audit.read	2026-07-11 14:04:15	2026-07-11 14:04:15
01KX8QWFP3J1GGABZCZMDXRXNX	system.health.read	2026-07-11 14:04:15	2026-07-11 14:04:15
\.


--
-- Data for Name: project_memory_entries; Type: TABLE DATA; Schema: public; Owner: devboard
--

COPY public.project_memory_entries (id, project_id, repository_id, task_id, run_id, author_user_id, agent_key, source, kind, completeness, summary, payload, occurred_at, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: project_memory_links; Type: TABLE DATA; Schema: public; Owner: devboard
--

COPY public.project_memory_links (id, memory_entry_id, target_type, target_id, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: projects; Type: TABLE DATA; Schema: public; Owner: devboard
--

COPY public.projects (id, name, slug, description, status, default_code_exposure_policy, created_by_user_id, created_at, updated_at, archived_at, archived_by_user_id, deleted_at, deleted_by_user_id, restored_at, restored_by_user_id) FROM stdin;
\.


--
-- Data for Name: repositories; Type: TABLE DATA; Schema: public; Owner: devboard
--

COPY public.repositories (id, project_id, name, slug, default_branch, local_only, code_exposure_policy, protected_paths, excluded_paths, stack_hints, graph_enabled, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: repository_task; Type: TABLE DATA; Schema: public; Owner: devboard
--

COPY public.repository_task (id, task_id, repository_id, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: role_user; Type: TABLE DATA; Schema: public; Owner: devboard
--

COPY public.role_user (id, user_id, role_id, created_at, updated_at) FROM stdin;
1	2	01KX8QWFP5EJGD7HKR84AT13QW	2026-07-11 14:04:16	2026-07-11 14:04:16
2	3	01KX8QWFP5EJGD7HKR84AT13QW	2026-07-11 14:04:16	2026-07-11 14:04:16
3	4	01KX8QWFP7M0NF3AHHE39PKBWQ	2026-07-11 14:04:16	2026-07-11 14:04:16
4	5	01KX8QWFP9GV0WNXHTK34NSKHE	2026-07-11 14:04:16	2026-07-11 14:04:16
5	6	01KX8QWFPBFFHS7DSK3VPB8Q9P	2026-07-11 14:04:16	2026-07-11 14:04:16
\.


--
-- Data for Name: roles; Type: TABLE DATA; Schema: public; Owner: devboard
--

COPY public.roles (id, name, permissions, created_at, updated_at) FROM stdin;
01KX8QWFP5EJGD7HKR84AT13QW	Admin	["users.manage","roles.manage","tokens.manage","projects.read","projects.write","repositories.read","repositories.write","tasks.read","tasks.write","runs.read","runs.write","artifacts.read","artifacts.write","wiki.read","wiki.write","policies.read","policies.write","graph.read","graph.write","audit.read","system.health.read"]	2026-07-11 14:04:15	2026-07-11 14:04:15
01KX8QWFP7M0NF3AHHE39PKBWQ	PM	["projects.read","repositories.read","tasks.read","tasks.write","runs.read","artifacts.read","wiki.read","wiki.write","graph.read","audit.read"]	2026-07-11 14:04:15	2026-07-11 14:04:15
01KX8QWFP9GV0WNXHTK34NSKHE	Developer	["projects.read","repositories.read","tasks.read","tasks.write","runs.read","runs.write","artifacts.read","artifacts.write","wiki.read","wiki.write","policies.read","graph.read"]	2026-07-11 14:04:15	2026-07-11 14:04:15
01KX8QWFPBFFHS7DSK3VPB8Q9P	Sysadmin	["projects.read","repositories.read","runs.read","artifacts.read","audit.read","system.health.read","graph.read"]	2026-07-11 14:04:15	2026-07-11 14:04:15
01KX8QWFPDD7G97CEQG9RKR0VZ	Agent	["projects.read","repositories.read","runs.write","artifacts.write","wiki.write","policies.read","graph.write"]	2026-07-11 14:04:15	2026-07-11 14:04:15
\.


--
-- Data for Name: run_events; Type: TABLE DATA; Schema: public; Owner: devboard
--

COPY public.run_events (id, run_id, event_type, severity, message, payload, created_at) FROM stdin;
\.


--
-- Data for Name: runs; Type: TABLE DATA; Schema: public; Owner: devboard
--

COPY public.runs (id, project_id, repository_id, local_workspace_id, task_id, device_id, started_by_user_id, runtime_profile, status, branch, base_branch, base_sha, head_sha, summary, risk_level, started_at, finished_at, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: sessions; Type: TABLE DATA; Schema: public; Owner: devboard
--

COPY public.sessions (id, user_id, ip_address, user_agent, payload, last_activity) FROM stdin;
elqG66qmek1hEUFr1YJrs6ZSiExepUqsdHLbPkCX	3	79.31.190.194	Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36	eyJfdG9rZW4iOiJvRXpyak00V2x1S3B6S1UzWWtveUlwbm9xakJvVnpuNzQ4TVRPcjlLIiwiX2ZsYXNoIjp7Im9sZCI6W10sIm5ldyI6W119LCJsb2dpbl93ZWJfNTliYTM2YWRkYzJiMmY5NDAxNTgwZjAxNGM3ZjU4ZWE0ZTMwOTg5ZCI6M30=	1783778682
\.


--
-- Data for Name: snapshots; Type: TABLE DATA; Schema: public; Owner: devboard
--

COPY public.snapshots (id, project_id, repository_id, local_workspace_id, source_type, branch, base_sha, head_sha, dirty_status, file_inventory_artifact_id, graph_snapshot_artifact_id, created_by_run_id, created_at) FROM stdin;
\.


--
-- Data for Name: task_attachments; Type: TABLE DATA; Schema: public; Owner: devboard
--

COPY public.task_attachments (id, project_id, task_id, uploaded_by_user_id, deleted_by_user_id, original_name, stored_name, storage_path, sha256, size_bytes, mime_type, kind, status, scan_status, metadata, deleted_at, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: tasks; Type: TABLE DATA; Schema: public; Owner: devboard
--

COPY public.tasks (id, project_id, title, description, status_column_id, priority, risk_level, owner_user_id, created_by_user_id, due_at, created_at, updated_at, acceptance_criteria) FROM stdin;
\.


--
-- Data for Name: users; Type: TABLE DATA; Schema: public; Owner: devboard
--

COPY public.users (id, name, email, email_verified_at, password, status, last_login_at, remember_token, created_at, updated_at) FROM stdin;
2	DevBoard Admin	admin@example.com	\N	$2y$12$mHr04d.LKE55GEPNZJwSV.E0MxWVq6CqjUYpEC6IAN/gV/gO9MNXu	active	\N	\N	2026-07-11 14:04:16	2026-07-11 14:04:16
4	DevBoard PM	pm@devboard.local	\N	$2y$12$31LUQ9VYQNYhD2TlXOtSs.xRiGdJfnhBW8/QVuoWOtDyHqtrUzfJy	active	\N	\N	2026-07-11 14:04:16	2026-07-11 14:04:16
5	DevBoard Developer	dev@devboard.local	\N	$2y$12$ENnIC7K173HnaRyugj/yXui37waKut9m7PAkce9D3N12SAcUC7PLC	active	\N	\N	2026-07-11 14:04:16	2026-07-11 14:04:16
6	DevBoard Sysadmin	sysadmin@devboard.local	\N	$2y$12$A46FxYVfYS07dDlmchEx/OWYhs3lQY1F3Pb3B5tNrJDIYfMMg9KRK	active	\N	\N	2026-07-11 14:04:16	2026-07-11 14:04:16
3	DevBoard Admin	admin@devboard.local	\N	$2y$12$CoKNMiHlfxxqhDh5b.vzZe.Jm2jBk9.ilFPHco8KlZQKuO5cd4MlW	active	2026-07-11 14:04:33	\N	2026-07-11 14:04:16	2026-07-11 14:04:33
\.


--
-- Data for Name: wiki_pages; Type: TABLE DATA; Schema: public; Owner: devboard
--

COPY public.wiki_pages (id, project_id, repository_id, slug, title, page_type, current_revision_id, source_status, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: wiki_revisions; Type: TABLE DATA; Schema: public; Owner: devboard
--

COPY public.wiki_revisions (id, wiki_page_id, author_user_id, author_device_id, producer, source_type, source_status, content_markdown, evidence_refs, created_at) FROM stdin;
\.


--
-- Name: failed_jobs_id_seq; Type: SEQUENCE SET; Schema: public; Owner: devboard
--

SELECT pg_catalog.setval('public.failed_jobs_id_seq', 1, false);


--
-- Name: hades_source_slice_candidates_id_seq; Type: SEQUENCE SET; Schema: public; Owner: devboard
--

SELECT pg_catalog.setval('public.hades_source_slice_candidates_id_seq', 1, false);


--
-- Name: jobs_id_seq; Type: SEQUENCE SET; Schema: public; Owner: devboard
--

SELECT pg_catalog.setval('public.jobs_id_seq', 1, false);


--
-- Name: migrations_id_seq; Type: SEQUENCE SET; Schema: public; Owner: devboard
--

SELECT pg_catalog.setval('public.migrations_id_seq', 41, true);


--
-- Name: role_user_id_seq; Type: SEQUENCE SET; Schema: public; Owner: devboard
--

SELECT pg_catalog.setval('public.role_user_id_seq', 5, true);


--
-- Name: users_id_seq; Type: SEQUENCE SET; Schema: public; Owner: devboard
--

SELECT pg_catalog.setval('public.users_id_seq', 6, true);


--
-- Name: agent_chat_messages agent_chat_messages_pkey; Type: CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.agent_chat_messages
    ADD CONSTRAINT agent_chat_messages_pkey PRIMARY KEY (id);


--
-- Name: agent_chat_threads agent_chat_threads_pkey; Type: CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.agent_chat_threads
    ADD CONSTRAINT agent_chat_threads_pkey PRIMARY KEY (id);


--
-- Name: agent_work_item_events agent_work_item_events_pkey; Type: CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.agent_work_item_events
    ADD CONSTRAINT agent_work_item_events_pkey PRIMARY KEY (id);


--
-- Name: agent_work_item_leases agent_work_item_leases_pkey; Type: CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.agent_work_item_leases
    ADD CONSTRAINT agent_work_item_leases_pkey PRIMARY KEY (id);


--
-- Name: agent_work_items agent_work_items_pkey; Type: CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.agent_work_items
    ADD CONSTRAINT agent_work_items_pkey PRIMARY KEY (id);


--
-- Name: ai_agent_profiles ai_agent_profiles_agent_key_unique; Type: CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.ai_agent_profiles
    ADD CONSTRAINT ai_agent_profiles_agent_key_unique UNIQUE (agent_key);


--
-- Name: ai_agent_profiles ai_agent_profiles_pkey; Type: CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.ai_agent_profiles
    ADD CONSTRAINT ai_agent_profiles_pkey PRIMARY KEY (id);


--
-- Name: ai_agent_project_visibility ai_agent_project_visibility_pkey; Type: CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.ai_agent_project_visibility
    ADD CONSTRAINT ai_agent_project_visibility_pkey PRIMARY KEY (id);


--
-- Name: ai_agent_project_visibility ai_agent_project_visibility_unique; Type: CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.ai_agent_project_visibility
    ADD CONSTRAINT ai_agent_project_visibility_unique UNIQUE (ai_agent_profile_id, project_id);


--
-- Name: ai_model_profiles ai_model_profiles_pkey; Type: CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.ai_model_profiles
    ADD CONSTRAINT ai_model_profiles_pkey PRIMARY KEY (id);


--
-- Name: ai_model_profiles ai_model_profiles_profile_key_unique; Type: CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.ai_model_profiles
    ADD CONSTRAINT ai_model_profiles_profile_key_unique UNIQUE (profile_key);


--
-- Name: ai_model_providers ai_model_providers_pkey; Type: CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.ai_model_providers
    ADD CONSTRAINT ai_model_providers_pkey PRIMARY KEY (id);


--
-- Name: ai_model_providers ai_model_providers_provider_key_unique; Type: CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.ai_model_providers
    ADD CONSTRAINT ai_model_providers_provider_key_unique UNIQUE (provider_key);


--
-- Name: api_tokens api_tokens_pkey; Type: CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.api_tokens
    ADD CONSTRAINT api_tokens_pkey PRIMARY KEY (id);


--
-- Name: artifacts artifacts_pkey; Type: CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.artifacts
    ADD CONSTRAINT artifacts_pkey PRIMARY KEY (id);


--
-- Name: assistant_messages assistant_messages_pkey; Type: CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.assistant_messages
    ADD CONSTRAINT assistant_messages_pkey PRIMARY KEY (id);


--
-- Name: assistant_runs assistant_runs_pkey; Type: CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.assistant_runs
    ADD CONSTRAINT assistant_runs_pkey PRIMARY KEY (id);


--
-- Name: assistant_suggestions assistant_suggestions_pkey; Type: CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.assistant_suggestions
    ADD CONSTRAINT assistant_suggestions_pkey PRIMARY KEY (id);


--
-- Name: audit_chain_heads audit_chain_heads_pkey; Type: CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.audit_chain_heads
    ADD CONSTRAINT audit_chain_heads_pkey PRIMARY KEY (chain_key);


--
-- Name: audit_logs audit_logs_pkey; Type: CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.audit_logs
    ADD CONSTRAINT audit_logs_pkey PRIMARY KEY (id);


--
-- Name: audit_logs audit_logs_sequence_unique; Type: CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.audit_logs
    ADD CONSTRAINT audit_logs_sequence_unique UNIQUE (sequence);


--
-- Name: cache_locks cache_locks_pkey; Type: CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.cache_locks
    ADD CONSTRAINT cache_locks_pkey PRIMARY KEY (key);


--
-- Name: cache cache_pkey; Type: CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.cache
    ADD CONSTRAINT cache_pkey PRIMARY KEY (key);


--
-- Name: delta_syncs delta_syncs_pkey; Type: CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.delta_syncs
    ADD CONSTRAINT delta_syncs_pkey PRIMARY KEY (id);


--
-- Name: devices devices_pkey; Type: CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.devices
    ADD CONSTRAINT devices_pkey PRIMARY KEY (id);


--
-- Name: failed_jobs failed_jobs_pkey; Type: CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.failed_jobs
    ADD CONSTRAINT failed_jobs_pkey PRIMARY KEY (id);


--
-- Name: failed_jobs failed_jobs_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.failed_jobs
    ADD CONSTRAINT failed_jobs_uuid_unique UNIQUE (uuid);


--
-- Name: genesis_imports genesis_imports_pkey; Type: CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.genesis_imports
    ADD CONSTRAINT genesis_imports_pkey PRIMARY KEY (id);


--
-- Name: hades_agent_artifacts hades_agent_artifacts_pkey; Type: CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.hades_agent_artifacts
    ADD CONSTRAINT hades_agent_artifacts_pkey PRIMARY KEY (id);


--
-- Name: hades_agent_job_events hades_agent_job_events_pkey; Type: CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.hades_agent_job_events
    ADD CONSTRAINT hades_agent_job_events_pkey PRIMARY KEY (id);


--
-- Name: hades_agent_jobs hades_agent_jobs_pkey; Type: CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.hades_agent_jobs
    ADD CONSTRAINT hades_agent_jobs_pkey PRIMARY KEY (id);


--
-- Name: hades_agent_tokens hades_agent_tokens_pkey; Type: CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.hades_agent_tokens
    ADD CONSTRAINT hades_agent_tokens_pkey PRIMARY KEY (id);


--
-- Name: hades_agents hades_agents_pkey; Type: CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.hades_agents
    ADD CONSTRAINT hades_agents_pkey PRIMARY KEY (id);


--
-- Name: hades_agents hades_agents_project_id_external_agent_id_unique; Type: CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.hades_agents
    ADD CONSTRAINT hades_agents_project_id_external_agent_id_unique UNIQUE (project_id, external_agent_id);


--
-- Name: hades_bootstrap_tokens hades_bootstrap_tokens_pkey; Type: CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.hades_bootstrap_tokens
    ADD CONSTRAINT hades_bootstrap_tokens_pkey PRIMARY KEY (id);


--
-- Name: hades_bug_evidence_items hades_bug_evidence_items_pkey; Type: CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.hades_bug_evidence_items
    ADD CONSTRAINT hades_bug_evidence_items_pkey PRIMARY KEY (id);


--
-- Name: hades_bug_reports hades_bug_reports_pkey; Type: CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.hades_bug_reports
    ADD CONSTRAINT hades_bug_reports_pkey PRIMARY KEY (id);


--
-- Name: hades_causal_packs hades_causal_packs_pkey; Type: CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.hades_causal_packs
    ADD CONSTRAINT hades_causal_packs_pkey PRIMARY KEY (id);


--
-- Name: hades_causal_packs hades_causal_packs_project_id_pack_key_unique; Type: CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.hades_causal_packs
    ADD CONSTRAINT hades_causal_packs_project_id_pack_key_unique UNIQUE (project_id, pack_key);


--
-- Name: hades_diagnosis_reports hades_diagnosis_reports_pkey; Type: CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.hades_diagnosis_reports
    ADD CONSTRAINT hades_diagnosis_reports_pkey PRIMARY KEY (id);


--
-- Name: hades_doctor_reports hades_doctor_reports_pkey; Type: CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.hades_doctor_reports
    ADD CONSTRAINT hades_doctor_reports_pkey PRIMARY KEY (id);


--
-- Name: hades_evidence_packs hades_evidence_packs_pkey; Type: CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.hades_evidence_packs
    ADD CONSTRAINT hades_evidence_packs_pkey PRIMARY KEY (id);


--
-- Name: hades_memory_proposals hades_memory_proposals_pkey; Type: CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.hades_memory_proposals
    ADD CONSTRAINT hades_memory_proposals_pkey PRIMARY KEY (id);


--
-- Name: hades_memory_proposals hades_memory_workspace_local_proposal_unique; Type: CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.hades_memory_proposals
    ADD CONSTRAINT hades_memory_workspace_local_proposal_unique UNIQUE (workspace_binding_id, local_proposal_id);


--
-- Name: hades_persephone_agent_messages hades_persephone_agent_messages_pkey; Type: CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.hades_persephone_agent_messages
    ADD CONSTRAINT hades_persephone_agent_messages_pkey PRIMARY KEY (id);


--
-- Name: hades_persephone_agent_messages hades_persephone_agent_messages_project_message_unique; Type: CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.hades_persephone_agent_messages
    ADD CONSTRAINT hades_persephone_agent_messages_project_message_unique UNIQUE (project_id, message_id);


--
-- Name: hades_persephone_events hades_persephone_events_pkey; Type: CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.hades_persephone_events
    ADD CONSTRAINT hades_persephone_events_pkey PRIMARY KEY (id);


--
-- Name: hades_search_documents hades_search_documents_pkey; Type: CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.hades_search_documents
    ADD CONSTRAINT hades_search_documents_pkey PRIMARY KEY (id);


--
-- Name: hades_search_documents hades_search_documents_source_table_source_id_unique; Type: CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.hades_search_documents
    ADD CONSTRAINT hades_search_documents_source_table_source_id_unique UNIQUE (source_table, source_id);


--
-- Name: hades_source_slice_candidates hades_slice_candidate_binding_key_unique; Type: CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.hades_source_slice_candidates
    ADD CONSTRAINT hades_slice_candidate_binding_key_unique UNIQUE (workspace_binding_id, candidate_key);


--
-- Name: hades_source_slice_candidates hades_source_slice_candidates_pkey; Type: CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.hades_source_slice_candidates
    ADD CONSTRAINT hades_source_slice_candidates_pkey PRIMARY KEY (id);


--
-- Name: hades_source_slices hades_source_slices_pkey; Type: CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.hades_source_slices
    ADD CONSTRAINT hades_source_slices_pkey PRIMARY KEY (id);


--
-- Name: hades_workspace_bindings hades_workspace_agent_fingerprint_unique; Type: CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.hades_workspace_bindings
    ADD CONSTRAINT hades_workspace_agent_fingerprint_unique UNIQUE (project_id, hades_agent_id, workspace_fingerprint);


--
-- Name: hades_workspace_bindings hades_workspace_bindings_pkey; Type: CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.hades_workspace_bindings
    ADD CONSTRAINT hades_workspace_bindings_pkey PRIMARY KEY (id);


--
-- Name: job_batches job_batches_pkey; Type: CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.job_batches
    ADD CONSTRAINT job_batches_pkey PRIMARY KEY (id);


--
-- Name: jobs jobs_pkey; Type: CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.jobs
    ADD CONSTRAINT jobs_pkey PRIMARY KEY (id);


--
-- Name: kanban_boards kanban_boards_pkey; Type: CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.kanban_boards
    ADD CONSTRAINT kanban_boards_pkey PRIMARY KEY (id);


--
-- Name: kanban_columns kanban_columns_pkey; Type: CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.kanban_columns
    ADD CONSTRAINT kanban_columns_pkey PRIMARY KEY (id);


--
-- Name: local_workspaces local_workspaces_pkey; Type: CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.local_workspaces
    ADD CONSTRAINT local_workspaces_pkey PRIMARY KEY (id);


--
-- Name: memory_import_batches memory_import_batches_pkey; Type: CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.memory_import_batches
    ADD CONSTRAINT memory_import_batches_pkey PRIMARY KEY (id);


--
-- Name: memory_import_items memory_import_items_batch_id_source_hash_unique; Type: CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.memory_import_items
    ADD CONSTRAINT memory_import_items_batch_id_source_hash_unique UNIQUE (batch_id, source_hash);


--
-- Name: memory_import_items memory_import_items_pkey; Type: CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.memory_import_items
    ADD CONSTRAINT memory_import_items_pkey PRIMARY KEY (id);


--
-- Name: migrations migrations_pkey; Type: CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.migrations
    ADD CONSTRAINT migrations_pkey PRIMARY KEY (id);


--
-- Name: password_reset_tokens password_reset_tokens_pkey; Type: CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.password_reset_tokens
    ADD CONSTRAINT password_reset_tokens_pkey PRIMARY KEY (email);


--
-- Name: permissions permissions_name_unique; Type: CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.permissions
    ADD CONSTRAINT permissions_name_unique UNIQUE (name);


--
-- Name: permissions permissions_pkey; Type: CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.permissions
    ADD CONSTRAINT permissions_pkey PRIMARY KEY (id);


--
-- Name: project_memory_entries project_memory_entries_pkey; Type: CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.project_memory_entries
    ADD CONSTRAINT project_memory_entries_pkey PRIMARY KEY (id);


--
-- Name: project_memory_links project_memory_links_pkey; Type: CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.project_memory_links
    ADD CONSTRAINT project_memory_links_pkey PRIMARY KEY (id);


--
-- Name: projects projects_pkey; Type: CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.projects
    ADD CONSTRAINT projects_pkey PRIMARY KEY (id);


--
-- Name: projects projects_slug_unique; Type: CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.projects
    ADD CONSTRAINT projects_slug_unique UNIQUE (slug);


--
-- Name: repositories repositories_pkey; Type: CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.repositories
    ADD CONSTRAINT repositories_pkey PRIMARY KEY (id);


--
-- Name: repositories repositories_project_id_slug_unique; Type: CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.repositories
    ADD CONSTRAINT repositories_project_id_slug_unique UNIQUE (project_id, slug);


--
-- Name: repository_task repository_task_pkey; Type: CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.repository_task
    ADD CONSTRAINT repository_task_pkey PRIMARY KEY (id);


--
-- Name: repository_task repository_task_task_id_repository_id_unique; Type: CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.repository_task
    ADD CONSTRAINT repository_task_task_id_repository_id_unique UNIQUE (task_id, repository_id);


--
-- Name: role_user role_user_pkey; Type: CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.role_user
    ADD CONSTRAINT role_user_pkey PRIMARY KEY (id);


--
-- Name: role_user role_user_user_id_role_id_unique; Type: CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.role_user
    ADD CONSTRAINT role_user_user_id_role_id_unique UNIQUE (user_id, role_id);


--
-- Name: roles roles_name_unique; Type: CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.roles
    ADD CONSTRAINT roles_name_unique UNIQUE (name);


--
-- Name: roles roles_pkey; Type: CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.roles
    ADD CONSTRAINT roles_pkey PRIMARY KEY (id);


--
-- Name: run_events run_events_pkey; Type: CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.run_events
    ADD CONSTRAINT run_events_pkey PRIMARY KEY (id);


--
-- Name: runs runs_pkey; Type: CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.runs
    ADD CONSTRAINT runs_pkey PRIMARY KEY (id);


--
-- Name: sessions sessions_pkey; Type: CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.sessions
    ADD CONSTRAINT sessions_pkey PRIMARY KEY (id);


--
-- Name: snapshots snapshots_pkey; Type: CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.snapshots
    ADD CONSTRAINT snapshots_pkey PRIMARY KEY (id);


--
-- Name: task_attachments task_attachments_pkey; Type: CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.task_attachments
    ADD CONSTRAINT task_attachments_pkey PRIMARY KEY (id);


--
-- Name: tasks tasks_pkey; Type: CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.tasks
    ADD CONSTRAINT tasks_pkey PRIMARY KEY (id);


--
-- Name: users users_email_unique; Type: CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_email_unique UNIQUE (email);


--
-- Name: users users_pkey; Type: CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_pkey PRIMARY KEY (id);


--
-- Name: wiki_pages wiki_pages_pkey; Type: CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.wiki_pages
    ADD CONSTRAINT wiki_pages_pkey PRIMARY KEY (id);


--
-- Name: wiki_pages wiki_pages_project_id_repository_id_slug_unique; Type: CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.wiki_pages
    ADD CONSTRAINT wiki_pages_project_id_repository_id_slug_unique UNIQUE (project_id, repository_id, slug);


--
-- Name: wiki_revisions wiki_revisions_pkey; Type: CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.wiki_revisions
    ADD CONSTRAINT wiki_revisions_pkey PRIMARY KEY (id);


--
-- Name: agent_chat_messages_agent_chat_thread_id_created_at_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX agent_chat_messages_agent_chat_thread_id_created_at_index ON public.agent_chat_messages USING btree (agent_chat_thread_id, created_at);


--
-- Name: agent_chat_messages_agent_work_item_id_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX agent_chat_messages_agent_work_item_id_index ON public.agent_chat_messages USING btree (agent_work_item_id);


--
-- Name: agent_chat_messages_assistant_run_id_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX agent_chat_messages_assistant_run_id_index ON public.agent_chat_messages USING btree (assistant_run_id);


--
-- Name: agent_chat_project_archived_idx; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX agent_chat_project_archived_idx ON public.agent_chat_threads USING btree (project_id, archived_at);


--
-- Name: agent_chat_threads_project_id_agent_key_last_message_at_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX agent_chat_threads_project_id_agent_key_last_message_at_index ON public.agent_chat_threads USING btree (project_id, agent_key, last_message_at);


--
-- Name: agent_chat_threads_project_id_status_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX agent_chat_threads_project_id_status_index ON public.agent_chat_threads USING btree (project_id, status);


--
-- Name: agent_chat_threads_status_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX agent_chat_threads_status_index ON public.agent_chat_threads USING btree (status);


--
-- Name: agent_work_item_events_actor_device_id_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX agent_work_item_events_actor_device_id_index ON public.agent_work_item_events USING btree (actor_device_id);


--
-- Name: agent_work_item_events_actor_user_id_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX agent_work_item_events_actor_user_id_index ON public.agent_work_item_events USING btree (actor_user_id);


--
-- Name: agent_work_item_events_agent_work_item_id_created_at_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX agent_work_item_events_agent_work_item_id_created_at_index ON public.agent_work_item_events USING btree (agent_work_item_id, created_at);


--
-- Name: agent_work_item_leases_agent_work_item_id_released_at_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX agent_work_item_leases_agent_work_item_id_released_at_index ON public.agent_work_item_leases USING btree (agent_work_item_id, released_at);


--
-- Name: agent_work_item_leases_device_id_released_at_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX agent_work_item_leases_device_id_released_at_index ON public.agent_work_item_leases USING btree (device_id, released_at);


--
-- Name: agent_work_item_leases_expires_at_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX agent_work_item_leases_expires_at_index ON public.agent_work_item_leases USING btree (expires_at);


--
-- Name: agent_work_items_assigned_agent_key_status_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX agent_work_items_assigned_agent_key_status_index ON public.agent_work_items USING btree (assigned_agent_key, status);


--
-- Name: agent_work_items_claimed_by_device_id_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX agent_work_items_claimed_by_device_id_index ON public.agent_work_items USING btree (claimed_by_device_id);


--
-- Name: agent_work_items_project_id_status_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX agent_work_items_project_id_status_index ON public.agent_work_items USING btree (project_id, status);


--
-- Name: agent_work_items_repository_id_status_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX agent_work_items_repository_id_status_index ON public.agent_work_items USING btree (repository_id, status);


--
-- Name: agent_work_items_result_memory_entry_id_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX agent_work_items_result_memory_entry_id_index ON public.agent_work_items USING btree (result_memory_entry_id);


--
-- Name: agent_work_items_task_id_status_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX agent_work_items_task_id_status_index ON public.agent_work_items USING btree (task_id, status);


--
-- Name: agent_work_project_archived_idx; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX agent_work_project_archived_idx ON public.agent_work_items USING btree (project_id, archived_at);


--
-- Name: ai_agent_profiles_parent_agent_key_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX ai_agent_profiles_parent_agent_key_index ON public.ai_agent_profiles USING btree (parent_agent_key);


--
-- Name: ai_agent_profiles_visibility_scope_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX ai_agent_profiles_visibility_scope_index ON public.ai_agent_profiles USING btree (visibility_scope);


--
-- Name: ai_model_profiles_provider_id_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX ai_model_profiles_provider_id_index ON public.ai_model_profiles USING btree (provider_id);


--
-- Name: api_tokens_hades_scope_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX api_tokens_hades_scope_index ON public.api_tokens USING btree (project_id, hades_agent_id);


--
-- Name: api_tokens_token_prefix_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX api_tokens_token_prefix_index ON public.api_tokens USING btree (token_prefix);


--
-- Name: artifacts_run_id_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX artifacts_run_id_index ON public.artifacts USING btree (run_id);


--
-- Name: assistant_messages_assistant_run_id_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX assistant_messages_assistant_run_id_index ON public.assistant_messages USING btree (assistant_run_id);


--
-- Name: assistant_runs_project_id_target_type_target_id_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX assistant_runs_project_id_target_type_target_id_index ON public.assistant_runs USING btree (project_id, target_type, target_id);


--
-- Name: assistant_runs_status_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX assistant_runs_status_index ON public.assistant_runs USING btree (status);


--
-- Name: assistant_runs_target_id_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX assistant_runs_target_id_index ON public.assistant_runs USING btree (target_id);


--
-- Name: assistant_runs_target_type_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX assistant_runs_target_type_index ON public.assistant_runs USING btree (target_type);


--
-- Name: assistant_suggestions_project_id_target_type_target_id_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX assistant_suggestions_project_id_target_type_target_id_index ON public.assistant_suggestions USING btree (project_id, target_type, target_id);


--
-- Name: assistant_suggestions_status_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX assistant_suggestions_status_index ON public.assistant_suggestions USING btree (status);


--
-- Name: assistant_suggestions_suggestion_type_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX assistant_suggestions_suggestion_type_index ON public.assistant_suggestions USING btree (suggestion_type);


--
-- Name: assistant_suggestions_target_id_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX assistant_suggestions_target_id_index ON public.assistant_suggestions USING btree (target_id);


--
-- Name: assistant_suggestions_target_type_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX assistant_suggestions_target_type_index ON public.assistant_suggestions USING btree (target_type);


--
-- Name: audit_logs_action_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX audit_logs_action_index ON public.audit_logs USING btree (action);


--
-- Name: audit_logs_actor_type_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX audit_logs_actor_type_index ON public.audit_logs USING btree (actor_type);


--
-- Name: audit_logs_sequence_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX audit_logs_sequence_index ON public.audit_logs USING btree (sequence);


--
-- Name: cache_expiration_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX cache_expiration_index ON public.cache USING btree (expiration);


--
-- Name: cache_locks_expiration_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX cache_locks_expiration_index ON public.cache_locks USING btree (expiration);


--
-- Name: devices_user_id_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX devices_user_id_index ON public.devices USING btree (user_id);


--
-- Name: failed_jobs_connection_queue_failed_at_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX failed_jobs_connection_queue_failed_at_index ON public.failed_jobs USING btree (connection, queue, failed_at);


--
-- Name: genesis_imports_repository_id_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX genesis_imports_repository_id_index ON public.genesis_imports USING btree (repository_id);


--
-- Name: hades_agent_artifacts_hades_agent_id_created_at_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX hades_agent_artifacts_hades_agent_id_created_at_index ON public.hades_agent_artifacts USING btree (hades_agent_id, created_at);


--
-- Name: hades_agent_artifacts_lookup_idx; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX hades_agent_artifacts_lookup_idx ON public.hades_agent_artifacts USING btree (project_id, workspace_binding_id, schema, sha256);


--
-- Name: hades_agent_artifacts_project_id_schema_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX hades_agent_artifacts_project_id_schema_index ON public.hades_agent_artifacts USING btree (project_id, schema);


--
-- Name: hades_agent_artifacts_workspace_binding_id_schema_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX hades_agent_artifacts_workspace_binding_id_schema_index ON public.hades_agent_artifacts USING btree (workspace_binding_id, schema);


--
-- Name: hades_agent_job_events_event_type_created_at_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX hades_agent_job_events_event_type_created_at_index ON public.hades_agent_job_events USING btree (event_type, created_at);


--
-- Name: hades_agent_job_events_job_id_created_at_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX hades_agent_job_events_job_id_created_at_index ON public.hades_agent_job_events USING btree (job_id, created_at);


--
-- Name: hades_agent_jobs_capability_status_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX hades_agent_jobs_capability_status_index ON public.hades_agent_jobs USING btree (capability, status);


--
-- Name: hades_agent_jobs_hades_agent_id_status_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX hades_agent_jobs_hades_agent_id_status_index ON public.hades_agent_jobs USING btree (hades_agent_id, status);


--
-- Name: hades_agent_jobs_idempotency_key_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX hades_agent_jobs_idempotency_key_index ON public.hades_agent_jobs USING btree (idempotency_key);


--
-- Name: hades_agent_jobs_job_type_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX hades_agent_jobs_job_type_index ON public.hades_agent_jobs USING btree (job_type);


--
-- Name: hades_agent_jobs_project_id_status_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX hades_agent_jobs_project_id_status_index ON public.hades_agent_jobs USING btree (project_id, status);


--
-- Name: hades_agent_jobs_workspace_binding_id_status_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX hades_agent_jobs_workspace_binding_id_status_index ON public.hades_agent_jobs USING btree (workspace_binding_id, status);


--
-- Name: hades_agent_tokens_project_id_hades_agent_id_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX hades_agent_tokens_project_id_hades_agent_id_index ON public.hades_agent_tokens USING btree (project_id, hades_agent_id);


--
-- Name: hades_agent_tokens_token_prefix_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX hades_agent_tokens_token_prefix_index ON public.hades_agent_tokens USING btree (token_prefix);


--
-- Name: hades_agents_project_id_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX hades_agents_project_id_index ON public.hades_agents USING btree (project_id);


--
-- Name: hades_bootstrap_tokens_project_id_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX hades_bootstrap_tokens_project_id_index ON public.hades_bootstrap_tokens USING btree (project_id);


--
-- Name: hades_bootstrap_tokens_token_prefix_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX hades_bootstrap_tokens_token_prefix_index ON public.hades_bootstrap_tokens USING btree (token_prefix);


--
-- Name: hades_bug_evidence_items_bug_report_id_kind_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX hades_bug_evidence_items_bug_report_id_kind_index ON public.hades_bug_evidence_items USING btree (bug_report_id, kind);


--
-- Name: hades_bug_evidence_items_hades_agent_id_created_at_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX hades_bug_evidence_items_hades_agent_id_created_at_index ON public.hades_bug_evidence_items USING btree (hades_agent_id, created_at);


--
-- Name: hades_bug_evidence_items_project_id_kind_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX hades_bug_evidence_items_project_id_kind_index ON public.hades_bug_evidence_items USING btree (project_id, kind);


--
-- Name: hades_bug_evidence_items_project_id_occurred_at_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX hades_bug_evidence_items_project_id_occurred_at_index ON public.hades_bug_evidence_items USING btree (project_id, occurred_at);


--
-- Name: hades_bug_evidence_items_workspace_binding_id_kind_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX hades_bug_evidence_items_workspace_binding_id_kind_index ON public.hades_bug_evidence_items USING btree (workspace_binding_id, kind);


--
-- Name: hades_bug_reports_hades_agent_id_created_at_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX hades_bug_reports_hades_agent_id_created_at_index ON public.hades_bug_reports USING btree (hades_agent_id, created_at);


--
-- Name: hades_bug_reports_project_id_severity_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX hades_bug_reports_project_id_severity_index ON public.hades_bug_reports USING btree (project_id, severity);


--
-- Name: hades_bug_reports_project_id_status_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX hades_bug_reports_project_id_status_index ON public.hades_bug_reports USING btree (project_id, status);


--
-- Name: hades_bug_reports_workspace_binding_id_status_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX hades_bug_reports_workspace_binding_id_status_index ON public.hades_bug_reports USING btree (workspace_binding_id, status);


--
-- Name: hades_causal_packs_bug_report_id_created_at_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX hades_causal_packs_bug_report_id_created_at_index ON public.hades_causal_packs USING btree (bug_report_id, created_at);


--
-- Name: hades_causal_packs_project_id_workspace_binding_id_status_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX hades_causal_packs_project_id_workspace_binding_id_status_index ON public.hades_causal_packs USING btree (project_id, workspace_binding_id, status);


--
-- Name: hades_causal_packs_root_cause_id_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX hades_causal_packs_root_cause_id_index ON public.hades_causal_packs USING btree (root_cause_id);


--
-- Name: hades_diagnosis_reports_bug_report_id_created_at_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX hades_diagnosis_reports_bug_report_id_created_at_index ON public.hades_diagnosis_reports USING btree (bug_report_id, created_at);


--
-- Name: hades_diagnosis_reports_project_id_confidence_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX hades_diagnosis_reports_project_id_confidence_index ON public.hades_diagnosis_reports USING btree (project_id, confidence);


--
-- Name: hades_diagnosis_reports_project_id_status_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX hades_diagnosis_reports_project_id_status_index ON public.hades_diagnosis_reports USING btree (project_id, status);


--
-- Name: hades_diagnosis_reports_workspace_binding_id_status_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX hades_diagnosis_reports_workspace_binding_id_status_index ON public.hades_diagnosis_reports USING btree (workspace_binding_id, status);


--
-- Name: hades_doctor_reports_hades_agent_id_created_at_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX hades_doctor_reports_hades_agent_id_created_at_index ON public.hades_doctor_reports USING btree (hades_agent_id, created_at);


--
-- Name: hades_doctor_reports_project_id_created_at_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX hades_doctor_reports_project_id_created_at_index ON public.hades_doctor_reports USING btree (project_id, created_at);


--
-- Name: hades_doctor_reports_status_created_at_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX hades_doctor_reports_status_created_at_index ON public.hades_doctor_reports USING btree (status, created_at);


--
-- Name: hades_evidence_packs_bug_report_id_created_at_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX hades_evidence_packs_bug_report_id_created_at_index ON public.hades_evidence_packs USING btree (bug_report_id, created_at);


--
-- Name: hades_evidence_packs_project_id_created_at_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX hades_evidence_packs_project_id_created_at_index ON public.hades_evidence_packs USING btree (project_id, created_at);


--
-- Name: hades_evidence_packs_workspace_binding_id_created_at_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX hades_evidence_packs_workspace_binding_id_created_at_index ON public.hades_evidence_packs USING btree (workspace_binding_id, created_at);


--
-- Name: hades_evidence_packs_workspace_binding_id_head_commit_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX hades_evidence_packs_workspace_binding_id_head_commit_index ON public.hades_evidence_packs USING btree (workspace_binding_id, head_commit);


--
-- Name: hades_memory_proposals_hades_agent_id_status_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX hades_memory_proposals_hades_agent_id_status_index ON public.hades_memory_proposals USING btree (hades_agent_id, status);


--
-- Name: hades_memory_proposals_project_id_status_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX hades_memory_proposals_project_id_status_index ON public.hades_memory_proposals USING btree (project_id, status);


--
-- Name: hades_memory_proposals_workspace_binding_id_status_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX hades_memory_proposals_workspace_binding_id_status_index ON public.hades_memory_proposals USING btree (workspace_binding_id, status);


--
-- Name: hades_persephone_agent_messages_expiry_cursor_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX hades_persephone_agent_messages_expiry_cursor_index ON public.hades_persephone_agent_messages USING btree (project_id, expires_at, id);


--
-- Name: hades_persephone_agent_messages_target_cursor_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX hades_persephone_agent_messages_target_cursor_index ON public.hades_persephone_agent_messages USING btree (project_id, target_agent_id, id);


--
-- Name: hades_persephone_agent_messages_workspace_cursor_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX hades_persephone_agent_messages_workspace_cursor_index ON public.hades_persephone_agent_messages USING btree (project_id, target_agent_id, target_workspace_binding_id, id);


--
-- Name: hades_persephone_events_event_type_created_at_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX hades_persephone_events_event_type_created_at_index ON public.hades_persephone_events USING btree (event_type, created_at);


--
-- Name: hades_persephone_events_hades_agent_id_read_at_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX hades_persephone_events_hades_agent_id_read_at_index ON public.hades_persephone_events USING btree (hades_agent_id, read_at);


--
-- Name: hades_persephone_events_project_id_read_at_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX hades_persephone_events_project_id_read_at_index ON public.hades_persephone_events USING btree (project_id, read_at);


--
-- Name: hades_search_documents_embedding_hnsw_idx; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX hades_search_documents_embedding_hnsw_idx ON public.hades_search_documents USING hnsw (embedding public.vector_cosine_ops);


--
-- Name: hades_search_documents_project_id_domain_kind_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX hades_search_documents_project_id_domain_kind_index ON public.hades_search_documents USING btree (project_id, domain, kind);


--
-- Name: hades_search_documents_source_schema_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX hades_search_documents_source_schema_index ON public.hades_search_documents USING btree (source_schema);


--
-- Name: hades_search_documents_tsvector_idx; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX hades_search_documents_tsvector_idx ON public.hades_search_documents USING gin (search_vector);


--
-- Name: hades_search_documents_workspace_binding_id_domain_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX hades_search_documents_workspace_binding_id_domain_index ON public.hades_search_documents USING btree (workspace_binding_id, domain);


--
-- Name: hades_slice_candidate_status_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX hades_slice_candidate_status_index ON public.hades_source_slice_candidates USING btree (project_id, workspace_binding_id, status);


--
-- Name: hades_source_slices_job_id_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX hades_source_slices_job_id_index ON public.hades_source_slices USING btree (job_id);


--
-- Name: hades_source_slices_project_id_path_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX hades_source_slices_project_id_path_index ON public.hades_source_slices USING btree (project_id, path);


--
-- Name: hades_source_slices_workspace_binding_id_head_commit_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX hades_source_slices_workspace_binding_id_head_commit_index ON public.hades_source_slices USING btree (workspace_binding_id, head_commit);


--
-- Name: hades_source_slices_workspace_binding_id_path_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX hades_source_slices_workspace_binding_id_path_index ON public.hades_source_slices USING btree (workspace_binding_id, path);


--
-- Name: hades_workspace_bindings_hades_agent_id_status_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX hades_workspace_bindings_hades_agent_id_status_index ON public.hades_workspace_bindings USING btree (hades_agent_id, status);


--
-- Name: hades_workspace_bindings_project_id_status_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX hades_workspace_bindings_project_id_status_index ON public.hades_workspace_bindings USING btree (project_id, status);


--
-- Name: hades_workspace_bindings_workspace_fingerprint_status_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX hades_workspace_bindings_workspace_fingerprint_status_index ON public.hades_workspace_bindings USING btree (workspace_fingerprint, status);


--
-- Name: jobs_queue_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX jobs_queue_index ON public.jobs USING btree (queue);


--
-- Name: local_workspaces_last_snapshot_id_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX local_workspaces_last_snapshot_id_index ON public.local_workspaces USING btree (last_snapshot_id);


--
-- Name: local_workspaces_repository_id_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX local_workspaces_repository_id_index ON public.local_workspaces USING btree (repository_id);


--
-- Name: memory_import_batches_project_id_status_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX memory_import_batches_project_id_status_index ON public.memory_import_batches USING btree (project_id, status);


--
-- Name: memory_import_batches_requested_by_hades_agent_id_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX memory_import_batches_requested_by_hades_agent_id_index ON public.memory_import_batches USING btree (requested_by_hades_agent_id);


--
-- Name: memory_import_batches_target_workspace_binding_id_status_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX memory_import_batches_target_workspace_binding_id_status_index ON public.memory_import_batches USING btree (target_workspace_binding_id, status);


--
-- Name: memory_import_items_proposal_id_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX memory_import_items_proposal_id_index ON public.memory_import_items USING btree (proposal_id);


--
-- Name: memory_import_items_source_hash_status_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX memory_import_items_source_hash_status_index ON public.memory_import_items USING btree (source_hash, status);


--
-- Name: project_memory_entries_project_id_agent_key_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX project_memory_entries_project_id_agent_key_index ON public.project_memory_entries USING btree (project_id, agent_key);


--
-- Name: project_memory_entries_project_id_kind_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX project_memory_entries_project_id_kind_index ON public.project_memory_entries USING btree (project_id, kind);


--
-- Name: project_memory_entries_project_id_occurred_at_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX project_memory_entries_project_id_occurred_at_index ON public.project_memory_entries USING btree (project_id, occurred_at);


--
-- Name: project_memory_entries_repository_id_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX project_memory_entries_repository_id_index ON public.project_memory_entries USING btree (repository_id);


--
-- Name: project_memory_entries_run_id_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX project_memory_entries_run_id_index ON public.project_memory_entries USING btree (run_id);


--
-- Name: project_memory_entries_task_id_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX project_memory_entries_task_id_index ON public.project_memory_entries USING btree (task_id);


--
-- Name: project_memory_links_memory_entry_id_target_type_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX project_memory_links_memory_entry_id_target_type_index ON public.project_memory_links USING btree (memory_entry_id, target_type);


--
-- Name: project_memory_links_target_type_target_id_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX project_memory_links_target_type_target_id_index ON public.project_memory_links USING btree (target_type, target_id);


--
-- Name: projects_status_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX projects_status_index ON public.projects USING btree (status);


--
-- Name: repositories_project_id_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX repositories_project_id_index ON public.repositories USING btree (project_id);


--
-- Name: repository_task_repository_id_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX repository_task_repository_id_index ON public.repository_task USING btree (repository_id);


--
-- Name: runs_project_id_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX runs_project_id_index ON public.runs USING btree (project_id);


--
-- Name: runs_repository_id_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX runs_repository_id_index ON public.runs USING btree (repository_id);


--
-- Name: runs_task_id_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX runs_task_id_index ON public.runs USING btree (task_id);


--
-- Name: sessions_last_activity_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX sessions_last_activity_index ON public.sessions USING btree (last_activity);


--
-- Name: sessions_user_id_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX sessions_user_id_index ON public.sessions USING btree (user_id);


--
-- Name: snapshots_repository_id_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX snapshots_repository_id_index ON public.snapshots USING btree (repository_id);


--
-- Name: task_attachments_deleted_at_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX task_attachments_deleted_at_index ON public.task_attachments USING btree (deleted_at);


--
-- Name: task_attachments_project_id_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX task_attachments_project_id_index ON public.task_attachments USING btree (project_id);


--
-- Name: task_attachments_project_id_task_id_created_at_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX task_attachments_project_id_task_id_created_at_index ON public.task_attachments USING btree (project_id, task_id, created_at);


--
-- Name: task_attachments_task_id_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX task_attachments_task_id_index ON public.task_attachments USING btree (task_id);


--
-- Name: task_attachments_uploaded_by_user_id_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX task_attachments_uploaded_by_user_id_index ON public.task_attachments USING btree (uploaded_by_user_id);


--
-- Name: tasks_project_id_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX tasks_project_id_index ON public.tasks USING btree (project_id);


--
-- Name: wiki_pages_current_revision_id_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX wiki_pages_current_revision_id_index ON public.wiki_pages USING btree (current_revision_id);


--
-- Name: wiki_pages_project_id_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX wiki_pages_project_id_index ON public.wiki_pages USING btree (project_id);


--
-- Name: wiki_pages_repository_id_index; Type: INDEX; Schema: public; Owner: devboard
--

CREATE INDEX wiki_pages_repository_id_index ON public.wiki_pages USING btree (repository_id);


--
-- Name: hades_search_documents hades_search_documents_tsvector_update; Type: TRIGGER; Schema: public; Owner: devboard
--

CREATE TRIGGER hades_search_documents_tsvector_update BEFORE INSERT OR UPDATE ON public.hades_search_documents FOR EACH ROW EXECUTE FUNCTION public.hades_search_documents_tsvector_trigger();


--
-- Name: agent_chat_messages agent_chat_messages_agent_chat_thread_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.agent_chat_messages
    ADD CONSTRAINT agent_chat_messages_agent_chat_thread_id_foreign FOREIGN KEY (agent_chat_thread_id) REFERENCES public.agent_chat_threads(id) ON DELETE CASCADE;


--
-- Name: agent_chat_messages agent_chat_messages_agent_work_item_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.agent_chat_messages
    ADD CONSTRAINT agent_chat_messages_agent_work_item_id_foreign FOREIGN KEY (agent_work_item_id) REFERENCES public.agent_work_items(id) ON DELETE SET NULL;


--
-- Name: agent_chat_messages agent_chat_messages_assistant_run_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.agent_chat_messages
    ADD CONSTRAINT agent_chat_messages_assistant_run_id_foreign FOREIGN KEY (assistant_run_id) REFERENCES public.assistant_runs(id) ON DELETE SET NULL;


--
-- Name: agent_chat_messages agent_chat_messages_author_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.agent_chat_messages
    ADD CONSTRAINT agent_chat_messages_author_user_id_foreign FOREIGN KEY (author_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: agent_chat_threads agent_chat_threads_archived_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.agent_chat_threads
    ADD CONSTRAINT agent_chat_threads_archived_by_user_id_foreign FOREIGN KEY (archived_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: agent_chat_threads agent_chat_threads_created_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.agent_chat_threads
    ADD CONSTRAINT agent_chat_threads_created_by_user_id_foreign FOREIGN KEY (created_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: agent_chat_threads agent_chat_threads_latest_agent_work_item_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.agent_chat_threads
    ADD CONSTRAINT agent_chat_threads_latest_agent_work_item_id_foreign FOREIGN KEY (latest_agent_work_item_id) REFERENCES public.agent_work_items(id) ON DELETE SET NULL;


--
-- Name: agent_chat_threads agent_chat_threads_latest_assistant_run_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.agent_chat_threads
    ADD CONSTRAINT agent_chat_threads_latest_assistant_run_id_foreign FOREIGN KEY (latest_assistant_run_id) REFERENCES public.assistant_runs(id) ON DELETE SET NULL;


--
-- Name: agent_chat_threads agent_chat_threads_project_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.agent_chat_threads
    ADD CONSTRAINT agent_chat_threads_project_id_foreign FOREIGN KEY (project_id) REFERENCES public.projects(id) ON DELETE CASCADE;


--
-- Name: agent_chat_threads agent_chat_threads_repository_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.agent_chat_threads
    ADD CONSTRAINT agent_chat_threads_repository_id_foreign FOREIGN KEY (repository_id) REFERENCES public.repositories(id) ON DELETE SET NULL;


--
-- Name: agent_chat_threads agent_chat_threads_task_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.agent_chat_threads
    ADD CONSTRAINT agent_chat_threads_task_id_foreign FOREIGN KEY (task_id) REFERENCES public.tasks(id) ON DELETE SET NULL;


--
-- Name: agent_work_item_events agent_work_item_events_actor_device_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.agent_work_item_events
    ADD CONSTRAINT agent_work_item_events_actor_device_id_foreign FOREIGN KEY (actor_device_id) REFERENCES public.devices(id) ON DELETE SET NULL;


--
-- Name: agent_work_item_events agent_work_item_events_actor_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.agent_work_item_events
    ADD CONSTRAINT agent_work_item_events_actor_user_id_foreign FOREIGN KEY (actor_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: agent_work_item_events agent_work_item_events_agent_work_item_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.agent_work_item_events
    ADD CONSTRAINT agent_work_item_events_agent_work_item_id_foreign FOREIGN KEY (agent_work_item_id) REFERENCES public.agent_work_items(id) ON DELETE CASCADE;


--
-- Name: agent_work_item_leases agent_work_item_leases_agent_work_item_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.agent_work_item_leases
    ADD CONSTRAINT agent_work_item_leases_agent_work_item_id_foreign FOREIGN KEY (agent_work_item_id) REFERENCES public.agent_work_items(id) ON DELETE CASCADE;


--
-- Name: agent_work_item_leases agent_work_item_leases_device_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.agent_work_item_leases
    ADD CONSTRAINT agent_work_item_leases_device_id_foreign FOREIGN KEY (device_id) REFERENCES public.devices(id) ON DELETE CASCADE;


--
-- Name: agent_work_items agent_work_items_archived_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.agent_work_items
    ADD CONSTRAINT agent_work_items_archived_by_user_id_foreign FOREIGN KEY (archived_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: agent_work_items agent_work_items_claimed_by_device_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.agent_work_items
    ADD CONSTRAINT agent_work_items_claimed_by_device_id_foreign FOREIGN KEY (claimed_by_device_id) REFERENCES public.devices(id) ON DELETE SET NULL;


--
-- Name: agent_work_items agent_work_items_project_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.agent_work_items
    ADD CONSTRAINT agent_work_items_project_id_foreign FOREIGN KEY (project_id) REFERENCES public.projects(id) ON DELETE CASCADE;


--
-- Name: agent_work_items agent_work_items_repository_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.agent_work_items
    ADD CONSTRAINT agent_work_items_repository_id_foreign FOREIGN KEY (repository_id) REFERENCES public.repositories(id) ON DELETE SET NULL;


--
-- Name: agent_work_items agent_work_items_requested_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.agent_work_items
    ADD CONSTRAINT agent_work_items_requested_by_user_id_foreign FOREIGN KEY (requested_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: agent_work_items agent_work_items_result_memory_entry_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.agent_work_items
    ADD CONSTRAINT agent_work_items_result_memory_entry_id_foreign FOREIGN KEY (result_memory_entry_id) REFERENCES public.project_memory_entries(id) ON DELETE SET NULL;


--
-- Name: agent_work_items agent_work_items_task_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.agent_work_items
    ADD CONSTRAINT agent_work_items_task_id_foreign FOREIGN KEY (task_id) REFERENCES public.tasks(id) ON DELETE SET NULL;


--
-- Name: ai_agent_profiles ai_agent_profiles_default_model_profile_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.ai_agent_profiles
    ADD CONSTRAINT ai_agent_profiles_default_model_profile_id_foreign FOREIGN KEY (default_model_profile_id) REFERENCES public.ai_model_profiles(id) ON DELETE SET NULL;


--
-- Name: ai_agent_project_visibility ai_agent_project_visibility_ai_agent_profile_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.ai_agent_project_visibility
    ADD CONSTRAINT ai_agent_project_visibility_ai_agent_profile_id_foreign FOREIGN KEY (ai_agent_profile_id) REFERENCES public.ai_agent_profiles(id) ON DELETE CASCADE;


--
-- Name: ai_agent_project_visibility ai_agent_project_visibility_project_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.ai_agent_project_visibility
    ADD CONSTRAINT ai_agent_project_visibility_project_id_foreign FOREIGN KEY (project_id) REFERENCES public.projects(id) ON DELETE CASCADE;


--
-- Name: ai_model_profiles ai_model_profiles_provider_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.ai_model_profiles
    ADD CONSTRAINT ai_model_profiles_provider_id_foreign FOREIGN KEY (provider_id) REFERENCES public.ai_model_providers(id) ON DELETE CASCADE;


--
-- Name: ai_model_providers ai_model_providers_created_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.ai_model_providers
    ADD CONSTRAINT ai_model_providers_created_by_user_id_foreign FOREIGN KEY (created_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: api_tokens api_tokens_device_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.api_tokens
    ADD CONSTRAINT api_tokens_device_id_foreign FOREIGN KEY (device_id) REFERENCES public.devices(id) ON DELETE SET NULL;


--
-- Name: api_tokens api_tokens_hades_agent_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.api_tokens
    ADD CONSTRAINT api_tokens_hades_agent_id_foreign FOREIGN KEY (hades_agent_id) REFERENCES public.hades_agents(id) ON DELETE SET NULL;


--
-- Name: api_tokens api_tokens_project_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.api_tokens
    ADD CONSTRAINT api_tokens_project_id_foreign FOREIGN KEY (project_id) REFERENCES public.projects(id) ON DELETE SET NULL;


--
-- Name: api_tokens api_tokens_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.api_tokens
    ADD CONSTRAINT api_tokens_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: artifacts artifacts_project_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.artifacts
    ADD CONSTRAINT artifacts_project_id_foreign FOREIGN KEY (project_id) REFERENCES public.projects(id) ON DELETE CASCADE;


--
-- Name: artifacts artifacts_repository_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.artifacts
    ADD CONSTRAINT artifacts_repository_id_foreign FOREIGN KEY (repository_id) REFERENCES public.repositories(id) ON DELETE SET NULL;


--
-- Name: artifacts artifacts_run_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.artifacts
    ADD CONSTRAINT artifacts_run_id_foreign FOREIGN KEY (run_id) REFERENCES public.runs(id) ON DELETE SET NULL;


--
-- Name: assistant_messages assistant_messages_assistant_run_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.assistant_messages
    ADD CONSTRAINT assistant_messages_assistant_run_id_foreign FOREIGN KEY (assistant_run_id) REFERENCES public.assistant_runs(id) ON DELETE CASCADE;


--
-- Name: assistant_runs assistant_runs_agent_profile_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.assistant_runs
    ADD CONSTRAINT assistant_runs_agent_profile_id_foreign FOREIGN KEY (agent_profile_id) REFERENCES public.ai_agent_profiles(id) ON DELETE SET NULL;


--
-- Name: assistant_runs assistant_runs_model_profile_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.assistant_runs
    ADD CONSTRAINT assistant_runs_model_profile_id_foreign FOREIGN KEY (model_profile_id) REFERENCES public.ai_model_profiles(id) ON DELETE SET NULL;


--
-- Name: assistant_runs assistant_runs_model_provider_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.assistant_runs
    ADD CONSTRAINT assistant_runs_model_provider_id_foreign FOREIGN KEY (model_provider_id) REFERENCES public.ai_model_providers(id) ON DELETE SET NULL;


--
-- Name: assistant_runs assistant_runs_project_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.assistant_runs
    ADD CONSTRAINT assistant_runs_project_id_foreign FOREIGN KEY (project_id) REFERENCES public.projects(id) ON DELETE CASCADE;


--
-- Name: assistant_runs assistant_runs_triggered_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.assistant_runs
    ADD CONSTRAINT assistant_runs_triggered_by_user_id_foreign FOREIGN KEY (triggered_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: assistant_suggestions assistant_suggestions_assistant_run_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.assistant_suggestions
    ADD CONSTRAINT assistant_suggestions_assistant_run_id_foreign FOREIGN KEY (assistant_run_id) REFERENCES public.assistant_runs(id) ON DELETE CASCADE;


--
-- Name: assistant_suggestions assistant_suggestions_created_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.assistant_suggestions
    ADD CONSTRAINT assistant_suggestions_created_by_user_id_foreign FOREIGN KEY (created_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: assistant_suggestions assistant_suggestions_project_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.assistant_suggestions
    ADD CONSTRAINT assistant_suggestions_project_id_foreign FOREIGN KEY (project_id) REFERENCES public.projects(id) ON DELETE CASCADE;


--
-- Name: assistant_suggestions assistant_suggestions_resolved_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.assistant_suggestions
    ADD CONSTRAINT assistant_suggestions_resolved_by_user_id_foreign FOREIGN KEY (resolved_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: audit_logs audit_logs_actor_device_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.audit_logs
    ADD CONSTRAINT audit_logs_actor_device_id_foreign FOREIGN KEY (actor_device_id) REFERENCES public.devices(id) ON DELETE SET NULL;


--
-- Name: audit_logs audit_logs_actor_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.audit_logs
    ADD CONSTRAINT audit_logs_actor_user_id_foreign FOREIGN KEY (actor_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: delta_syncs delta_syncs_base_snapshot_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.delta_syncs
    ADD CONSTRAINT delta_syncs_base_snapshot_id_foreign FOREIGN KEY (base_snapshot_id) REFERENCES public.snapshots(id) ON DELETE SET NULL;


--
-- Name: delta_syncs delta_syncs_local_workspace_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.delta_syncs
    ADD CONSTRAINT delta_syncs_local_workspace_id_foreign FOREIGN KEY (local_workspace_id) REFERENCES public.local_workspaces(id) ON DELETE CASCADE;


--
-- Name: delta_syncs delta_syncs_new_snapshot_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.delta_syncs
    ADD CONSTRAINT delta_syncs_new_snapshot_id_foreign FOREIGN KEY (new_snapshot_id) REFERENCES public.snapshots(id) ON DELETE SET NULL;


--
-- Name: delta_syncs delta_syncs_project_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.delta_syncs
    ADD CONSTRAINT delta_syncs_project_id_foreign FOREIGN KEY (project_id) REFERENCES public.projects(id) ON DELETE CASCADE;


--
-- Name: delta_syncs delta_syncs_repository_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.delta_syncs
    ADD CONSTRAINT delta_syncs_repository_id_foreign FOREIGN KEY (repository_id) REFERENCES public.repositories(id) ON DELETE CASCADE;


--
-- Name: delta_syncs delta_syncs_run_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.delta_syncs
    ADD CONSTRAINT delta_syncs_run_id_foreign FOREIGN KEY (run_id) REFERENCES public.runs(id) ON DELETE CASCADE;


--
-- Name: devices devices_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.devices
    ADD CONSTRAINT devices_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: genesis_imports genesis_imports_local_workspace_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.genesis_imports
    ADD CONSTRAINT genesis_imports_local_workspace_id_foreign FOREIGN KEY (local_workspace_id) REFERENCES public.local_workspaces(id) ON DELETE CASCADE;


--
-- Name: genesis_imports genesis_imports_manifest_artifact_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.genesis_imports
    ADD CONSTRAINT genesis_imports_manifest_artifact_id_foreign FOREIGN KEY (manifest_artifact_id) REFERENCES public.artifacts(id) ON DELETE SET NULL;


--
-- Name: genesis_imports genesis_imports_project_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.genesis_imports
    ADD CONSTRAINT genesis_imports_project_id_foreign FOREIGN KEY (project_id) REFERENCES public.projects(id) ON DELETE CASCADE;


--
-- Name: genesis_imports genesis_imports_repository_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.genesis_imports
    ADD CONSTRAINT genesis_imports_repository_id_foreign FOREIGN KEY (repository_id) REFERENCES public.repositories(id) ON DELETE CASCADE;


--
-- Name: genesis_imports genesis_imports_run_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.genesis_imports
    ADD CONSTRAINT genesis_imports_run_id_foreign FOREIGN KEY (run_id) REFERENCES public.runs(id) ON DELETE CASCADE;


--
-- Name: genesis_imports genesis_imports_snapshot_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.genesis_imports
    ADD CONSTRAINT genesis_imports_snapshot_id_foreign FOREIGN KEY (snapshot_id) REFERENCES public.snapshots(id) ON DELETE SET NULL;


--
-- Name: hades_agent_artifacts hades_agent_artifacts_hades_agent_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.hades_agent_artifacts
    ADD CONSTRAINT hades_agent_artifacts_hades_agent_id_foreign FOREIGN KEY (hades_agent_id) REFERENCES public.hades_agents(id) ON DELETE SET NULL;


--
-- Name: hades_agent_artifacts hades_agent_artifacts_job_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.hades_agent_artifacts
    ADD CONSTRAINT hades_agent_artifacts_job_id_foreign FOREIGN KEY (job_id) REFERENCES public.hades_agent_jobs(id) ON DELETE SET NULL;


--
-- Name: hades_agent_artifacts hades_agent_artifacts_project_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.hades_agent_artifacts
    ADD CONSTRAINT hades_agent_artifacts_project_id_foreign FOREIGN KEY (project_id) REFERENCES public.projects(id) ON DELETE CASCADE;


--
-- Name: hades_agent_artifacts hades_agent_artifacts_workspace_binding_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.hades_agent_artifacts
    ADD CONSTRAINT hades_agent_artifacts_workspace_binding_id_foreign FOREIGN KEY (workspace_binding_id) REFERENCES public.hades_workspace_bindings(id) ON DELETE CASCADE;


--
-- Name: hades_agent_job_events hades_agent_job_events_job_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.hades_agent_job_events
    ADD CONSTRAINT hades_agent_job_events_job_id_foreign FOREIGN KEY (job_id) REFERENCES public.hades_agent_jobs(id) ON DELETE CASCADE;


--
-- Name: hades_agent_jobs hades_agent_jobs_hades_agent_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.hades_agent_jobs
    ADD CONSTRAINT hades_agent_jobs_hades_agent_id_foreign FOREIGN KEY (hades_agent_id) REFERENCES public.hades_agents(id) ON DELETE SET NULL;


--
-- Name: hades_agent_jobs hades_agent_jobs_project_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.hades_agent_jobs
    ADD CONSTRAINT hades_agent_jobs_project_id_foreign FOREIGN KEY (project_id) REFERENCES public.projects(id) ON DELETE CASCADE;


--
-- Name: hades_agent_jobs hades_agent_jobs_repository_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.hades_agent_jobs
    ADD CONSTRAINT hades_agent_jobs_repository_id_foreign FOREIGN KEY (repository_id) REFERENCES public.repositories(id) ON DELETE SET NULL;


--
-- Name: hades_agent_jobs hades_agent_jobs_requested_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.hades_agent_jobs
    ADD CONSTRAINT hades_agent_jobs_requested_by_user_id_foreign FOREIGN KEY (requested_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: hades_agent_jobs hades_agent_jobs_workspace_binding_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.hades_agent_jobs
    ADD CONSTRAINT hades_agent_jobs_workspace_binding_id_foreign FOREIGN KEY (workspace_binding_id) REFERENCES public.hades_workspace_bindings(id) ON DELETE CASCADE;


--
-- Name: hades_agent_tokens hades_agent_tokens_hades_agent_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.hades_agent_tokens
    ADD CONSTRAINT hades_agent_tokens_hades_agent_id_foreign FOREIGN KEY (hades_agent_id) REFERENCES public.hades_agents(id) ON DELETE CASCADE;


--
-- Name: hades_agent_tokens hades_agent_tokens_project_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.hades_agent_tokens
    ADD CONSTRAINT hades_agent_tokens_project_id_foreign FOREIGN KEY (project_id) REFERENCES public.projects(id) ON DELETE CASCADE;


--
-- Name: hades_agents hades_agents_project_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.hades_agents
    ADD CONSTRAINT hades_agents_project_id_foreign FOREIGN KEY (project_id) REFERENCES public.projects(id) ON DELETE CASCADE;


--
-- Name: hades_bootstrap_tokens hades_bootstrap_tokens_project_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.hades_bootstrap_tokens
    ADD CONSTRAINT hades_bootstrap_tokens_project_id_foreign FOREIGN KEY (project_id) REFERENCES public.projects(id) ON DELETE CASCADE;


--
-- Name: hades_bug_evidence_items hades_bug_evidence_items_bug_report_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.hades_bug_evidence_items
    ADD CONSTRAINT hades_bug_evidence_items_bug_report_id_foreign FOREIGN KEY (bug_report_id) REFERENCES public.hades_bug_reports(id) ON DELETE SET NULL;


--
-- Name: hades_bug_evidence_items hades_bug_evidence_items_hades_agent_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.hades_bug_evidence_items
    ADD CONSTRAINT hades_bug_evidence_items_hades_agent_id_foreign FOREIGN KEY (hades_agent_id) REFERENCES public.hades_agents(id) ON DELETE SET NULL;


--
-- Name: hades_bug_evidence_items hades_bug_evidence_items_project_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.hades_bug_evidence_items
    ADD CONSTRAINT hades_bug_evidence_items_project_id_foreign FOREIGN KEY (project_id) REFERENCES public.projects(id) ON DELETE CASCADE;


--
-- Name: hades_bug_evidence_items hades_bug_evidence_items_workspace_binding_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.hades_bug_evidence_items
    ADD CONSTRAINT hades_bug_evidence_items_workspace_binding_id_foreign FOREIGN KEY (workspace_binding_id) REFERENCES public.hades_workspace_bindings(id) ON DELETE CASCADE;


--
-- Name: hades_bug_reports hades_bug_reports_hades_agent_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.hades_bug_reports
    ADD CONSTRAINT hades_bug_reports_hades_agent_id_foreign FOREIGN KEY (hades_agent_id) REFERENCES public.hades_agents(id) ON DELETE SET NULL;


--
-- Name: hades_bug_reports hades_bug_reports_project_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.hades_bug_reports
    ADD CONSTRAINT hades_bug_reports_project_id_foreign FOREIGN KEY (project_id) REFERENCES public.projects(id) ON DELETE CASCADE;


--
-- Name: hades_bug_reports hades_bug_reports_workspace_binding_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.hades_bug_reports
    ADD CONSTRAINT hades_bug_reports_workspace_binding_id_foreign FOREIGN KEY (workspace_binding_id) REFERENCES public.hades_workspace_bindings(id) ON DELETE CASCADE;


--
-- Name: hades_causal_packs hades_causal_packs_bug_report_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.hades_causal_packs
    ADD CONSTRAINT hades_causal_packs_bug_report_id_foreign FOREIGN KEY (bug_report_id) REFERENCES public.hades_bug_reports(id) ON DELETE SET NULL;


--
-- Name: hades_causal_packs hades_causal_packs_hades_agent_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.hades_causal_packs
    ADD CONSTRAINT hades_causal_packs_hades_agent_id_foreign FOREIGN KEY (hades_agent_id) REFERENCES public.hades_agents(id) ON DELETE SET NULL;


--
-- Name: hades_causal_packs hades_causal_packs_project_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.hades_causal_packs
    ADD CONSTRAINT hades_causal_packs_project_id_foreign FOREIGN KEY (project_id) REFERENCES public.projects(id) ON DELETE CASCADE;


--
-- Name: hades_causal_packs hades_causal_packs_workspace_binding_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.hades_causal_packs
    ADD CONSTRAINT hades_causal_packs_workspace_binding_id_foreign FOREIGN KEY (workspace_binding_id) REFERENCES public.hades_workspace_bindings(id) ON DELETE CASCADE;


--
-- Name: hades_diagnosis_reports hades_diagnosis_reports_bug_report_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.hades_diagnosis_reports
    ADD CONSTRAINT hades_diagnosis_reports_bug_report_id_foreign FOREIGN KEY (bug_report_id) REFERENCES public.hades_bug_reports(id) ON DELETE SET NULL;


--
-- Name: hades_diagnosis_reports hades_diagnosis_reports_hades_agent_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.hades_diagnosis_reports
    ADD CONSTRAINT hades_diagnosis_reports_hades_agent_id_foreign FOREIGN KEY (hades_agent_id) REFERENCES public.hades_agents(id) ON DELETE SET NULL;


--
-- Name: hades_diagnosis_reports hades_diagnosis_reports_project_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.hades_diagnosis_reports
    ADD CONSTRAINT hades_diagnosis_reports_project_id_foreign FOREIGN KEY (project_id) REFERENCES public.projects(id) ON DELETE CASCADE;


--
-- Name: hades_diagnosis_reports hades_diagnosis_reports_workspace_binding_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.hades_diagnosis_reports
    ADD CONSTRAINT hades_diagnosis_reports_workspace_binding_id_foreign FOREIGN KEY (workspace_binding_id) REFERENCES public.hades_workspace_bindings(id) ON DELETE CASCADE;


--
-- Name: hades_doctor_reports hades_doctor_reports_hades_agent_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.hades_doctor_reports
    ADD CONSTRAINT hades_doctor_reports_hades_agent_id_foreign FOREIGN KEY (hades_agent_id) REFERENCES public.hades_agents(id) ON DELETE SET NULL;


--
-- Name: hades_doctor_reports hades_doctor_reports_project_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.hades_doctor_reports
    ADD CONSTRAINT hades_doctor_reports_project_id_foreign FOREIGN KEY (project_id) REFERENCES public.projects(id) ON DELETE CASCADE;


--
-- Name: hades_doctor_reports hades_doctor_reports_workspace_binding_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.hades_doctor_reports
    ADD CONSTRAINT hades_doctor_reports_workspace_binding_id_foreign FOREIGN KEY (workspace_binding_id) REFERENCES public.hades_workspace_bindings(id) ON DELETE SET NULL;


--
-- Name: hades_evidence_packs hades_evidence_packs_bug_report_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.hades_evidence_packs
    ADD CONSTRAINT hades_evidence_packs_bug_report_id_foreign FOREIGN KEY (bug_report_id) REFERENCES public.hades_bug_reports(id) ON DELETE SET NULL;


--
-- Name: hades_evidence_packs hades_evidence_packs_hades_agent_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.hades_evidence_packs
    ADD CONSTRAINT hades_evidence_packs_hades_agent_id_foreign FOREIGN KEY (hades_agent_id) REFERENCES public.hades_agents(id) ON DELETE SET NULL;


--
-- Name: hades_evidence_packs hades_evidence_packs_project_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.hades_evidence_packs
    ADD CONSTRAINT hades_evidence_packs_project_id_foreign FOREIGN KEY (project_id) REFERENCES public.projects(id) ON DELETE CASCADE;


--
-- Name: hades_evidence_packs hades_evidence_packs_workspace_binding_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.hades_evidence_packs
    ADD CONSTRAINT hades_evidence_packs_workspace_binding_id_foreign FOREIGN KEY (workspace_binding_id) REFERENCES public.hades_workspace_bindings(id) ON DELETE CASCADE;


--
-- Name: hades_memory_proposals hades_memory_proposals_hades_agent_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.hades_memory_proposals
    ADD CONSTRAINT hades_memory_proposals_hades_agent_id_foreign FOREIGN KEY (hades_agent_id) REFERENCES public.hades_agents(id) ON DELETE CASCADE;


--
-- Name: hades_memory_proposals hades_memory_proposals_memory_entry_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.hades_memory_proposals
    ADD CONSTRAINT hades_memory_proposals_memory_entry_id_foreign FOREIGN KEY (memory_entry_id) REFERENCES public.project_memory_entries(id) ON DELETE SET NULL;


--
-- Name: hades_memory_proposals hades_memory_proposals_project_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.hades_memory_proposals
    ADD CONSTRAINT hades_memory_proposals_project_id_foreign FOREIGN KEY (project_id) REFERENCES public.projects(id) ON DELETE CASCADE;


--
-- Name: hades_memory_proposals hades_memory_proposals_target_memory_entry_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.hades_memory_proposals
    ADD CONSTRAINT hades_memory_proposals_target_memory_entry_id_foreign FOREIGN KEY (target_memory_entry_id) REFERENCES public.project_memory_entries(id) ON DELETE SET NULL;


--
-- Name: hades_memory_proposals hades_memory_proposals_workspace_binding_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.hades_memory_proposals
    ADD CONSTRAINT hades_memory_proposals_workspace_binding_id_foreign FOREIGN KEY (workspace_binding_id) REFERENCES public.hades_workspace_bindings(id) ON DELETE CASCADE;


--
-- Name: hades_persephone_agent_messages hades_persephone_agent_messages_project_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.hades_persephone_agent_messages
    ADD CONSTRAINT hades_persephone_agent_messages_project_id_foreign FOREIGN KEY (project_id) REFERENCES public.projects(id) ON DELETE CASCADE;


--
-- Name: hades_persephone_agent_messages hades_persephone_agent_messages_target_workspace_binding_id_for; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.hades_persephone_agent_messages
    ADD CONSTRAINT hades_persephone_agent_messages_target_workspace_binding_id_for FOREIGN KEY (target_workspace_binding_id) REFERENCES public.hades_workspace_bindings(id) ON DELETE SET NULL;


--
-- Name: hades_persephone_events hades_persephone_events_hades_agent_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.hades_persephone_events
    ADD CONSTRAINT hades_persephone_events_hades_agent_id_foreign FOREIGN KEY (hades_agent_id) REFERENCES public.hades_agents(id) ON DELETE SET NULL;


--
-- Name: hades_persephone_events hades_persephone_events_project_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.hades_persephone_events
    ADD CONSTRAINT hades_persephone_events_project_id_foreign FOREIGN KEY (project_id) REFERENCES public.projects(id) ON DELETE CASCADE;


--
-- Name: hades_persephone_events hades_persephone_events_workspace_binding_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.hades_persephone_events
    ADD CONSTRAINT hades_persephone_events_workspace_binding_id_foreign FOREIGN KEY (workspace_binding_id) REFERENCES public.hades_workspace_bindings(id) ON DELETE SET NULL;


--
-- Name: hades_search_documents hades_search_documents_project_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.hades_search_documents
    ADD CONSTRAINT hades_search_documents_project_id_foreign FOREIGN KEY (project_id) REFERENCES public.projects(id) ON DELETE CASCADE;


--
-- Name: hades_search_documents hades_search_documents_workspace_binding_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.hades_search_documents
    ADD CONSTRAINT hades_search_documents_workspace_binding_id_foreign FOREIGN KEY (workspace_binding_id) REFERENCES public.hades_workspace_bindings(id) ON DELETE CASCADE;


--
-- Name: hades_source_slice_candidates hades_source_slice_candidates_job_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.hades_source_slice_candidates
    ADD CONSTRAINT hades_source_slice_candidates_job_id_foreign FOREIGN KEY (job_id) REFERENCES public.hades_agent_jobs(id) ON DELETE SET NULL;


--
-- Name: hades_source_slice_candidates hades_source_slice_candidates_project_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.hades_source_slice_candidates
    ADD CONSTRAINT hades_source_slice_candidates_project_id_foreign FOREIGN KEY (project_id) REFERENCES public.projects(id) ON DELETE CASCADE;


--
-- Name: hades_source_slice_candidates hades_source_slice_candidates_source_slice_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.hades_source_slice_candidates
    ADD CONSTRAINT hades_source_slice_candidates_source_slice_id_foreign FOREIGN KEY (source_slice_id) REFERENCES public.hades_source_slices(id) ON DELETE SET NULL;


--
-- Name: hades_source_slice_candidates hades_source_slice_candidates_workspace_binding_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.hades_source_slice_candidates
    ADD CONSTRAINT hades_source_slice_candidates_workspace_binding_id_foreign FOREIGN KEY (workspace_binding_id) REFERENCES public.hades_workspace_bindings(id) ON DELETE CASCADE;


--
-- Name: hades_source_slices hades_source_slices_hades_agent_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.hades_source_slices
    ADD CONSTRAINT hades_source_slices_hades_agent_id_foreign FOREIGN KEY (hades_agent_id) REFERENCES public.hades_agents(id) ON DELETE SET NULL;


--
-- Name: hades_source_slices hades_source_slices_job_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.hades_source_slices
    ADD CONSTRAINT hades_source_slices_job_id_foreign FOREIGN KEY (job_id) REFERENCES public.hades_agent_jobs(id) ON DELETE SET NULL;


--
-- Name: hades_source_slices hades_source_slices_project_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.hades_source_slices
    ADD CONSTRAINT hades_source_slices_project_id_foreign FOREIGN KEY (project_id) REFERENCES public.projects(id) ON DELETE CASCADE;


--
-- Name: hades_source_slices hades_source_slices_workspace_binding_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.hades_source_slices
    ADD CONSTRAINT hades_source_slices_workspace_binding_id_foreign FOREIGN KEY (workspace_binding_id) REFERENCES public.hades_workspace_bindings(id) ON DELETE CASCADE;


--
-- Name: hades_workspace_bindings hades_workspace_bindings_hades_agent_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.hades_workspace_bindings
    ADD CONSTRAINT hades_workspace_bindings_hades_agent_id_foreign FOREIGN KEY (hades_agent_id) REFERENCES public.hades_agents(id) ON DELETE CASCADE;


--
-- Name: hades_workspace_bindings hades_workspace_bindings_project_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.hades_workspace_bindings
    ADD CONSTRAINT hades_workspace_bindings_project_id_foreign FOREIGN KEY (project_id) REFERENCES public.projects(id) ON DELETE CASCADE;


--
-- Name: kanban_boards kanban_boards_project_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.kanban_boards
    ADD CONSTRAINT kanban_boards_project_id_foreign FOREIGN KEY (project_id) REFERENCES public.projects(id) ON DELETE CASCADE;


--
-- Name: kanban_columns kanban_columns_board_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.kanban_columns
    ADD CONSTRAINT kanban_columns_board_id_foreign FOREIGN KEY (board_id) REFERENCES public.kanban_boards(id) ON DELETE CASCADE;


--
-- Name: local_workspaces local_workspaces_device_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.local_workspaces
    ADD CONSTRAINT local_workspaces_device_id_foreign FOREIGN KEY (device_id) REFERENCES public.devices(id) ON DELETE CASCADE;


--
-- Name: local_workspaces local_workspaces_repository_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.local_workspaces
    ADD CONSTRAINT local_workspaces_repository_id_foreign FOREIGN KEY (repository_id) REFERENCES public.repositories(id) ON DELETE CASCADE;


--
-- Name: memory_import_batches memory_import_batches_cancelled_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.memory_import_batches
    ADD CONSTRAINT memory_import_batches_cancelled_by_user_id_foreign FOREIGN KEY (cancelled_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: memory_import_batches memory_import_batches_project_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.memory_import_batches
    ADD CONSTRAINT memory_import_batches_project_id_foreign FOREIGN KEY (project_id) REFERENCES public.projects(id) ON DELETE CASCADE;


--
-- Name: memory_import_batches memory_import_batches_requested_by_hades_agent_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.memory_import_batches
    ADD CONSTRAINT memory_import_batches_requested_by_hades_agent_id_foreign FOREIGN KEY (requested_by_hades_agent_id) REFERENCES public.hades_agents(id) ON DELETE SET NULL;


--
-- Name: memory_import_batches memory_import_batches_requested_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.memory_import_batches
    ADD CONSTRAINT memory_import_batches_requested_by_user_id_foreign FOREIGN KEY (requested_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: memory_import_batches memory_import_batches_source_workspace_binding_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.memory_import_batches
    ADD CONSTRAINT memory_import_batches_source_workspace_binding_id_foreign FOREIGN KEY (source_workspace_binding_id) REFERENCES public.hades_workspace_bindings(id) ON DELETE SET NULL;


--
-- Name: memory_import_batches memory_import_batches_target_workspace_binding_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.memory_import_batches
    ADD CONSTRAINT memory_import_batches_target_workspace_binding_id_foreign FOREIGN KEY (target_workspace_binding_id) REFERENCES public.hades_workspace_bindings(id) ON DELETE CASCADE;


--
-- Name: memory_import_items memory_import_items_batch_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.memory_import_items
    ADD CONSTRAINT memory_import_items_batch_id_foreign FOREIGN KEY (batch_id) REFERENCES public.memory_import_batches(id) ON DELETE CASCADE;


--
-- Name: memory_import_items memory_import_items_proposal_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.memory_import_items
    ADD CONSTRAINT memory_import_items_proposal_id_foreign FOREIGN KEY (proposal_id) REFERENCES public.hades_memory_proposals(id) ON DELETE SET NULL;


--
-- Name: memory_import_items memory_import_items_target_memory_entry_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.memory_import_items
    ADD CONSTRAINT memory_import_items_target_memory_entry_id_foreign FOREIGN KEY (target_memory_entry_id) REFERENCES public.project_memory_entries(id) ON DELETE SET NULL;


--
-- Name: project_memory_entries project_memory_entries_author_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.project_memory_entries
    ADD CONSTRAINT project_memory_entries_author_user_id_foreign FOREIGN KEY (author_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: project_memory_entries project_memory_entries_project_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.project_memory_entries
    ADD CONSTRAINT project_memory_entries_project_id_foreign FOREIGN KEY (project_id) REFERENCES public.projects(id) ON DELETE CASCADE;


--
-- Name: project_memory_entries project_memory_entries_repository_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.project_memory_entries
    ADD CONSTRAINT project_memory_entries_repository_id_foreign FOREIGN KEY (repository_id) REFERENCES public.repositories(id) ON DELETE SET NULL;


--
-- Name: project_memory_entries project_memory_entries_run_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.project_memory_entries
    ADD CONSTRAINT project_memory_entries_run_id_foreign FOREIGN KEY (run_id) REFERENCES public.runs(id) ON DELETE SET NULL;


--
-- Name: project_memory_entries project_memory_entries_task_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.project_memory_entries
    ADD CONSTRAINT project_memory_entries_task_id_foreign FOREIGN KEY (task_id) REFERENCES public.tasks(id) ON DELETE SET NULL;


--
-- Name: project_memory_links project_memory_links_memory_entry_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.project_memory_links
    ADD CONSTRAINT project_memory_links_memory_entry_id_foreign FOREIGN KEY (memory_entry_id) REFERENCES public.project_memory_entries(id) ON DELETE CASCADE;


--
-- Name: projects projects_archived_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.projects
    ADD CONSTRAINT projects_archived_by_user_id_foreign FOREIGN KEY (archived_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: projects projects_created_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.projects
    ADD CONSTRAINT projects_created_by_user_id_foreign FOREIGN KEY (created_by_user_id) REFERENCES public.users(id) ON DELETE RESTRICT;


--
-- Name: projects projects_deleted_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.projects
    ADD CONSTRAINT projects_deleted_by_user_id_foreign FOREIGN KEY (deleted_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: projects projects_restored_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.projects
    ADD CONSTRAINT projects_restored_by_user_id_foreign FOREIGN KEY (restored_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: repositories repositories_project_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.repositories
    ADD CONSTRAINT repositories_project_id_foreign FOREIGN KEY (project_id) REFERENCES public.projects(id) ON DELETE CASCADE;


--
-- Name: repository_task repository_task_repository_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.repository_task
    ADD CONSTRAINT repository_task_repository_id_foreign FOREIGN KEY (repository_id) REFERENCES public.repositories(id) ON DELETE CASCADE;


--
-- Name: repository_task repository_task_task_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.repository_task
    ADD CONSTRAINT repository_task_task_id_foreign FOREIGN KEY (task_id) REFERENCES public.tasks(id) ON DELETE CASCADE;


--
-- Name: role_user role_user_role_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.role_user
    ADD CONSTRAINT role_user_role_id_foreign FOREIGN KEY (role_id) REFERENCES public.roles(id) ON DELETE CASCADE;


--
-- Name: role_user role_user_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.role_user
    ADD CONSTRAINT role_user_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: run_events run_events_run_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.run_events
    ADD CONSTRAINT run_events_run_id_foreign FOREIGN KEY (run_id) REFERENCES public.runs(id) ON DELETE CASCADE;


--
-- Name: runs runs_device_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.runs
    ADD CONSTRAINT runs_device_id_foreign FOREIGN KEY (device_id) REFERENCES public.devices(id) ON DELETE RESTRICT;


--
-- Name: runs runs_local_workspace_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.runs
    ADD CONSTRAINT runs_local_workspace_id_foreign FOREIGN KEY (local_workspace_id) REFERENCES public.local_workspaces(id) ON DELETE SET NULL;


--
-- Name: runs runs_project_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.runs
    ADD CONSTRAINT runs_project_id_foreign FOREIGN KEY (project_id) REFERENCES public.projects(id) ON DELETE CASCADE;


--
-- Name: runs runs_repository_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.runs
    ADD CONSTRAINT runs_repository_id_foreign FOREIGN KEY (repository_id) REFERENCES public.repositories(id) ON DELETE SET NULL;


--
-- Name: runs runs_started_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.runs
    ADD CONSTRAINT runs_started_by_user_id_foreign FOREIGN KEY (started_by_user_id) REFERENCES public.users(id) ON DELETE RESTRICT;


--
-- Name: runs runs_task_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.runs
    ADD CONSTRAINT runs_task_id_foreign FOREIGN KEY (task_id) REFERENCES public.tasks(id) ON DELETE SET NULL;


--
-- Name: snapshots snapshots_created_by_run_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.snapshots
    ADD CONSTRAINT snapshots_created_by_run_id_foreign FOREIGN KEY (created_by_run_id) REFERENCES public.runs(id) ON DELETE RESTRICT;


--
-- Name: snapshots snapshots_file_inventory_artifact_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.snapshots
    ADD CONSTRAINT snapshots_file_inventory_artifact_id_foreign FOREIGN KEY (file_inventory_artifact_id) REFERENCES public.artifacts(id) ON DELETE SET NULL;


--
-- Name: snapshots snapshots_graph_snapshot_artifact_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.snapshots
    ADD CONSTRAINT snapshots_graph_snapshot_artifact_id_foreign FOREIGN KEY (graph_snapshot_artifact_id) REFERENCES public.artifacts(id) ON DELETE SET NULL;


--
-- Name: snapshots snapshots_local_workspace_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.snapshots
    ADD CONSTRAINT snapshots_local_workspace_id_foreign FOREIGN KEY (local_workspace_id) REFERENCES public.local_workspaces(id) ON DELETE CASCADE;


--
-- Name: snapshots snapshots_project_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.snapshots
    ADD CONSTRAINT snapshots_project_id_foreign FOREIGN KEY (project_id) REFERENCES public.projects(id) ON DELETE CASCADE;


--
-- Name: snapshots snapshots_repository_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.snapshots
    ADD CONSTRAINT snapshots_repository_id_foreign FOREIGN KEY (repository_id) REFERENCES public.repositories(id) ON DELETE CASCADE;


--
-- Name: task_attachments task_attachments_deleted_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.task_attachments
    ADD CONSTRAINT task_attachments_deleted_by_user_id_foreign FOREIGN KEY (deleted_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: task_attachments task_attachments_project_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.task_attachments
    ADD CONSTRAINT task_attachments_project_id_foreign FOREIGN KEY (project_id) REFERENCES public.projects(id) ON DELETE CASCADE;


--
-- Name: task_attachments task_attachments_task_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.task_attachments
    ADD CONSTRAINT task_attachments_task_id_foreign FOREIGN KEY (task_id) REFERENCES public.tasks(id) ON DELETE CASCADE;


--
-- Name: task_attachments task_attachments_uploaded_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.task_attachments
    ADD CONSTRAINT task_attachments_uploaded_by_user_id_foreign FOREIGN KEY (uploaded_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: tasks tasks_created_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.tasks
    ADD CONSTRAINT tasks_created_by_user_id_foreign FOREIGN KEY (created_by_user_id) REFERENCES public.users(id) ON DELETE RESTRICT;


--
-- Name: tasks tasks_owner_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.tasks
    ADD CONSTRAINT tasks_owner_user_id_foreign FOREIGN KEY (owner_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: tasks tasks_project_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.tasks
    ADD CONSTRAINT tasks_project_id_foreign FOREIGN KEY (project_id) REFERENCES public.projects(id) ON DELETE CASCADE;


--
-- Name: tasks tasks_status_column_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.tasks
    ADD CONSTRAINT tasks_status_column_id_foreign FOREIGN KEY (status_column_id) REFERENCES public.kanban_columns(id) ON DELETE RESTRICT;


--
-- Name: wiki_pages wiki_pages_project_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.wiki_pages
    ADD CONSTRAINT wiki_pages_project_id_foreign FOREIGN KEY (project_id) REFERENCES public.projects(id) ON DELETE CASCADE;


--
-- Name: wiki_pages wiki_pages_repository_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.wiki_pages
    ADD CONSTRAINT wiki_pages_repository_id_foreign FOREIGN KEY (repository_id) REFERENCES public.repositories(id) ON DELETE SET NULL;


--
-- Name: wiki_revisions wiki_revisions_author_device_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.wiki_revisions
    ADD CONSTRAINT wiki_revisions_author_device_id_foreign FOREIGN KEY (author_device_id) REFERENCES public.devices(id) ON DELETE SET NULL;


--
-- Name: wiki_revisions wiki_revisions_author_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.wiki_revisions
    ADD CONSTRAINT wiki_revisions_author_user_id_foreign FOREIGN KEY (author_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: wiki_revisions wiki_revisions_wiki_page_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: devboard
--

ALTER TABLE ONLY public.wiki_revisions
    ADD CONSTRAINT wiki_revisions_wiki_page_id_foreign FOREIGN KEY (wiki_page_id) REFERENCES public.wiki_pages(id) ON DELETE CASCADE;


--
-- PostgreSQL database dump complete
--

\unrestrict e3vtLNWba5L2eS5Vxic9H92X7a8bLJfxo8FRupXzjOo2fwMt5l9WibPYEhOcxI4

