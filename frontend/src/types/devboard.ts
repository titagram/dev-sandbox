// Hades Agent typed domain model.
// Every technical fact carries source metadata. See API_CONTRACT.md.

export type Role = "admin" | "pm" | "developer" | "sysadmin" | "agent";

export type SourceType =
  | "local_plugin_snapshot"
  | "local_plugin_diff"
  | "local_analyzer"
  | "server_history"
  | "user_manual"
  | "ai_generated";

export type SourceStatus =
  | "verified_from_code"
  | "developer_provided"
  | "ai_generated"
  | "needs_verification"
  | "stale"
  | "conflict_with_code";

export interface SourceMeta {
  type: SourceType;
  status: SourceStatus;
  /** Human label of the producing source, e.g. "plugin@dev-mbp / snapshot #4821". */
  origin: string;
  /** ISO-8601 timestamp the fact was produced. */
  generated_at: string;
  /** Optional pointer back to the producing run / snapshot / diff. */
  ref?: string;
}

export type RiskLevel = "low" | "medium" | "high" | "critical";

export type RunStatus =
  | "queued"
  | "running"
  | "passed"
  | "failed"
  | "needs_review"
  | "reviewed"
  | "cancelled";

export type TaskColumn =
  | "backlog"
  | "ready"
  | "in_progress"
  | "blocked"
  | "review"
  | "done";

export type GitMode = "local_clone" | "local_worktree" | "local_bare";

export type PipelineStatus =
  | "not_started"
  | "pending"
  | "in_progress"
  | "complete"
  | "stale"
  | "failed";

export type AgentKey =
  | "socrates"
  | "platon"
  | "aristoteles"
  | "local_agent"
  | "socrate_supervisor"
  | "task_clarifier"
  | "backlog_triage";

export type AgentWorkStatus =
  | "draft"
  | "queued"
  | "claimed"
  | "running"
  | "completed"
  | "completed_with_incomplete_memory"
  | "failed"
  | "canceled";

export type AgentWorkPriority = "low" | "normal" | "high" | "urgent";

export type MemoryEntryKind =
  | "decision"
  | "implementation"
  | "clarification"
  | "risk"
  | "verification"
  | "handoff"
  | "incident"
  | "logbook"
  | "agent_note";

export type MemoryCompleteness = "complete" | "incomplete";
export type ProjectMemorySource =
  | "dashboard_user"
  | "user_inserted"
  | "server_agent"
  | "hades_agent"
  | "local_agent"
  | "system_event";
export type ProjectMemoryDomain = "logbook" | "wiki" | "agent_notes";

// ---------- Auth ----------

export interface User {
  id: string;
  name: string;
  email: string;
  role: Role;
  avatar_color: string;
}

export interface LoginPayload {
  email: string;
  password: string;
  /** Mock-only: lets the demo log in as any role without real credentials. */
  role?: Role;
}

// ---------- Projects & Repositories ----------

export interface Repository {
  id: string;
  project_id: string;
  key?: string;
  name: string;
  default_branch: string;
  git_mode: GitMode;
  last_local_snapshot: string | null;
  local_workspace?: LocalWorkspace;
  genesis_status: PipelineStatus;
  delta_status: PipelineStatus;
  graph_status: PipelineStatus;
  wiki_status: PipelineStatus;
  risk_level: RiskLevel;
  latest_run_id: string | null;
  latest_run_status: RunStatus | null;
  source: SourceMeta;
}

export interface PolicySummary {
  code_write_allowed: boolean;
  destructive_scans_allowed: boolean;
  auto_import_on_snapshot: boolean;
  require_review_above_risk: RiskLevel;
  retention_days: number;
}

export type ProjectStatus = "active" | "archived" | "deleted";

export type ProjectStatusFilter = ProjectStatus;

export type ProjectKickstartState =
  | "draft"
  | "awaiting_project_intake"
  | "awaiting_repository_declaration"
  | "awaiting_local_workspace_link"
  | "awaiting_genesis"
  | "analyzing"
  | "knowledge_review"
  | "active";

export type ProjectKickstartStepKey =
  | "project_intake"
  | "repository_declaration"
  | "local_workspace_link"
  | "genesis"
  | "knowledge_review";

export type ProjectKickstartStepStatus = "pending" | "current" | "complete" | "blocked";

export interface ProjectKickstartStep {
  key: ProjectKickstartStepKey;
  status: ProjectKickstartStepStatus;
}

export interface ProjectKickstartPairing {
  /** Operational metadata only. Browser code must not call the plugin API. */
  api_base: string;
}

export interface ProjectKickstart {
  state: ProjectKickstartState;
  steps: ProjectKickstartStep[];
  pairing: ProjectKickstartPairing;
}

export type AssistantSuggestionStatus =
  | "pending"
  | "accepted"
  | "rejected"
  | "superseded"
  | "applied"
  | (string & {});

export interface TaskClarificationPayload {
  questions?: string[];
  acceptance_criteria?: string[];
  risks?: string[];
  missing_context?: string[];
  confidence?: number;
}

export interface BacklogTriageGroup {
  label: string;
  task_ids?: string[];
  reason?: string;
}

export interface BacklogTriageRecommendation {
  title: string;
  body: string;
  task_ids?: string[];
  priority?: string;
}

export interface BacklogTriagePayload {
  summary?: string;
  groups?: BacklogTriageGroup[];
  recommendations?: BacklogTriageRecommendation[];
  risks?: string[];
  confidence?: number;
}

export interface AssistantEvidenceRef {
  type?: string;
  id?: string;
  label?: string;
  ref?: string;
  source_status?: SourceStatus;
}

export interface AssistantSuggestion<TPayload = Record<string, unknown>> {
  id: string;
  assistant_run_id: string;
  project_id?: string;
  target_type?: string;
  target_id?: string;
  suggestion_type: string;
  title: string;
  body_markdown: string;
  structured_payload: TPayload;
  evidence_refs: AssistantEvidenceRef[];
  confidence: number;
  approval_required: boolean;
  status: AssistantSuggestionStatus;
  resolved_by_user_id?: string | null;
  resolved_at?: string | null;
  created_at: string;
  updated_at?: string;
}

export interface AssistantRun {
  id: string;
  project_id: string;
  agent_key: AgentKey;
  target_type: string;
  target_id: string;
  status: string;
  context_hash: string;
  result_summary: string;
  started_at: string;
  finished_at: string | null;
}

export interface AssistantRunResponse<TPayload = Record<string, unknown>> {
  run: AssistantRun;
  suggestion: AssistantSuggestion<TPayload>;
}

export interface AssistantSuggestionResponse<TPayload = Record<string, unknown>> {
  suggestion: AssistantSuggestion<TPayload>;
}

export interface AssistantApplyResponse {
  suggestion: AssistantSuggestion<TaskClarificationPayload>;
  task: TaskDetail;
}

export interface TaskAssistantState {
  clarify_href?: string;
  resolve_suggestion_href?: string;
  apply_suggestion_href?: string;
  latest_suggestion?: AssistantSuggestion<TaskClarificationPayload> | null;
}

export interface ProjectAssistantState {
  triage_href?: string;
  latest_backlog_triage_suggestion?: AssistantSuggestion<BacklogTriagePayload> | null;
}

export interface AiModelProvider {
  id: string;
  provider_key: string;
  display_name: string;
  provider_type: string;
  base_url: string | null;
  api_key_configured: boolean;
  api_key_last_four: string | null;
  api_key_updated_at: string | null;
  enabled: boolean;
  metadata: Record<string, unknown>;
}

export interface AiModelProfile {
  id: string;
  profile_key: string;
  display_name: string;
  provider_key: string;
  provider_name: string;
  model_name: string;
  runtime_profile: string;
  max_context: number | null;
  max_output_tokens: number;
  temperature: number;
  timeout_seconds: number;
  enabled: boolean;
}

export interface AiAgentProfile {
  id: string;
  agent_key: string;
  display_name: string;
  description: string;
  agent_type: string;
  delegation_mode: string;
  parent_agent_key: string | null;
  default_model_profile_id: string | null;
  requires_human_approval: boolean;
  enabled: boolean;
  allowed_tools: unknown[];
  output_schema: Record<string, unknown>;
  trigger_events: string[];
}

export interface AiAgentsSnapshot {
  providers: AiModelProvider[];
  modelProfiles: AiModelProfile[];
  agentProfiles: AiAgentProfile[];
}

export interface AiModelProviderInput {
  display_name: string;
  base_url: string | null;
  api_key?: string | null;
  clear_api_key?: boolean;
  enabled: boolean;
}

export interface AiModelProfileInput {
  display_name: string;
  model_name: string;
  runtime_profile: string;
  max_context: number | null;
  max_output_tokens: number;
  temperature: number;
  timeout_seconds: number;
  enabled: boolean;
}

export interface AiModelProfileCreateInput extends AiModelProfileInput {
  provider_key: string;
  profile_key: string;
}

export interface AiModelProviderValidationResult {
  status: "valid" | "invalid" | "unknown" | "ready_for_runtime" | "disabled" | "missing_configuration";
  checked_at: string;
  message?: string | null;
  redacted_error?: string | null;
  models?: string[];
}

export interface AiAgentProfileInput {
  default_model_profile_id: string | null;
  enabled: boolean;
}

export type LocalWorkspaceStatus = "missing" | "linked" | "stale" | "unknown";

export interface LocalWorkspace {
  id?: string | null;
  status: LocalWorkspaceStatus;
  display_path?: string | null;
  current_branch?: string | null;
  last_head_sha?: string | null;
  dirty_status?: string | null;
  last_seen_at?: string | null;
  device_name?: string | null;
  remote_name?: string | null;
  remote_url_host?: string | null;
  remote_url_hash?: string | null;
  upstream_branch?: string | null;
  ahead_count?: number | null;
  behind_count?: number | null;
  git_state_observed_at?: string | null;
  source_truth?: "local_agent_reported" | string | null;
}

export interface Project {
  id: string;
  key: string;
  name: string;
  description: string;
  owner: string;
  repository_count: number;
  open_tasks: number;
  risk_level: RiskLevel;
  wiki_freshness: PipelineStatus;
  genesis_status: PipelineStatus;
  delta_status: PipelineStatus;
  graph_status: PipelineStatus;
  updated_at: string;
  status: ProjectStatus;
  archived_at: string | null;
  deleted_at: string | null;
  restored_at: string | null;
}

export interface ProjectDetail extends Project {
  repositories: Repository[];
  policy: PolicySummary;
  recent_run_ids: string[];
  latest_artifact_ids: string[];
  kickstart?: ProjectKickstart;
  assistant?: ProjectAssistantState;
}

export interface ProjectInput {
  name: string;
  key: string;
  description: string;
}

export interface RepositoryDeclarationInput {
  name: string;
  key: string;
  default_branch: string;
  protected_paths?: string[];
  excluded_paths?: string[];
  stack_hints?: string[];
}

export interface ProjectLifecycleInput {
  reason?: string;
}

export interface DashboardOverview {
  summary: {
    active_projects: number;
    repositories_awaiting_genesis: number;
  };
  tasks: {
    total: number;
    blocked: number;
    by_state: Record<TaskColumn, number>;
    by_risk: Record<RiskLevel, number>;
  };
  runs: {
    failed: number;
    running: number;
  };
  wiki: {
    stale_pages: number;
  };
  agents: {
    online: number;
    offline: number;
  };
  projects: Project[];
}

// ---------- Kanban / Tasks ----------

export type TaskAttachmentKind = "image" | "file";
export type TaskAttachmentStatus = "available" | "blocked" | "deleted";
export type TaskAttachmentScanStatus = "not_scanned" | "clean" | "blocked" | "failed";

export interface TaskAttachment {
  id: string;
  task_id: string;
  project_id: string;
  name: string;
  mime_type: string;
  kind: TaskAttachmentKind;
  status: TaskAttachmentStatus;
  scan_status: TaskAttachmentScanStatus;
  size_bytes: number;
  uploaded_at: string;
  uploaded_by: string;
  download_url: string;
  preview_url: string | null;
}

export interface TaskRepositoryRef {
  id: string;
  name: string;
}

export interface TaskMutationInput {
  title?: string;
  description?: string | null;
  column?: TaskColumn;
  priority?: AgentWorkPriority;
  risk?: RiskLevel;
  owner_user_id?: number | null;
  repository_ids?: string[];
  acceptance_criteria?: string[];
}

export interface TaskCreateInput extends TaskMutationInput {
  title: string;
}

export interface TaskCard {
  id: string;
  title: string;
  column: TaskColumn;
  owner: string;
  owner_color: string;
  risk: RiskLevel;
  project_id: string;
  repositories: string[];
  linked_run_id: string | null;
  linked_run_status: RunStatus | null;
  wiki_page_id: string | null;
  attachment_count: number;
  image_attachment_count: number;
  source_status: SourceStatus;
  blocked: boolean;
  blocked_reason?: string;
  updated_at: string;
}

export interface KanbanColumn {
  id: TaskColumn;
  title: string;
  task_ids: string[];
}

export interface KanbanBoard {
  columns: KanbanColumn[];
  tasks: Record<string, TaskCard>;
}

export interface TaskDetail extends TaskCard {
  description: string;
  acceptance_criteria: string[];
  attachments: TaskAttachment[];
  assistant?: TaskAssistantState;
  audit_ids: string[];
  graph_node_ids: string[];
  source: SourceMeta;
}

export interface ProjectMemoryEntry {
  id: string;
  project_id: string;
  repository_id: string | null;
  task_id: string | null;
  run_id: string | null;
  author_user_id: number | null;
  agent_key: AgentKey | null;
  source: ProjectMemorySource;
  kind: MemoryEntryKind;
  domain?: ProjectMemoryDomain;
  completeness: MemoryCompleteness;
  summary: string;
  payload: Record<string, unknown>;
  occurred_at: string;
  created_at: string;
}

export interface ProjectMemoryResponse {
  domain?: ProjectMemoryDomain | "all";
  query?: string | null;
  domains?: Record<ProjectMemoryDomain, number>;
  entries: ProjectMemoryEntry[];
}

export interface ProjectMemoryQuery {
  domain?: ProjectMemoryDomain | "all";
  query?: string;
}

export interface MemoryCreateInput {
  repository_id?: string | null;
  task_id?: string | null;
  run_id?: string | null;
  kind: MemoryEntryKind;
  completeness?: MemoryCompleteness;
  summary: string;
  payload: Record<string, unknown>;
}

export interface MemoryUpdateInput {
  repository_id?: string | null;
  task_id?: string | null;
  run_id?: string | null;
  kind: MemoryEntryKind;
  completeness: MemoryCompleteness;
  summary: string;
  payload: Record<string, unknown>;
}

export interface ProjectWorkspaceMemoryCounts {
  entries: number;
  proposals: number;
  imports: number;
}

export interface ProjectWorkspaceBinding {
  id: string;
  project_id: string;
  status: string;
  display_path: string;
  workspace_fingerprint: string | null;
  git_remote_hash: string | null;
  head_commit: string | null;
  agent_label: string | null;
  external_agent_id: string | null;
  last_seen_at: string | null;
  memory_counts: ProjectWorkspaceMemoryCounts;
}

export type ProjectMemoryImportMode = "copy_as_proposals" | "copy_accepted";
export type ProjectMemoryImportDedupeStrategy = "source_hash" | "summary_payload_hash" | "provenance_hash";
export type ProjectMemoryImportConflictPolicy = "skip" | "proposal" | "mark_conflicted";
export type ProjectMemoryImportStatus = "pending" | "queued" | "running" | "completed" | "failed" | "cancelled";
export type ProjectMemoryImportReviewStatus = "empty" | "review_pending" | "applied" | "no_action_required" | "cancelled";

export interface ProjectMemoryImportFilters {
  kinds?: MemoryEntryKind[];
  since?: string | null;
  limit?: number | null;
}

export interface ProjectMemoryImportInput {
  source_workspace_binding_id: string;
  target_workspace_binding_id: string;
  mode: ProjectMemoryImportMode;
  filters?: ProjectMemoryImportFilters;
  dedupe_strategy: ProjectMemoryImportDedupeStrategy;
  conflict_policy: ProjectMemoryImportConflictPolicy;
  reason: string;
}

export interface ProjectMemoryImportBatch {
  id: string;
  project_id: string;
  source_workspace_binding_id: string | null;
  target_workspace_binding_id: string;
  requested_by_user_id: number | null;
  status: ProjectMemoryImportStatus;
  review_status?: ProjectMemoryImportReviewStatus;
  mode: ProjectMemoryImportMode;
  dedupe_strategy: ProjectMemoryImportDedupeStrategy;
  conflict_policy: ProjectMemoryImportConflictPolicy;
  reason: string;
  filters: ProjectMemoryImportFilters;
  counts: {
    entries_found: number;
    proposals_created: number;
    accepted_created: number;
    skipped_duplicates: number;
    conflicted: number;
    cancelled?: number;
  };
  items?: ProjectMemoryImportItem[];
  created_at: string;
  updated_at: string;
  completed_at: string | null;
  cancelled_at?: string | null;
  cancelled_by_user_id?: number | null;
  cancel_reason?: string | null;
}

export interface ProjectMemoryImportItem {
  id: string;
  source_local_id: string | null;
  source_hash: string;
  proposal_id: string | null;
  target_memory_entry_id: string | null;
  status: string;
  conflict_reason: string | null;
  provenance: Record<string, unknown>;
}

export interface AgentWorkItem {
  id: string;
  project_id: string;
  repository_id: string | null;
  task_id: string | null;
  requested_by_user_id: number | null;
  assigned_agent_key: AgentKey;
  status: AgentWorkStatus;
  priority: AgentWorkPriority;
  title: string;
  prompt: string;
  payload: Record<string, unknown>;
  requires_memory_entry: boolean;
  result_memory_entry_id: string | null;
  claimed_by_device_id: string | null;
  claimed_at: string | null;
  heartbeat_at: string | null;
  completed_at: string | null;
  failed_at: string | null;
  canceled_at: string | null;
  failure_reason: string | null;
  archived_at?: string | null;
  archived_by_user_id?: number | null;
  archive_reason?: string | null;
  created_at: string;
  updated_at: string;
}

export interface AgentWorkResponse {
  items: AgentWorkItem[];
}

export interface AgentWorkEvent {
  id: string;
  event_type: string;
  actor_user_id: number | null;
  actor_device_id: string | null;
  message: string | null;
  payload: Record<string, unknown>;
  created_at: string;
}

export interface AgentWorkChatMessage {
  id: string;
  role: string;
  content: string;
  metadata: Record<string, unknown>;
  created_at: string;
}

export interface AgentWorkChat {
  run_id: string | null;
  agent_key: AgentKey;
  messages: AgentWorkChatMessage[];
}

export interface AgentWorkDetailItem extends AgentWorkItem {
  result_memory_entry: ProjectMemoryEntry | null;
  events: AgentWorkEvent[];
  chat: AgentWorkChat;
}

export interface AgentWorkDetailResponse {
  item: AgentWorkDetailItem;
}

export interface AgentWorkCreateInput {
  repository_id?: string | null;
  task_id?: string | null;
  assigned_agent_key: AgentKey;
  priority?: AgentWorkPriority;
  title: string;
  prompt: string;
  payload?: Record<string, unknown>;
  requires_memory_entry?: boolean;
}

export type AgentChatStatus =
  | "active"
  | "waiting_for_agent"
  | "pending_local_agent"
  | "failed"
  | "archived";

export type AgentChatMessageRole = "user" | "assistant" | "system";

export interface AgentChatLastMessage {
  id: string;
  role: AgentChatMessageRole;
  content: string;
  created_at: string;
}

export interface AgentChatMessage extends AgentChatLastMessage {
  thread_id: string;
  author_user_id: number | null;
  assistant_run_id: string | null;
  agent_work_item_id: string | null;
  metadata: Record<string, unknown>;
}

export interface AgentChatThreadSummary {
  id: string;
  project_id: string;
  repository_id: string | null;
  task_id: string | null;
  created_by_user_id: number | null;
  agent_key: AgentKey;
  title: string;
  status: AgentChatStatus;
  latest_agent_work_item_id: string | null;
  latest_assistant_run_id: string | null;
  last_message_at: string | null;
  archived_at?: string | null;
  archived_by_user_id?: number | null;
  archive_reason?: string | null;
  message_count: number;
  last_message: AgentChatLastMessage | null;
  metadata: Record<string, unknown>;
  created_at: string;
  updated_at: string;
}

export interface AgentChatThread extends AgentChatThreadSummary {
  messages: AgentChatMessage[];
}

export interface AgentChatResponse {
  threads: AgentChatThreadSummary[];
}

export interface AgentChatDetailResponse {
  thread: AgentChatThread;
}

export interface AgentChatCreateInput {
  agent_key: AgentKey;
  title?: string | null;
  repository_id?: string | null;
  task_id?: string | null;
  metadata?: Record<string, unknown>;
  initial_message?: string | null;
}

export interface AgentChatMessageInput {
  content: string;
  metadata?: Record<string, unknown>;
}

// ---------- Runs ----------

export interface TimelineEvent {
  id: string;
  ts: string;
  label: string;
  status: "ok" | "warn" | "error" | "info";
  detail?: string;
}

export interface RunMetric {
  label: string;
  value: string;
  delta?: string;
  tone?: "neutral" | "good" | "warn" | "bad";
}

export interface SafetyResult {
  id: string;
  name: string;
  status: "pass" | "fail" | "warning" | "skipped";
  detail: string;
}

export interface RiskTrigger {
  id: string;
  label: string;
  level: RiskLevel;
  reason: string;
}

export interface AuditEvent {
  id: string;
  ts: string;
  actor: string;
  action: string;
  target: string;
  result: "ok" | "denied" | "error";
}

export interface RunSummary {
  id: string;
  project_id: string;
  repository_id: string;
  repository_name: string;
  type: "genesis_import" | "delta_sync" | "analysis" | "verification";
  status: RunStatus;
  risk_level: RiskLevel;
  started_at: string;
  finished_at: string | null;
  duration_ms: number | null;
  task_id: string | null;
  source: SourceMeta;
  reviewed_by?: string | null;
}

export interface RunDetail extends RunSummary {
  local_source_labels: { label: string; value: string; source: SourceMeta }[];
  timeline: TimelineEvent[];
  metrics: RunMetric[];
  risk_triggers: RiskTrigger[];
  safety_results: SafetyResult[];
  artifact_ids: string[];
  test_output: string;
  graph_status: PipelineStatus;
  wiki_status: PipelineStatus;
  wiki_page_id: string | null;
  audit_events: AuditEvent[];
}

// ---------- Wiki ----------

export interface WikiPageSummary {
  id: string;
  title: string;
  project_id: string;
  category: string;
  source_status: SourceStatus;
  has_evidence: boolean;
  updated_at: string;
  source: SourceMeta;
}

export interface WikiEvidence {
  id: string;
  label: string;
  kind: "code_ref" | "run_ref" | "artifact_ref" | "manual_note";
  ref: string;
  source: SourceMeta;
}

export interface WikiPageDetail extends WikiPageSummary {
  body_markdown: string;
  evidence: WikiEvidence[];
  related_run_ids: string[];
  related_node_ids: string[];
}

export interface WikiPageWriteInput {
  repository_id?: string | null;
  slug?: string;
  title: string;
  page_type: "business" | "technical" | "runbook" | "audit";
  source_status: SourceStatus;
  content_markdown: string;
  evidence_refs?: Record<string, unknown>[];
}

export type WikiRefreshScope = "project" | "repository";
export type WikiRefreshPolicy = "manual" | "confirm" | "approval_required";

export interface WikiRefreshRequestInput {
  workspace_binding_id?: string | null;
  repository_id?: string | null;
  scope: WikiRefreshScope;
  reason: string;
  sections?: string[];
  policy?: WikiRefreshPolicy;
}

export interface WikiRefreshRequest {
  id: string;
  project_id: string;
  workspace_binding_id: string | null;
  repository_id?: string | null;
  capability?: string;
  status: string;
  scope?: WikiRefreshScope;
  reason?: string | null;
  sections?: string[];
  policy?: WikiRefreshPolicy | string | null;
  requires_confirmation?: boolean | number;
  created_at: string | null;
  completed_at?: string | null;
  failed_at?: string | null;
  cancelled_at?: string | null;
  payload?: Record<string, unknown>;
  result?: Record<string, unknown>;
}

// ---------- Graph ----------

export type GraphNodeKind =
  | "module"
  | "class"
  | "function"
  | "route"
  | "model"
  | "service";

export interface GraphNode {
  id: string;
  label: string;
  kind: GraphNodeKind;
  repository: string;
  degree: number;
  risk: RiskLevel;
  source: SourceMeta;
}

export interface GraphEdge {
  id: string;
  from: string;
  to: string;
  kind: "calls" | "imports" | "extends" | "routes_to" | "uses";
}

export interface GraphView {
  snapshot_id: string | null;
  run_id: string | null;
  generated_at: string;
  source: SourceMeta;
  stats: { nodes: number; edges: number; modules: number; routes: number };
  nodes: GraphNode[];
  edges: GraphEdge[];
}

// ---------- Artifacts ----------

export type ArtifactKind = "genesis_import" | "delta_sync" | "analysis" | "report";
export type ArtifactState =
  | "uploaded"
  | "validated"
  | "imported"
  | "invalid"
  | "purged";

export interface Artifact {
  id: string;
  name: string;
  kind: ArtifactKind;
  state: ArtifactState;
  project_id: string;
  repository_id: string;
  run_id: string | null;
  size_bytes: number;
  checksum: string;
  created_at: string;
  validated: boolean;
  downloadable: boolean;
  source: SourceMeta;
}

// ---------- Quality Center ----------

export type QualityStatus = "pass" | "fail" | "warning";
export type Severity = "info" | "low" | "medium" | "high" | "critical";

export interface QualityReportFinding {
  id: string;
  severity: Severity;
  type:
    | "route_5xx"
    | "unexpected_status"
    | "missing_config"
    | "missing_parameter_provider"
    | "critical_security_finding"
    | "secret_detected";
  message: string;
  route: string;
  expected: string;
  actual: string;
  evidence: Record<string, unknown>;
}

export interface QualityReport {
  tool: string;
  status: QualityStatus;
  generated_at: string;
  summary: {
    total: number;
    passed: number;
    failed: number;
    warnings: number;
    skipped: number;
  };
  findings: QualityReportFinding[];
}

export interface QualityOverview {
  overall_status: QualityStatus;
  latest_gate: { gate: string; status: QualityStatus; generated_at: string };
  latest_route_smoke: { status: QualityStatus; generated_at: string };
  latest_security: { status: QualityStatus; generated_at: string };
  stale_or_missing: { label: string; reason: string }[];
  counters: { passed: number; failed: number; warnings: number; skipped: number };
}

export interface TruthRegistryEntry {
  id: string;
  feature: string;
  domain_rules: string[];
  required_tests: string[];
  risk: RiskLevel;
  evidence: string;
  /** Whether the listed rules are confirmed or illustrative. */
  marking: "example" | "inferred" | "verified";
  source: SourceMeta;
}

export type RouteClassification =
  | "SAFE_READ"
  | "MUTATING"
  | "DESTRUCTIVE"
  | "AUTH"
  | "UNKNOWN";

export interface RouteInventoryEntry {
  id: string;
  name: string;
  method: "GET" | "POST" | "PATCH" | "PUT" | "DELETE";
  path: string;
  controller_action: string;
  classification: RouteClassification;
  configured: boolean;
  parameter_provider: "configured" | "missing" | "not_required";
  warning?: string;
}

export interface RouteSmokeRow {
  id: string;
  route: string;
  actor: Role | "guest";
  expected_status: number;
  actual_status: number | null;
  result: "pass" | "fail" | "skipped";
  skipped_reason?: string;
  blocking: boolean;
}

export type AuthDecision =
  | "allowed"
  | "denied"
  | "allowed_owner_only"
  | "allowed_same_tenant"
  | "unknown";

export interface AuthMatrixRow {
  resource: string;
  decisions: Record<string, AuthDecision>; // actor -> decision
}

export type GateName = "pull_request" | "nightly" | "release";

export interface QualityGate {
  gate: GateName;
  status: QualityStatus;
  generated_at: string;
  blocking_findings: QualityReportFinding[];
  warnings: QualityReportFinding[];
  human_approvals_required: { id: string; label: string; approved: boolean }[];
}

export type CheckState =
  | "implemented"
  | "configured_disabled"
  | "missing_setup"
  | "warning"
  | "blocking";

export interface SecurityCheck {
  id: string;
  tool: string;
  category: "static" | "dependency" | "dast" | "load" | "e2e" | "accessibility" | "mutation";
  state: CheckState;
  requires_human_approval: boolean;
  destructive: boolean;
  description: string;
  last_run_at: string | null;
}

export interface RoadmapPhase {
  id: string;
  phase: string;
  title: string;
  status: "done" | "in_progress" | "planned";
  items: string[];
}

export interface QualityCurrentState {
  deterministic: boolean;
  description: string;
  current_state: string;
  desired_state: string;
  transition_notes: string[];
}

// ---------- Admin ----------

export interface PluginToken {
  id: string;
  name: string;
  /** Only present once, immediately after creation/rotation. Never stored. */
  plain_token?: string;
  prefix: string;
  scopes: string[];
  created_at: string;
  last_used_at: string | null;
  created_by: string;
  revoked: boolean;
}

export interface PluginDevice {
  id: string;
  hostname: string;
  os: string;
  plugin_version: string;
  registered_at: string;
  last_seen_at: string;
  token_prefix: string;
  status: "active" | "stale" | "revoked";
}

// ---------- System ----------

export interface SystemMetric {
  label: string;
  value: string;
  tone: "neutral" | "good" | "warn" | "bad";
}

export interface SystemStatus {
  runtime: SystemMetric[];
  queue: { name: string; pending: number; processing: number; failed: number }[];
  graph_status: PipelineStatus;
  import_status: PipelineStatus;
  retention: { artifact_retention_days: number; auto_purge_enabled: boolean };
  last_operation: { label: string; status: "ok" | "error" | "pending"; at: string } | null;
  audit_export_available: boolean;
}

export interface BackupSecretRequirement {
  name: string;
  required: boolean;
  present: boolean;
  fingerprint: string | null;
}

export interface BackupReadiness {
  format: string;
  compatibility_version: number;
  can_export: boolean;
  can_restore_dry_run: boolean;
  components: { key: string; label: string; included: boolean; detail: string }[];
  secret_policy: {
    includes_plaintext_secrets: boolean;
    required_secrets: BackupSecretRequirement[];
  };
  last_backup: { path: string; filename: string; size_bytes: number; sha256: string } | null;
  warnings: string[];
}

export interface BackupExport {
  id: string;
  format: string;
  filename: string;
  path: string;
  size_bytes: number;
  sha256: string;
  download_url: string;
  manifest: {
    backup_id: string;
    created_at: string;
    compatibility_version: number;
    counts: { tables: number; rows: number; storage_files: number; storage_bytes: number; task_attachments: number };
    components: Record<string, unknown>;
  };
  restore_requirements: {
    required_secrets: BackupSecretRequirement[];
    policy: Record<string, unknown>;
  };
}

export interface BackupDryRunReport {
  mode: "dry_run";
  valid: boolean;
  can_restore: boolean;
  manifest: {
    backup_id: string | null;
    created_at: string | null;
    compatibility_version: number | null;
    source_host_label: string | null;
    counts: Record<string, unknown>;
  } | null;
  summary: { tables: number; rows: number; storage_files: number; storage_bytes: number; required_secrets: number };
  checks: { key: string; label: string; status: "ok" | "error" | "warn"; detail: string }[];
  blockers: { code: string; severity: "error" | "warn"; message: string }[];
  warnings: string[];
}


// ---------- Hades Admin ----------

export type HadesCapability = "read_files" | "sync_git_tree" | "populate_backend_ast" | "populate_project_wiki";

export interface HadesProjectSummary {
  id: string;
  name: string;
  slug?: string | null;
}

export interface HadesBootstrapToken {
  id: string;
  project_id: string;
  project_name?: string | null;
  token_prefix: string;
  name: string;
  scopes?: string[];
  allowed_capabilities?: HadesCapability[] | null;
  expires_at: string | null;
  revoked_at: string | null;
  last_used_at: string | null;
  created_at?: string | null;
}

export interface HadesWorkspaceBinding {
  id: string;
  project_id: string;
  project_name?: string | null;
  display_path: string;
  status: string;
  agent_label: string | null;
  updated_at?: string | null;
}

export interface HadesAgentJob {
  id: string;
  project_id: string;
  workspace_binding_id: string;
  capability: HadesCapability | string;
  status: string;
  policy: string | null;
  requires_confirmation?: boolean | number;
  created_at: string | null;
  completed_at: string | null;
  failed_at: string | null;
  cancelled_at?: string | null;
}

export interface HadesMemoryProposal {
  id: string;
  project_id: string;
  workspace_binding_id: string;
  action: string;
  intent: string | null;
  summary: string | null;
  status: string;
  reason_code: string | null;
  created_at: string | null;
  decided_at?: string | null;
}

export interface HadesAdminSnapshot {
  projects: HadesProjectSummary[];
  bootstrapTokens: HadesBootstrapToken[];
  workspaces: HadesWorkspaceBinding[];
  jobs: HadesAgentJob[];
  memoryProposals: HadesMemoryProposal[];
}

export interface HadesBootstrapTokenCreateInput {
  project_id: string;
  name: string;
  expires_in_days?: number;
  allowed_capabilities?: HadesCapability[];
  base_url?: string;
  project_name?: string;
}

export interface HadesBootstrapTokenCreateResponse {
  plain_token: string;
  token: HadesBootstrapToken;
  install: {
    posix: string;
    windows: string;
  };
}

export interface HadesJobCreateInput {
  project_id: string;
  workspace_binding_id: string;
  hades_agent_id?: string | null;
  idempotency_key?: string | null;
  capability: HadesCapability | string;
  policy?: string;
  priority?: string;
  payload: Record<string, unknown>;
  requires_confirmation?: boolean;
  deadline_at?: string | null;
  available_at?: string | null;
}

export interface HadesJobCreateResponse {
  job: HadesAgentJob & { payload?: Record<string, unknown> };
}

export interface HadesMemoryProposalReviewInput {
  status: "accepted" | "refused" | "conflicted";
  reason_code?: string | null;
  reason_message?: string | null;
}

export interface HadesMemoryProposalReviewResponse {
  proposal: HadesMemoryProposal;
}

// ---------- Intake Normalization ----------

export type IntakeNormalizationType = "bug" | "task" | "feature" | "question";

export interface IntakeNormalization {
  task_type: IntakeNormalizationType;
  suggested_title: string;
  suggested_description: string;
  clarifying_questions: string[];
  requires_root_cause: boolean;
  confidence: number;
  execution_mode: string;
}

export interface IntakeNormalizeResponse {
  normalization: IntakeNormalization;
}

// ---------- Generic ----------

export interface ApiError {
  message: string;
  code?: string;
}
