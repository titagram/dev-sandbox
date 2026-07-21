import { DevboardApi } from "@/api/devboardApi";
import {
  ARTIFACTS, AUTH_MATRIX, BACKUP_READINESS, buildBoard, buildGate, buildKickstart, buildReports,
  DASHBOARD_GRAPH_EDGES, DASHBOARD_GRAPH_NODES, DASHBOARD_GRAPH_SOURCE,
  GRAPH, PLUGIN_DEVICES,
  PLUGIN_TOKENS, PROJECT_DETAILS, PROJECTS, QUALITY_CURRENT_STATE, QUALITY_OVERVIEW,
  agentWorkItems, memoryEntries, ROADMAP, ROUTE_INVENTORY, ROUTE_SMOKE, runSummaries, RUNS, SECURITY_CHECKS, SYSTEM_STATUS,
  PROJECT_LOGBOOK_ENTRIES, TASKS, TRUTH_REGISTRY, USERS, WIKI, wikiSummaries,
} from "@/api/mockData";
import {
  AgentChatThread, AgentChatThreadSummary, AgentKey, AgentWorkDetailResponse, AgentWorkItem,
  AiAgentProfile, AiAgentProfileInput, AiAgentsSnapshot, AiModelProfile, AiModelProfileCreateInput, AiModelProfileInput, AiModelProvider, AiModelProviderInput, AiModelProviderValidationResult,
  AssistantRun, AssistantSuggestion, AssistantSuggestionResponse, BacklogTriagePayload,
  DashboardGraphDataQueryType, DashboardGraphDataResponse, DashboardGraphDirection, DashboardGraphEdge, DashboardGraphFamily, DashboardGraphNode, DashboardGraphProjection, DashboardGraphQueryRequest, DashboardGraphQueryType, DashboardGraphResponse, DashboardGraphScopeItem, DashboardGraphScopeType, DashboardGraphScopesResponse, DashboardOverview, IntakeNormalizationType, LoginPayload, Project, ProjectDetail, ProjectLifecycleInput,
  HadesCapability, ProjectLogbookEntry, ProjectLogbookNoteInput, ProjectLogbookNoteResponse, ProjectLogbookQuery, ProjectLogbookResponse, ProjectMemoryDomain, ProjectMemoryEntry, ProjectMemoryImportBatch, ProjectMemoryImportInput, ProjectMemoryImportItem, ProjectMemoryQuery, ProjectStatusFilter, ProjectWorkspaceBinding, RepositoryDeclarationInput, Role, SourceStatus, TaskAttachment, TaskClarificationPayload, TaskColumn, TaskDetail, User, WikiEvidence, WikiPageDetail, WikiPageWriteInput, WikiRefreshRequest, WikiRefreshRequestInput,
} from "@/types/devboard";

const SESSION_KEY = "devboard_session_role";
const clone = <T,>(v: T): T => JSON.parse(JSON.stringify(v));
const delay = (ms = 280) => new Promise((r) => setTimeout(r, ms + Math.random() * 180));

const MOCK_HADES_SUPPORTED_CAPABILITIES: HadesCapability[] = [
  "read_files",
  "read_source_slice",
  "project_inspection",
  "sync_git_tree",
  "populate_backend_ast",
  "populate_project_wiki",
];

// Mutable copies so mock mutations persist for the session.
let tokens = clone(PLUGIN_TOKENS);
let devices = clone(PLUGIN_DEVICES);
let system = clone(SYSTEM_STATUS);
let logbookEntries = clone(PROJECT_LOGBOOK_ENTRIES);
let backupReadiness = clone(BACKUP_READINESS);
let aiProviders: AiModelProvider[] = [
  {
    id: "provider-openai",
    provider_key: "openai",
    display_name: "OpenAI",
    provider_type: "openai_compatible",
    base_url: "https://api.openai.com/v1",
    api_key_configured: false,
    api_key_last_four: null,
    api_key_updated_at: null,
    enabled: true,
    metadata: {},
  },
  {
    id: "provider-openrouter",
    provider_key: "openrouter",
    display_name: "OpenRouter",
    provider_type: "openai_compatible",
    base_url: "https://openrouter.ai/api/v1",
    api_key_configured: false,
    api_key_last_four: null,
    api_key_updated_at: null,
    enabled: false,
    metadata: {},
  },
];
let aiModelProfiles: AiModelProfile[] = [
  {
    id: "profile-openai-default",
    profile_key: "openai_default_text",
    display_name: "OpenAI default text",
    provider_key: "openai",
    provider_name: "OpenAI",
    model_name: "gpt-4.1-mini",
    runtime_profile: "text",
    max_context: 128000,
    max_output_tokens: 4096,
    temperature: 0.2,
    timeout_seconds: 60,
    enabled: true,
  },
];
let hadesBootstrapTokens: any[] = [];
let hadesWorkspaces: any[] = [];
let hadesJobs: any[] = [];
let hadesMemoryProposals: any[] = [];
let workspaceBindings: ProjectWorkspaceBinding[] = [
  {
    id: "binding-core-web",
    project_id: "proj-core",
    status: "linked",
    display_path: "~/work/devboard-web",
    workspace_fingerprint: "wf-core-web-4821",
    git_remote_hash: "remote-core-web",
    head_commit: "9c8b7a1",
    agent_label: "dev-mbp",
    external_agent_id: "hades-dev-mbp",
    last_seen_at: new Date(Date.now() - 18 * 60000).toISOString(),
    memory_counts: { entries: 2, proposals: 1, imports: 0 },
  },
  {
    id: "binding-core-api",
    project_id: "proj-core",
    status: "linked",
    display_path: "~/work/devboard-api",
    workspace_fingerprint: "wf-core-api-4821",
    git_remote_hash: "remote-core-api",
    head_commit: "7a6b5c4",
    agent_label: "dev-mbp",
    external_agent_id: "hades-dev-mbp",
    last_seen_at: new Date(Date.now() - 44 * 60000).toISOString(),
    memory_counts: { entries: 4, proposals: 0, imports: 1 },
  },
  {
    id: "binding-payments",
    project_id: "proj-pay",
    status: "linked",
    display_path: "~/work/payments-svc",
    workspace_fingerprint: "wf-payments-218",
    git_remote_hash: "remote-payments",
    head_commit: "aa19c0e",
    agent_label: "billing-box",
    external_agent_id: "hades-billing-box",
    last_seen_at: new Date(Date.now() - 90 * 60000).toISOString(),
    memory_counts: { entries: 3, proposals: 2, imports: 0 },
  },
];
let memoryImports: ProjectMemoryImportBatch[] = [
  {
    id: "import-core-1",
    project_id: "proj-core",
    source_workspace_binding_id: "binding-core-api",
    target_workspace_binding_id: "binding-core-web",
    requested_by_user_id: 1,
    status: "completed",
    review_status: "review_pending",
    mode: "copy_as_proposals",
    dedupe_strategy: "summary_payload_hash",
    conflict_policy: "proposal",
    reason: "Seed frontend workspace with API decisions.",
    filters: { kinds: ["decision", "implementation"], limit: 50 },
    counts: { entries_found: 5, proposals_created: 3, accepted_created: 0, skipped_duplicates: 2, conflicted: 0, cancelled: 0 },
    items: [
      {
        id: "import-core-1-item-1",
        source_local_id: "mem-api-auth-1",
        source_hash: "sha256:mock-import-auth",
        proposal_id: "proposal-core-auth",
        target_memory_entry_id: null,
        status: "proposal_created",
        conflict_reason: null,
        provenance: { source_workspace_binding_id: "binding-core-api", source: "mock_seed" },
      },
      {
        id: "import-core-1-item-2",
        source_local_id: "mem-api-routing-1",
        source_hash: "sha256:mock-import-routing",
        proposal_id: "proposal-core-routing",
        target_memory_entry_id: null,
        status: "proposal_created",
        conflict_reason: null,
        provenance: { source_workspace_binding_id: "binding-core-api", source: "mock_seed" },
      },
      {
        id: "import-core-1-item-3",
        source_local_id: "mem-api-duplicate-1",
        source_hash: "sha256:mock-import-duplicate",
        proposal_id: null,
        target_memory_entry_id: null,
        status: "duplicate_skipped",
        conflict_reason: "Matching memory already exists in the target workspace.",
        provenance: { source_workspace_binding_id: "binding-core-api", source: "mock_seed" },
      },
    ],
    created_at: new Date(Date.now() - 6 * 3600 * 1000).toISOString(),
    updated_at: new Date(Date.now() - 6 * 3600 * 1000 + 8 * 60000).toISOString(),
    completed_at: new Date(Date.now() - 6 * 3600 * 1000 + 8 * 60000).toISOString(),
    cancelled_at: null,
    cancelled_by_user_id: null,
    cancel_reason: null,
  },
];

let aiAgentProfiles: AiAgentProfile[] = [
  {
    id: "agent-socrate",
    agent_key: "socrate_supervisor",
    display_name: "Socrate Supervisor",
    description: "Project-level supervisor that keeps context and delegates specialist work.",
    agent_type: "supervisor",
    delegation_mode: "delegates",
    parent_agent_key: null,
    default_model_profile_id: "profile-openai-default",
    requires_human_approval: true,
    enabled: true,
    allowed_tools: [],
    output_schema: {},
    trigger_events: ["project.review_requested"],
  },
  {
    id: "agent-task-clarifier",
    agent_key: "task_clarifier",
    display_name: "Task Clarifier",
    description: "Checks whether a task is actionable before developers start implementation.",
    agent_type: "specialist",
    delegation_mode: "delegated",
    parent_agent_key: "socrate_supervisor",
    default_model_profile_id: "profile-openai-default",
    requires_human_approval: true,
    enabled: true,
    allowed_tools: ["read_project_summary", "read_task_detail", "search_wiki_revisions"],
    output_schema: {},
    trigger_events: ["task.created", "task.updated"],
  },
  {
    id: "agent-backlog-triage",
    agent_key: "backlog_triage",
    display_name: "Backlog Triage",
    description: "Summarizes project backlog readiness and PM follow-ups.",
    agent_type: "specialist",
    delegation_mode: "delegated",
    parent_agent_key: "socrate_supervisor",
    default_model_profile_id: "profile-openai-default",
    requires_human_approval: false,
    enabled: true,
    allowed_tools: ["read_project_summary", "read_project_tasks"],
    output_schema: {},
    trigger_events: ["project.backlog_triage_requested"],
  },
];
const runs = clone(RUNS);
const tasks = clone(TASKS);
let memory = clone(memoryEntries);
let workItems = clone(agentWorkItems);
let agentChats: AgentChatThread[] = [
  {
    id: "chat-core-local",
    project_id: "proj-core",
    repository_id: "repo-web",
    task_id: "CORE-102",
    created_by_user_id: 3,
    agent_key: "local_agent",
    title: "Local frontend follow-up",
    status: "pending_local_agent",
    latest_agent_work_item_id: "work-core-1",
    latest_assistant_run_id: null,
    last_message_at: new Date(Date.now() - 17 * 60000).toISOString(),
    message_count: 1,
    last_message: {
      id: "chat-msg-core-local-1",
      role: "user",
      content: "Check the React routing before changing frontend navigation.",
      created_at: new Date(Date.now() - 17 * 60000).toISOString(),
    },
    metadata: { source: "mock_seed" },
    created_at: new Date(Date.now() - 17 * 60000).toISOString(),
    updated_at: new Date(Date.now() - 17 * 60000).toISOString(),
    messages: [
      {
        id: "chat-msg-core-local-1",
        thread_id: "chat-core-local",
        role: "user",
        author_user_id: 3,
        assistant_run_id: null,
        agent_work_item_id: "work-core-1",
        content: "Check the React routing before changing frontend navigation.",
        metadata: { source: "mock_seed" },
        created_at: new Date(Date.now() - 17 * 60000).toISOString(),
      },
    ],
  },
];
const assistantSuggestions: Record<string, AssistantSuggestion<any>> = {};
const TASK_COLUMNS: TaskColumn[] = ["backlog", "ready", "in_progress", "blocked", "review", "done"];
const RISK_LEVELS = ["low", "medium", "high", "critical"] as const;

function withProjectLifecycle<T extends Project>(project: T): T {
  return {
    ...project,
    status: project.status ?? "active",
    archived_at: project.archived_at ?? null,
    deleted_at: project.deleted_at ?? null,
    restored_at: project.restored_at ?? null,
  };
}

let projects = clone(PROJECTS).map(withProjectLifecycle);
let projectDetails = Object.keys(PROJECT_DETAILS).reduce((acc, projectId) => {
  acc[projectId] = withProjectLifecycle(clone(PROJECT_DETAILS[projectId]));
  return acc;
}, {} as Record<string, ProjectDetail>);

function currentUser(): User | null {
  const role = (localStorage.getItem(SESSION_KEY) as Role | null) || null;
  if (!role || !USERS[role]) return null;
  return clone(USERS[role]);
}

function requireProject(projectId?: string): void {
  if (projectId && !projectDetails[projectId]) {
    throw { message: "Project not found." };
  }
}

function requireRepositoryForProject(projectId: string, repositoryId?: string | null): string | null {
  if (!repositoryId) return null;
  requireProject(projectId);

  if (!projectDetails[projectId].repositories.some((repository) => repository.id === repositoryId)) {
    throw { message: "Repository not found." };
  }

  return repositoryId;
}

function requireRepositoryListForProject(projectId: string, repositoryIds?: string[]): string[] {
  const ids = listFromInput(repositoryIds);
  ids.forEach((repositoryId) => requireRepositoryForProject(projectId, repositoryId));
  return ids;
}

function requireTaskForProject(projectId: string, taskId?: string | null): string | null {
  if (!taskId) return null;
  requireProject(projectId);

  if (!tasks[taskId] || tasks[taskId].project_id !== projectId) {
    throw { message: "Task not found." };
  }

  return taskId;
}

function requireRunForProject(projectId: string, runId?: string | null): string | null {
  if (!runId) return null;
  requireProject(projectId);

  if (!runs[runId] || runs[runId].project_id !== projectId) {
    throw { message: "Run not found." };
  }

  return runId;
}

function updateProjectOpenTaskCount(projectId: string, delta: number): void {
  if (delta === 0) return;

  projects = projects.map((project) => project.id === projectId
    ? { ...project, open_tasks: Math.max(0, project.open_tasks + delta), updated_at: new Date().toISOString() }
    : project);
  projectDetails = {
    ...projectDetails,
    [projectId]: {
      ...projectDetails[projectId],
      open_tasks: Math.max(0, projectDetails[projectId].open_tasks + delta),
      updated_at: new Date().toISOString(),
    },
  };
}

function slugify(value: string): string {
  return value
    .trim()
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-+|-+$/g, "");
}

function listFromInput(values?: string[]): string[] {
  return (values || []).map((value) => value.trim()).filter(Boolean);
}

function numericCurrentUserId(): number | null {
  const role = (localStorage.getItem(SESSION_KEY) as Role | null) || null;
  const ids: Partial<Record<Role, number>> = { admin: 1, pm: 2, developer: 3, sysadmin: 4 };
  return role ? ids[role] ?? null : null;
}

function projectMemoryDomain(entry: ProjectMemoryEntry): ProjectMemoryDomain {
  if (entry.kind === "agent_note" || entry.source === "server_agent" || entry.source === "local_agent" || entry.agent_key) {
    return "agent_notes";
  }

  return "logbook";
}

function projectMemoryDomains(projectId: string): Record<ProjectMemoryDomain, number> {
  const scopedMemory = memory.filter((entry) => entry.project_id === projectId);

  return {
    logbook: scopedMemory.filter((entry) => projectMemoryDomain(entry) === "logbook").length,
    agent_notes: scopedMemory.filter((entry) => projectMemoryDomain(entry) === "agent_notes").length,
    wiki: wikiSummaries().filter((page) => page.project_id === projectId).length,
  };
}

function memoryMatchesQuery(entry: ProjectMemoryEntry, query?: string): boolean {
  const q = query?.trim().toLowerCase();
  if (!q) return true;
  return JSON.stringify([entry.summary, entry.payload]).toLowerCase().includes(q);
}

function wikiMemoryEntries(projectId: string, query?: string): ProjectMemoryEntry[] {
  const q = query?.trim().toLowerCase();
  return wikiSummaries()
    .filter((page) => page.project_id === projectId)
    .filter((page) => !q || JSON.stringify([page.title, page.category, page.source_status]).toLowerCase().includes(q))
    .map((page) => ({
      id: page.id,
      project_id: page.project_id,
      repository_id: null,
      task_id: null,
      run_id: null,
      author_user_id: null,
      agent_key: null,
      source: "system_event",
      kind: "agent_note",
      domain: "wiki",
      completeness: page.source_status === "needs_verification" || page.source_status === "stale" ? "incomplete" : "complete",
      summary: page.title,
      payload: {
        page_id: page.id,
        page_category: page.category,
        source_status: page.source_status,
      },
      occurred_at: page.updated_at,
      created_at: page.updated_at,
    }));
}

function evidenceFromRefs(refs: Record<string, unknown>[] | undefined, status: SourceStatus): WikiEvidence[] {
  return (refs || []).map((ref, index) => {
    const value = String(ref["ref"] || ref["path"] || ref["id"] || `manual-ref-${index + 1}`);
    return {
      id: `manual-ev-${Date.now()}-${index}`,
      label: String(ref["label"] || ref["description"] || value),
      kind: "manual_note",
      ref: value,
      source: {
        type: "user_manual",
        status,
        origin: "manual dashboard entry",
        generated_at: new Date().toISOString(),
        ref: value,
      },
    };
  });
}

function wikiSource(status: SourceStatus, pageId: string) {
  return {
    type: "user_manual" as const,
    status,
    origin: "manual dashboard entry",
    generated_at: new Date().toISOString(),
    ref: pageId,
  };
}

function currentOwnerFallback() {
  const user = currentUser() || USERS.pm;
  return { name: user.name, color: user.avatar_color };
}

function cardFromTask(t: TaskDetail) {
  const { description, acceptance_criteria, attachments, audit_ids, graph_node_ids, source, assistant, ...card } = t;
  return card;
}

function currentBoard(projectId?: string) {
  requireProject(projectId);
  const board = buildBoard();

  Object.values(tasks).forEach((t) => {
    board.tasks[t.id] = {
      ...(board.tasks[t.id] || cardFromTask(t)),
      column: t.column,
      attachment_count: t.attachment_count,
      image_attachment_count: t.image_attachment_count,
      updated_at: t.updated_at,
    };
  });

  if (projectId) {
    Object.keys(board.tasks).forEach((taskId) => {
      if (board.tasks[taskId].project_id !== projectId) {
        delete board.tasks[taskId];
      }
    });
  }

  board.columns.forEach((c) => {
    c.task_ids = Object.values(board.tasks).filter((t) => t.column === c.id).map((t) => t.id);
  });

  return board;
}

function mockOverview(): DashboardOverview {
  const activeProjects = projects.filter((project) => project.status === "active");
  const taskList = Object.values(tasks);
  const runList = runSummaries();
  const wikiList = wikiSummaries();
  const byState = Object.fromEntries(TASK_COLUMNS.map((column) => [
    column,
    taskList.filter((task) => task.column === column).length,
  ])) as Record<TaskColumn, number>;
  const byRisk = Object.fromEntries(RISK_LEVELS.map((risk) => [
    risk,
    taskList.filter((task) => task.risk === risk).length,
  ])) as DashboardOverview["tasks"]["by_risk"];

  return {
    summary: {
      active_projects: activeProjects.length,
      repositories_awaiting_genesis: activeProjects.filter((project) => project.genesis_status !== "complete").length,
    },
    tasks: {
      total: taskList.length,
      blocked: byState.blocked,
      by_state: byState,
      by_risk: byRisk,
    },
    runs: {
      failed: runList.filter((run) => run.status === "failed").length,
      running: runList.filter((run) => run.status === "queued" || run.status === "running").length,
    },
    wiki: {
      stale_pages: wikiList.filter((page) => page.source_status === "stale" || page.source_status === "conflict_with_code").length,
    },
    agents: {
      online: devices.filter((device) => device.status === "active").length,
      offline: devices.filter((device) => device.status === "stale").length,
    },
    projects: clone(activeProjects),
  };
}

function transitionProject(projectId: string, status: ProjectStatusFilter, _payload?: ProjectLifecycleInput): Project {
  requireProject(projectId);
  const now = new Date().toISOString();
  projects = projects.map((project) => project.id === projectId
    ? {
        ...project,
        status,
        archived_at: status === "archived" ? now : project.archived_at,
        deleted_at: status === "deleted" ? now : project.deleted_at,
        restored_at: status === "active" ? now : project.restored_at,
        updated_at: now,
      }
    : project);
  projectDetails = {
    ...projectDetails,
    [projectId]: {
      ...projectDetails[projectId],
      status,
      archived_at: status === "archived" ? now : projectDetails[projectId].archived_at,
      deleted_at: status === "deleted" ? now : projectDetails[projectId].deleted_at,
      restored_at: status === "active" ? now : projectDetails[projectId].restored_at,
      updated_at: now,
    },
  };

  return clone(projectDetails[projectId]);
}

function aiSnapshot(): AiAgentsSnapshot {
  return {
    providers: clone(aiProviders),
    modelProfiles: clone(aiModelProfiles),
    agentProfiles: clone(aiAgentProfiles),
  };
}

function updateMockAiProvider(providerKey: string, input: AiModelProviderInput): AiModelProvider {
  const now = new Date().toISOString();
  const existing = aiProviders.find((provider) => provider.provider_key === providerKey);
  const next: AiModelProvider = {
    ...(existing || {
      id: `provider-${providerKey}`,
      provider_key: providerKey,
      provider_type: "openai_compatible",
      metadata: {},
      api_key_configured: false,
      api_key_last_four: null,
      api_key_updated_at: null,
    }),
    display_name: input.display_name,
    base_url: input.base_url || null,
    enabled: input.enabled,
  };

  if (input.clear_api_key) {
    next.api_key_configured = false;
    next.api_key_last_four = null;
    next.api_key_updated_at = null;
  } else if (input.api_key && input.api_key.trim()) {
    const key = input.api_key.trim();
    next.api_key_configured = true;
    next.api_key_last_four = key.slice(-4);
    next.api_key_updated_at = now;
  }

  aiProviders = existing
    ? aiProviders.map((provider) => provider.provider_key === providerKey ? next : provider)
    : [...aiProviders, next];
  aiModelProfiles = aiModelProfiles.map((profile) => profile.provider_key === providerKey
    ? { ...profile, provider_name: next.display_name }
    : profile);

  return clone(next);
}

function updateMockAiModelProfile(profileKey: string, input: AiModelProfileInput): AiModelProfile {
  const existing = aiModelProfiles.find((profile) => profile.profile_key === profileKey);
  if (!existing) throw { message: "Model profile not found." };

  const next: AiModelProfile = {
    ...existing,
    display_name: input.display_name,
    model_name: input.model_name,
    runtime_profile: input.runtime_profile,
    max_context: input.max_context,
    max_output_tokens: input.max_output_tokens,
    temperature: input.temperature,
    timeout_seconds: input.timeout_seconds,
    enabled: input.enabled,
  };
  aiModelProfiles = aiModelProfiles.map((profile) => profile.profile_key === profileKey ? next : profile);

  return clone(next);
}

function createMockAiModelProfile(input: AiModelProfileCreateInput): AiModelProfile {
  const provider = aiProviders.find((item) => item.provider_key === input.provider_key);
  if (!provider) throw { message: "Model provider not found." };

  const existing = aiModelProfiles.find((profile) => profile.profile_key === input.profile_key);
  const next: AiModelProfile = {
    ...(existing || {
      id: `profile-${input.profile_key}`,
      profile_key: input.profile_key,
    }),
    display_name: input.display_name,
    provider_key: input.provider_key,
    provider_name: provider.display_name,
    model_name: input.model_name,
    runtime_profile: input.runtime_profile,
    max_context: input.max_context,
    max_output_tokens: input.max_output_tokens,
    temperature: input.temperature,
    timeout_seconds: input.timeout_seconds,
    enabled: input.enabled,
  };

  aiModelProfiles = existing
    ? aiModelProfiles.map((profile) => profile.profile_key === input.profile_key ? next : profile)
    : [...aiModelProfiles, next];

  return clone(next);
}

function deleteMockAiModelProfile(profileKey: string): void {
  const existing = aiModelProfiles.find((profile) => profile.profile_key === profileKey);
  if (!existing) throw { message: "Model profile not found." };
  aiAgentProfiles = aiAgentProfiles.map((agent) => agent.default_model_profile_id === existing.id
    ? { ...agent, default_model_profile_id: null }
    : agent);
  aiModelProfiles = aiModelProfiles.filter((profile) => profile.profile_key !== profileKey);
}

function validateMockAiModelProvider(providerKey: string): AiModelProviderValidationResult {
  const provider = aiProviders.find((item) => item.provider_key === providerKey);
  const checkedAt = new Date().toISOString();
  if (!provider) {
    return {
      status: "unknown",
      checked_at: checkedAt,
      message: "Provider has not been created yet.",
      redacted_error: null,
      models: [],
    };
  }

  if (!provider.enabled || !provider.base_url || !provider.api_key_configured) {
    return {
      status: "invalid",
      checked_at: checkedAt,
      message: "Provider requires enabled status, base URL, and stored key.",
      redacted_error: provider.api_key_configured ? null : "Missing API key.",
      models: [],
    };
  }

  return {
    status: "valid",
    checked_at: checkedAt,
    message: `${provider.display_name} credentials accepted by mock validation.`,
    redacted_error: null,
    models: providerKey === "opencode_go" ? ["opencode-go", "opencode-go/coder"] : [providerKey],
  };
}

function projectWorkspaceBindings(projectId: string): ProjectWorkspaceBinding[] {
  requireProject(projectId);
  return workspaceBindings
    .filter((binding) => binding.project_id === projectId)
    .map((binding) => {
      const entries = memory.filter((entry) => entry.project_id === projectId).length;
      const imports = memoryImports.filter((batch) => batch.project_id === projectId).length;
      return {
        ...binding,
        memory_counts: {
          ...binding.memory_counts,
          entries,
          imports,
        },
      };
    });
}

function requireWorkspaceBinding(projectId: string, bindingId?: string | null): ProjectWorkspaceBinding {
  const bindings = projectWorkspaceBindings(projectId);
  const binding = bindings.find((candidate) => candidate.id === bindingId);
  if (!binding) throw { message: "Workspace binding not found." };
  return binding;
}

function createMockMemoryImport(projectId: string, input: ProjectMemoryImportInput): ProjectMemoryImportBatch {
  requireProject(projectId);
  const source = requireWorkspaceBinding(projectId, input.source_workspace_binding_id);
  const target = requireWorkspaceBinding(projectId, input.target_workspace_binding_id);
  const now = new Date().toISOString();
  const limit = Math.max(1, Math.min(input.filters?.limit ?? 25, 100));
  const entriesFound = Math.min(limit, memory.filter((entry) => entry.project_id === projectId).length + source.memory_counts.entries);
  const skipped = Math.min(2, entriesFound);
  const proposalsCreated = Math.max(0, entriesFound - skipped);
  const items: ProjectMemoryImportItem[] = Array.from({ length: entriesFound }).map((_, index) => {
    const isProposal = index < proposalsCreated;

    return {
      id: `import-item-${Date.now()}-${index}`,
      source_local_id: `source-memory-${index + 1}`,
      source_hash: `sha256:mock-import-${Date.now()}-${index}`,
      proposal_id: isProposal ? `proposal-${Date.now()}-${index}` : null,
      target_memory_entry_id: null,
      status: isProposal ? "proposal_created" : "duplicate_skipped",
      conflict_reason: isProposal ? null : "Matching memory already exists in the target workspace.",
      provenance: {
        source_workspace_binding_id: source.id,
        target_workspace_binding_id: target.id,
        source: "mock_import",
      },
    };
  });
  const batch: ProjectMemoryImportBatch = {
    id: `import-${Date.now()}`,
    project_id: projectId,
    source_workspace_binding_id: source.id,
    target_workspace_binding_id: target.id,
    requested_by_user_id: numericCurrentUserId(),
    status: "completed",
    review_status: proposalsCreated > 0 ? "review_pending" : "no_action_required",
    mode: input.mode,
    dedupe_strategy: input.dedupe_strategy,
    conflict_policy: input.conflict_policy,
    reason: input.reason,
    filters: input.filters ?? {},
    counts: {
      entries_found: entriesFound,
      proposals_created: proposalsCreated,
      accepted_created: 0,
      skipped_duplicates: skipped,
      conflicted: input.conflict_policy === "mark_conflicted" ? 1 : 0,
      cancelled: 0,
    },
    items,
    created_at: now,
    updated_at: now,
    completed_at: now,
    cancelled_at: null,
    cancelled_by_user_id: null,
    cancel_reason: null,
  };

  memoryImports = [batch, ...memoryImports];
  workspaceBindings = workspaceBindings.map((binding) =>
    binding.id === target.id
      ? { ...binding, memory_counts: { ...binding.memory_counts, imports: binding.memory_counts.imports + 1 } }
      : binding,
  );

  return clone(batch);
}

function memoryImportById(projectId: string, batchId: string): ProjectMemoryImportBatch {
  requireProject(projectId);
  const batch = memoryImports.find((candidate) => candidate.project_id === projectId && candidate.id === batchId);
  if (!batch) throw { message: "Memory import not found." };
  return batch;
}

function createMockWikiRefreshRequest(projectId: string, input: WikiRefreshRequestInput): WikiRefreshRequest {
  requireProject(projectId);
  const bindings = projectWorkspaceBindings(projectId);
  const bindingId = input.workspace_binding_id || (bindings.length === 1 ? bindings[0].id : bindings[0]?.id);
  if (!bindingId) throw { message: "Workspace binding is required." };
  requireWorkspaceBinding(projectId, bindingId);

  const now = new Date().toISOString();
  const payload = {
    schema: "devboard.wiki_refresh_request.v1",
    scope: input.scope,
    reason: input.reason,
    sections: input.sections ?? ["overview", "architecture", "runbook", "risks"],
    repository_id: input.repository_id ?? null,
  };
  const job = {
    id: `wiki-refresh-${Date.now()}`,
    project_id: projectId,
    workspace_binding_id: bindingId,
    repository_id: input.repository_id ?? null,
    capability: "populate_project_wiki",
    status: "queued",
    scope: input.scope,
    reason: input.reason,
    sections: input.sections ?? ["overview", "architecture", "runbook", "risks"],
    policy: input.policy ?? "manual",
    requires_confirmation: true,
    created_at: now,
    completed_at: null,
    failed_at: null,
    cancelled_at: null,
    payload,
  };

  hadesJobs = [job, ...hadesJobs];
  return clone(job);
}

function wikiRefreshRequests(projectId: string): WikiRefreshRequest[] {
  requireProject(projectId);
  return clone(hadesJobs)
    .filter((job) => job.project_id === projectId && job.capability === "populate_project_wiki")
    .sort((a, b) => String(b.created_at || "").localeCompare(String(a.created_at || "")));
}

function updateMockAiAgentProfile(agentKey: string, input: AiAgentProfileInput): AiAgentProfile {
  const existing = aiAgentProfiles.find((agent) => agent.agent_key === agentKey);
  if (!existing) throw { message: "Agent profile not found." };

  const next: AiAgentProfile = {
    ...existing,
    default_model_profile_id: input.default_model_profile_id || null,
    enabled: input.enabled,
  };
  aiAgentProfiles = aiAgentProfiles.map((agent) => agent.agent_key === agentKey ? next : agent);

  return clone(next);
}

function assistantRun(projectId: string, agentKey: AgentKey, targetType: string, targetId: string, summary: string): AssistantRun {
  const now = new Date().toISOString();
  return {
    id: `asst-run-${Date.now()}-${Math.random().toString(16).slice(2, 6)}`,
    project_id: projectId,
    agent_key: agentKey,
    target_type: targetType,
    target_id: targetId,
    status: "completed",
    context_hash: `mock-${targetId}`,
    result_summary: summary,
    started_at: now,
    finished_at: now,
  };
}

function rememberSuggestion<TPayload>(suggestion: AssistantSuggestion<TPayload>): AssistantSuggestion<TPayload> {
  assistantSuggestions[suggestion.id] = suggestion as AssistantSuggestion<any>;

  if (suggestion.target_type === "task" && suggestion.target_id && tasks[suggestion.target_id]) {
    tasks[suggestion.target_id].assistant = {
      ...tasks[suggestion.target_id].assistant,
      latest_suggestion: suggestion as AssistantSuggestion<TaskClarificationPayload>,
    };
  }

  if (suggestion.target_type === "project" && suggestion.target_id && projectDetails[suggestion.target_id]) {
    projectDetails[suggestion.target_id].assistant = {
      ...projectDetails[suggestion.target_id].assistant,
      latest_backlog_triage_suggestion: suggestion as AssistantSuggestion<BacklogTriagePayload>,
    };
  }

  return suggestion;
}

function assistantSuggestion(suggestionId: string): AssistantSuggestion<any> {
  const known = assistantSuggestions[suggestionId];
  if (known) return known;

  for (const task of Object.values(tasks)) {
    const suggestion = task.assistant?.latest_suggestion;
    if (suggestion?.id === suggestionId) {
      assistantSuggestions[suggestionId] = suggestion;
      return suggestion;
    }
  }

  for (const project of Object.values(projectDetails)) {
    const suggestion = project.assistant?.latest_backlog_triage_suggestion;
    if (suggestion?.id === suggestionId) {
      assistantSuggestions[suggestionId] = suggestion;
      return suggestion;
    }
  }

  throw { message: "Assistant suggestion not found." };
}

function taskClarificationSuggestion(task: TaskDetail, run: AssistantRun): AssistantSuggestion<TaskClarificationPayload> {
  const now = new Date().toISOString();
  return {
    id: `sugg-task-${Date.now()}-${Math.random().toString(16).slice(2, 6)}`,
    assistant_run_id: run.id,
    project_id: task.project_id,
    target_type: "task",
    target_id: task.id,
    suggestion_type: "task_clarification",
    title: `Clarification for ${task.title}`,
    body_markdown: "Mock Task Clarifier found missing implementation context, test expectations, and acceptance detail.",
    structured_payload: {
      questions: [
        "What exact user-visible behavior should change?",
        "Which repository or module should the developer inspect first?",
        "What edge case would make this task incomplete?",
      ],
      acceptance_criteria: [
        "The task states the expected behavior in testable terms.",
        "Relevant affected areas and non-goals are explicit.",
        "Review and verification steps are named before development starts.",
      ],
      risks: task.risk === "high" || task.risk === "critical"
        ? ["High-risk task: require reviewer approval before merge."]
        : ["Ambiguous scope can create implementation churn."],
      missing_context: [
        "Concrete reproduction or business rule reference.",
        "Expected owner for product sign-off.",
      ],
      confidence: 0.78,
    },
    evidence_refs: [{ type: "task", id: task.id, source_status: task.source_status }],
    confidence: 0.78,
    approval_required: true,
    status: "pending",
    resolved_by_user_id: null,
    resolved_at: null,
    created_at: now,
    updated_at: now,
  };
}

function backlogTriageSuggestion(project: ProjectDetail, run: AssistantRun): AssistantSuggestion<BacklogTriagePayload> {
  const now = new Date().toISOString();
  const projectTasks = Object.values(tasks).filter((task) => task.project_id === project.id);
  const blocked = projectTasks.filter((task) => task.column === "blocked");
  const ready = projectTasks.filter((task) => task.column === "ready");

  return {
    id: `sugg-backlog-${Date.now()}-${Math.random().toString(16).slice(2, 6)}`,
    assistant_run_id: run.id,
    project_id: project.id,
    target_type: "project",
    target_id: project.id,
    suggestion_type: "backlog_triage",
    title: `Backlog triage for ${project.name}`,
    body_markdown: "Mock Backlog Triage grouped tasks by execution readiness and highlighted PM follow-ups.",
    structured_payload: {
      summary: `${projectTasks.length} tasks reviewed. ${blocked.length} blocked, ${ready.length} ready for development.`,
      groups: [
        {
          label: "Ready to pull",
          task_ids: ready.map((task) => task.id),
          reason: "These tasks already sit in the ready column.",
        },
        {
          label: "Needs PM clarification",
          task_ids: blocked.map((task) => task.id),
          reason: "Blocked tasks should be clarified before developers pick them up.",
        },
      ],
      recommendations: [
        {
          title: "Clarify blocked items first",
          body: "Run Task Clarifier on blocked or high-risk cards before sprint planning.",
          task_ids: blocked.map((task) => task.id),
          priority: blocked.length ? "high" : "medium",
        },
        {
          title: "Keep local agent evidence fresh",
          body: "Prioritize repositories with stale graph or wiki status before assigning implementation tasks.",
          task_ids: [],
          priority: "medium",
        },
      ],
      risks: project.risk_level === "critical" || project.risk_level === "high"
        ? ["Project risk is elevated; keep PM approvals explicit."]
        : ["No critical backlog risk detected in mock data."],
      confidence: 0.74,
    },
    evidence_refs: [
      { type: "project", id: project.id, source_status: "verified_from_code" },
      ...projectTasks.slice(0, 8).map((task) => ({ type: "task", id: task.id, source_status: task.source_status })),
    ],
    confidence: 0.74,
    approval_required: false,
    status: "pending",
    resolved_by_user_id: null,
    resolved_at: null,
    created_at: now,
    updated_at: now,
  };
}

function appendClarification(description: string, suggestion: AssistantSuggestion<TaskClarificationPayload>): string {
  const payload = suggestion.structured_payload;
  const questions = (payload.questions || []).map((item) => `- ${item}`).join("\n") || "- No questions.";
  const criteria = (payload.acceptance_criteria || []).map((item) => `- ${item}`).join("\n") || "- No acceptance criteria.";

  return [
    description.trim(),
    "",
    "## Assistant clarification",
    "",
    "Questions:",
    questions,
    "",
    "Acceptance criteria:",
    criteria,
  ].filter((part, index) => index > 0 || Boolean(part)).join("\n");
}

function applyTaskMutation(task: TaskDetail, input: Record<string, any>): TaskDetail {
  const passthroughKeys: (keyof TaskDetail)[] = [
    "linked_run_id",
    "linked_run_status",
    "wiki_page_id",
    "attachment_count",
    "image_attachment_count",
    "source_status",
    "blocked",
    "blocked_reason",
    "attachments",
    "assistant",
    "audit_ids",
    "graph_node_ids",
    "source",
  ];

  passthroughKeys.forEach((key) => {
    if (input[key] !== undefined) {
      (task as Record<string, any>)[key] = input[key];
    }
  });

  if (input.title !== undefined) task.title = input.title;
  if (input.description !== undefined) task.description = input.description ?? "";
  if (input.column !== undefined) task.column = input.column;
  if (input.risk !== undefined) task.risk = input.risk;
  if (input.priority !== undefined) {
    (task as TaskDetail & { priority?: string }).priority = input.priority;
  }
  if (input.acceptance_criteria !== undefined) {
    task.acceptance_criteria = input.acceptance_criteria || [];
  }
  if (input.repository_ids !== undefined) {
    task.repositories = input.repository_ids || [];
  } else if (input.repositories !== undefined) {
    task.repositories = input.repositories || [];
  }
  if (input.owner_user_id !== undefined) {
    if (input.owner_user_id === null) {
      task.owner = "Unassigned";
      task.owner_color = "#94a3b8";
    } else {
      const owner = currentOwnerFallback();
      task.owner = owner.name;
      task.owner_color = owner.color;
    }
  }

  task.updated_at = new Date().toISOString();
  return task;
}

function createMockTask(projectId: string, input: Record<string, any>): TaskDetail {
  requireProject(projectId);
  const project = projectDetails[projectId];
  if (project.status !== "active") throw { message: "Project is not active." };
  const repositoryIds = requireRepositoryListForProject(projectId, input.repository_ids);

  const now = new Date().toISOString();
  const owner = currentOwnerFallback();
  const projectKey = project.key || "TASK";
  const task: TaskDetail = {
    id: `${projectKey}-${Date.now()}`,
    title: input.title,
    column: input.column || "backlog",
    owner: owner.name,
    owner_color: owner.color,
    risk: input.risk || "low",
    project_id: projectId,
    repositories: repositoryIds,
    linked_run_id: null,
    linked_run_status: null,
    wiki_page_id: null,
    attachment_count: 0,
    image_attachment_count: 0,
    source_status: "needs_verification",
    blocked: false,
    updated_at: now,
    description: input.description ?? "",
    acceptance_criteria: input.acceptance_criteria || [],
    attachments: [],
    audit_ids: [],
    graph_node_ids: [],
    source: {
      type: "user_manual",
      status: "needs_verification",
      origin: `manual entry / ${owner.name}`,
      generated_at: now,
    },
  };

  if (input.priority !== undefined) {
    (task as TaskDetail & { priority?: string }).priority = input.priority;
  }

  tasks[task.id] = task;
  updateProjectOpenTaskCount(projectId, task.column === "done" ? 0 : 1);

  return task;
}

function agentDisplayName(agentKey: AgentKey): string {
  const labels: Partial<Record<AgentKey, string>> = {
    socrates: "Socrates",
    platon: "Platon",
    aristoteles: "Aristoteles",
    local_agent: "Local Agent",
  };

  return labels[agentKey] || agentKey.replace(/_/g, " ");
}

function refreshThreadDerivedFields(thread: AgentChatThread, now = new Date().toISOString()): AgentChatThread {
  const last = thread.messages[thread.messages.length - 1] || null;
  thread.message_count = thread.messages.length;
  thread.last_message = last
    ? {
        id: last.id,
        role: last.role,
        content: last.content,
        created_at: last.created_at,
      }
    : null;
  thread.last_message_at = last?.created_at ?? thread.last_message_at;
  thread.updated_at = now;
  return thread;
}

function summarizeThread(thread: AgentChatThread): AgentChatThreadSummary {
  const { messages, ...summary } = refreshThreadDerivedFields(thread);
  return clone(summary);
}

function agentThreads(projectId: string): AgentChatThreadSummary[] {
  requireProject(projectId);
  return agentChats
    .filter((thread) => thread.project_id === projectId && thread.status !== "archived" && !thread.archived_at)
    .sort((a, b) => String(b.last_message_at || b.updated_at).localeCompare(String(a.last_message_at || a.updated_at)))
    .map(summarizeThread);
}

function threadById(projectId: string, threadId: string): AgentChatThread {
  requireProject(projectId);
  const thread = agentChats.find((candidate) => candidate.project_id === projectId && candidate.id === threadId);
  if (!thread) throw { message: "Agent chat not found." };
  return thread;
}

function serverAgentAnswer(agentKey: AgentKey, content: string): string {
  return `${agentDisplayName(agentKey)}: ${content.length > 180 ? content.slice(0, 177) + "..." : content}`;
}

function workItemIsLocked(item: AgentWorkItem): boolean {
  return item.status === "claimed" || item.status === "running" || Boolean(item.claimed_by_device_id || item.claimed_at || item.heartbeat_at);
}

function archiveWorkItemInMemory(item: AgentWorkItem, message?: string): AgentWorkItem {
  if (item.archived_at) return item;
  if (workItemIsLocked(item)) throw { message: "Work item is claimed or running and cannot be archived." };

  const now = new Date().toISOString();
  if (item.status === "queued") {
    item.status = "canceled";
    item.canceled_at = now;
    item.failure_reason = message || "Archived before claim.";
  }

  item.archived_at = now;
  item.archived_by_user_id = numericCurrentUserId();
  item.archive_reason = message || null;
  item.updated_at = now;
  return item;
}

function appendAgentChatTurn(thread: AgentChatThread, content: string, metadata: Record<string, unknown> = {}): AgentChatThread {
  const now = new Date().toISOString();
  const workItemId = `work-chat-${Date.now()}-${Math.random().toString(16).slice(2, 6)}`;
  const userMessageId = `chat-msg-${Date.now()}-${Math.random().toString(16).slice(2, 6)}`;
  const workItem: AgentWorkItem = {
    id: workItemId,
    project_id: thread.project_id,
    repository_id: thread.repository_id,
    task_id: thread.task_id,
    requested_by_user_id: numericCurrentUserId(),
    assigned_agent_key: thread.agent_key,
    status: "queued",
    priority: "normal",
    title: `${agentDisplayName(thread.agent_key)} chat: ${content.length > 80 ? content.slice(0, 77) + "..." : content}`,
    prompt: content,
    payload: {
      schema: "devboard.agent_chat_turn.v1",
      source: "agent_chat",
      agent_chat_thread_id: thread.id,
      agent_chat_message_id: userMessageId,
    },
    requires_memory_entry: true,
    result_memory_entry_id: null,
    claimed_by_device_id: null,
    claimed_at: null,
    heartbeat_at: null,
    completed_at: null,
    failed_at: null,
    canceled_at: null,
    failure_reason: null,
    archived_at: null,
    archived_by_user_id: null,
    archive_reason: null,
    created_at: now,
    updated_at: now,
  };
  workItems = [workItem, ...workItems];

  thread.messages.push({
    id: userMessageId,
    thread_id: thread.id,
    role: "user",
    author_user_id: numericCurrentUserId(),
    assistant_run_id: null,
    agent_work_item_id: workItemId,
    content,
    metadata,
    created_at: now,
  });
  thread.latest_agent_work_item_id = workItemId;
  thread.status = thread.agent_key === "local_agent" ? "pending_local_agent" : "waiting_for_agent";
  refreshThreadDerivedFields(thread, now);

  if (thread.agent_key === "local_agent") {
    return thread;
  }

  const answer = serverAgentAnswer(thread.agent_key, content);
  const memoryId = `mem-agent-chat-${Date.now()}`;
  const runId = `run-agent-chat-${Date.now()}`;
  const completedAt = new Date(Date.now() + 500).toISOString();
  const memoryEntry: ProjectMemoryEntry = {
    id: memoryId,
    project_id: thread.project_id,
    repository_id: thread.repository_id,
    task_id: thread.task_id,
    run_id: null,
    author_user_id: null,
    agent_key: thread.agent_key,
    source: "server_agent",
    kind: "agent_note",
    domain: "agent_notes",
    completeness: "complete",
    summary: answer.slice(0, 240),
    payload: { schema: "devboard.server_agent_answer.v1", agent_work_item_id: workItemId, answer },
    occurred_at: completedAt,
    created_at: completedAt,
  };
  memory = [memoryEntry, ...memory];

  workItem.status = "completed";
  workItem.result_memory_entry_id = memoryId;
  workItem.completed_at = completedAt;
  workItem.updated_at = completedAt;
  thread.status = "active";
  thread.latest_assistant_run_id = runId;
  thread.messages.push({
    id: `chat-msg-${Date.now()}-${Math.random().toString(16).slice(2, 6)}`,
    thread_id: thread.id,
    role: "assistant",
    author_user_id: null,
    assistant_run_id: runId,
    agent_work_item_id: workItemId,
    content: answer,
    metadata: { schema: "devboard.agent_chat_response.v1", memory_entry_id: memoryId },
    created_at: completedAt,
  });

  return refreshThreadDerivedFields(thread, completedAt);
}

function dashboardGraphEnvelope(
  projectId: string,
  request: DashboardGraphQueryRequest,
  queryType: DashboardGraphDataQueryType,
  projection: DashboardGraphProjection,
  values: Partial<DashboardGraphDataResponse> = {},
): DashboardGraphDataResponse {
  const limit = request.limit ?? 50;

  return {
    protocol_version: "v1",
    project_id: projectId,
    query_type: queryType,
    found: false,
    reason: null,
    completeness: projection.quality === "complete" ? "complete" : "partial",
    scope: request.scope_type && request.scope_id
      ? { type: request.scope_type, id: request.scope_id }
      : null,
    projection: clone(projection),
    node: null,
    items: [],
    edges: [],
    returned: 0,
    limit,
    next_cursor: null,
    has_more: false,
    truncated: false,
    source: clone(DASHBOARD_GRAPH_SOURCE),
    ...values,
  };
}

function dashboardGraphScopesEnvelope(
  projectId: string,
  request: DashboardGraphQueryRequest,
  projection: DashboardGraphProjection,
  values: Partial<DashboardGraphScopesResponse> = {},
): DashboardGraphScopesResponse {
  return {
    protocol_version: "v1",
    project_id: projectId,
    query_type: "scopes",
    found: false,
    reason: null,
    scope: null,
    projection: clone(projection),
    node: null,
    items: [],
    edges: [],
    returned: 0,
    limit: request.limit ?? 50,
    next_cursor: null,
    has_more: false,
    truncated: false,
    source: clone(DASHBOARD_GRAPH_SOURCE),
    ...values,
  };
}

function graphError(reason: string, code: "404" | "422"): never {
  throw { message: reason, code };
}

function validGraphHandle(value: unknown): value is string {
  return typeof value === "string" && /^gh1_[A-Za-z0-9_-]{43}$/.test(value);
}

const DASHBOARD_GRAPH_QUERY_TYPES = new Set<string>([
  "scopes", "overview", "search", "detail", "neighborhood", "path", "impact",
]);
const DASHBOARD_GRAPH_SCOPE_TYPES = new Set<string>(["repository", "workspace_binding"]);
const DASHBOARD_GRAPH_DIRECTIONS = new Set<string>(["in", "out", "any"]);
const DASHBOARD_GRAPH_FAMILIES = new Set<string>(["call", "dependency", "route", "test", "table"]);
const DASHBOARD_GRAPH_ALLOWED_FIELDS = new Set<string>([
  "type", "scope_type", "scope_id", "query", "node_handle", "from_handle", "to_handle",
  "direction", "families", "max_depth", "limit", "cursor",
]);

function isGraphRecord(value: unknown): value is Record<string, unknown> {
  return value !== null && typeof value === "object" && !Array.isArray(value);
}

function hasGraphField(request: Record<string, unknown>, field: string): boolean {
  return Object.prototype.hasOwnProperty.call(request, field);
}

function isGraphQueryType(value: unknown): value is DashboardGraphQueryType {
  return typeof value === "string" && DASHBOARD_GRAPH_QUERY_TYPES.has(value);
}

function isGraphScopeType(value: unknown): value is DashboardGraphScopeType {
  return typeof value === "string" && DASHBOARD_GRAPH_SCOPE_TYPES.has(value);
}

function isGraphDirection(value: unknown): value is DashboardGraphDirection {
  return typeof value === "string" && DASHBOARD_GRAPH_DIRECTIONS.has(value);
}

function isGraphFamily(value: unknown): value is DashboardGraphFamily {
  return typeof value === "string" && DASHBOARD_GRAPH_FAMILIES.has(value);
}

function isGraphMaxDepth(value: unknown): value is 1 | 2 | 3 {
  return value === 1 || value === 2 || value === 3;
}

function validateDashboardGraphQuery(request: unknown): DashboardGraphQueryRequest {
  if (!isGraphRecord(request)) {
    graphError("validation_failed", "422");
  }
  if (Object.entries(request).some(([field]) => !DASHBOARD_GRAPH_ALLOWED_FIELDS.has(field))
    || !isGraphQueryType(request.type)) {
    graphError("validation_failed", "422");
  }

  const validated: DashboardGraphQueryRequest = { type: request.type };
  if (hasGraphField(request, "scope_type")) {
    const scopeType = request.scope_type;
    if (scopeType === null) {
      // Laravel's nullable rule accepts an explicit null and treats the scope as omitted.
    } else if (isGraphScopeType(scopeType)) {
      validated.scope_type = scopeType;
    } else {
      graphError("validation_failed", "422");
    }
  }
  if (hasGraphField(request, "scope_id")) {
    const scopeId = request.scope_id;
    if (scopeId === null) {
      // Laravel's nullable rule accepts an explicit null and treats the scope as omitted.
    } else if (typeof scopeId === "string" && scopeId.length <= 191) {
      validated.scope_id = scopeId;
    } else {
      graphError("validation_failed", "422");
    }
  }
  if (hasGraphField(request, "query")) {
    if (typeof request.query !== "string" || request.query.length < 1 || request.query.length > 160) {
      graphError("validation_failed", "422");
    }
    validated.query = request.query;
  }
  for (const field of ["node_handle", "from_handle", "to_handle"] as const) {
    if (!hasGraphField(request, field)) continue;
    const value = request[field];
    if (!validGraphHandle(value)) graphError("invalid_handle", "422");
    validated[field] = value;
  }
  if (hasGraphField(request, "direction")) {
    if (!isGraphDirection(request.direction)) graphError("validation_failed", "422");
    validated.direction = request.direction;
  }
  if (hasGraphField(request, "families")) {
    if (!Array.isArray(request.families) || !request.families.every(isGraphFamily)) {
      graphError("validation_failed", "422");
    }
    validated.families = [...request.families];
  }
  if (hasGraphField(request, "max_depth")) {
    if (!isGraphMaxDepth(request.max_depth)) graphError("validation_failed", "422");
    validated.max_depth = request.max_depth;
  }
  if (hasGraphField(request, "limit")) {
    if (typeof request.limit !== "number" || !Number.isInteger(request.limit)
      || request.limit < 1 || request.limit > 100) {
      graphError("validation_failed", "422");
    }
    validated.limit = request.limit;
  }
  if (hasGraphField(request, "cursor")) {
    const cursor = request.cursor;
    if (cursor === null) {
      validated.cursor = null;
    } else if (typeof cursor === "string" && cursor.length <= 512) {
      validated.cursor = cursor;
    } else {
      graphError("validation_failed", "422");
    }
  }

  if ((validated.scope_type === undefined) !== (validated.scope_id === undefined)) {
    graphError("validation_failed", "422");
  }
  if (validated.type === "search" && validated.query === undefined) {
    graphError("validation_failed", "422");
  }
  if (["detail", "neighborhood", "impact"].includes(validated.type)
    && validated.node_handle === undefined) {
    graphError("invalid_handle", "422");
  }
  if (validated.type === "path"
    && (validated.from_handle === undefined || validated.to_handle === undefined)) {
    graphError("invalid_handle", "422");
  }
  if (validated.type !== "scopes" && validated.type !== "search"
    && validated.limit !== undefined && validated.limit > 50) {
    graphError("validation_failed", "422");
  }
  if (validated.type === "impact" && validated.max_depth !== undefined && validated.max_depth !== 2) {
    graphError("validation_failed", "422");
  }
  if (validated.cursor !== undefined && validated.cursor !== null
    && validated.type !== "scopes" && validated.type !== "search") {
    graphError("validation_failed", "422");
  }

  return validated;
}

interface MockGraphScopeFixture {
  projectId: string;
  scopeType: DashboardGraphScopeType;
  scopeId: string;
  nodeHandles: readonly string[];
  projection: DashboardGraphProjection;
}

const mockGraphHandle = (character: string) => `gh1_${character.repeat(43)}`;
const unavailableGraphProjection = (): DashboardGraphProjection => ({
  status: "unavailable",
  quality: null,
  generated_at: null,
  active_graph_version: null,
  node_count: 0,
  relationship_count: 0,
  unknown_kind_count: 0,
  missing_label_count: 0,
  excluded_node_count: 0,
});
const readyGraphProjection = (
  activeGraphVersion: string,
  quality: string,
  nodeCount: number,
  relationshipCount: number,
): DashboardGraphProjection => ({
  status: "ready",
  quality,
  generated_at: new Date(Date.now() - 12 * 60_000).toISOString(),
  active_graph_version: activeGraphVersion,
  node_count: nodeCount,
  relationship_count: relationshipCount,
  unknown_kind_count: 0,
  missing_label_count: 0,
  excluded_node_count: 0,
});
const MOCK_GRAPH_SCOPE_FIXTURES: readonly MockGraphScopeFixture[] = [
  {
    projectId: "proj-core", scopeType: "repository", scopeId: "repo-api",
    nodeHandles: ["a", "b", "c", "d", "e", "f"].map(mockGraphHandle),
    projection: readyGraphProjection("canonical-proj-core-v7", "complete", 6, 5),
  },
  {
    projectId: "proj-core", scopeType: "workspace_binding", scopeId: "binding-core-api",
    nodeHandles: ["g", "h"].map(mockGraphHandle),
    projection: readyGraphProjection("canonical-binding-core-api-v3", "partial", 2, 1),
  },
  {
    projectId: "proj-pay", scopeType: "repository", scopeId: "repo-billing",
    nodeHandles: ["i", "j"].map(mockGraphHandle),
    projection: readyGraphProjection("canonical-proj-pay-v2", "complete", 2, 1),
  },
];

function graphScopeItem(fixture: MockGraphScopeFixture): DashboardGraphScopeItem {
  const projection = fixture.projection;

  return {
    source_scope_type: fixture.scopeType,
    source_scope_id: fixture.scopeId,
    ...(projection.active_graph_version === null ? {} : {
      active_graph_version: projection.active_graph_version,
    }),
    status: projection.status,
    ...(projection.quality === null ? {} : { quality: projection.quality }),
    node_count: projection.node_count,
    relationship_count: projection.relationship_count,
  };
}

function graphFixturesForProject(projectId: string): readonly MockGraphScopeFixture[] {
  return MOCK_GRAPH_SCOPE_FIXTURES.filter((fixture) => fixture.projectId === projectId);
}

function graphScopeFixture(projectId: string, request: DashboardGraphQueryRequest): MockGraphScopeFixture {
  if (!request.scope_type || !request.scope_id) {
    graphError("scope_required", "422");
  }
  const fixture = graphFixturesForProject(projectId).find((candidate) =>
    candidate.scopeType === request.scope_type && candidate.scopeId === request.scope_id,
  );
  if (!fixture) graphError("scope_not_found", "404");
  return fixture;
}

interface MockGraphCursorContext {
  projectId: string;
  queryType: "scopes" | "search";
  scopeType: string | null;
  scopeId: string | null;
  query: string;
  offset: number;
}

let mockGraphCursorSequence = 0;
const mockGraphCursors = new Map<string, MockGraphCursorContext>();

function normalizeGraphQuery(query: string): string {
  return query.replace(/\s+/gu, " ").trim();
}

function isExactLookingGraphQuery(query: string): boolean {
  const trimmed = query.trim();
  const symbolPattern = /^[A-Za-z_][A-Za-z0-9_]*(?:\\[A-Za-z_][A-Za-z0-9_]*|::[A-Za-z_][A-Za-z0-9_]*)*$/u;

  return trimmed.startsWith("/")
    || trimmed.includes("::")
    || (symbolPattern.test(trimmed) && (trimmed.includes("_") || /[a-z][A-Z]/u.test(trimmed)));
}

function createGraphCursor(context: MockGraphCursorContext): string {
  mockGraphCursorSequence += 1;
  const cursor = `mock-gc1_${mockGraphCursorSequence.toString(36).padStart(8, "0")}`;
  mockGraphCursors.set(cursor, context);
  return cursor;
}

function cursorOffset(
  cursor: string | null | undefined,
  expected: Omit<MockGraphCursorContext, "offset">,
): number {
  if (cursor == null) return 0;
  const context = mockGraphCursors.get(cursor);
  if (!context
    || context.projectId !== expected.projectId
    || context.queryType !== expected.queryType
    || context.scopeType !== expected.scopeType
    || context.scopeId !== expected.scopeId
    || context.query !== expected.query) {
    graphError("invalid_cursor", "422");
  }
  return context.offset;
}

function graphNodes(fixture: MockGraphScopeFixture): DashboardGraphNode[] {
  const handles = new Set(fixture.nodeHandles);
  return DASHBOARD_GRAPH_NODES.filter((node) => handles.has(node.handle));
}

function graphEdges(fixture: MockGraphScopeFixture): DashboardGraphEdge[] {
  const handles = new Set(fixture.nodeHandles);
  return DASHBOARD_GRAPH_EDGES.filter((edge) =>
    handles.has(edge.from_handle) && handles.has(edge.to_handle),
  );
}

function graphNode(fixture: MockGraphScopeFixture, handle: string | undefined): DashboardGraphNode | undefined {
  return graphNodes(fixture).find((node) => node.handle === handle);
}

function graphEdgeTouches(edge: DashboardGraphEdge, handle: string): boolean {
  return edge.from_handle === handle || edge.to_handle === handle;
}

function mockDashboardGraphQuery(projectId: string, request: DashboardGraphQueryRequest): DashboardGraphResponse {
  request = validateDashboardGraphQuery(request);

  if (request.type === "scopes") {
    const cursorContext = {
      projectId,
      queryType: "scopes" as const,
      scopeType: null,
      scopeId: null,
      query: "",
    };
    const offset = cursorOffset(request.cursor, cursorContext);
    const limit = request.limit ?? 50;
    const fixtures = graphFixturesForProject(projectId);
    const projection = unavailableGraphProjection();
    if (fixtures.length === 0) {
      return dashboardGraphScopesEnvelope(projectId, request, projection, {
        found: false,
        reason: "graph_scope_not_found",
      });
    }
    const projectScopes = fixtures.map(graphScopeItem);
    const page = projectScopes.slice(offset, offset + limit);
    const hasMore = offset + page.length < projectScopes.length;
    return dashboardGraphScopesEnvelope(projectId, request, projection, {
      found: true,
      items: clone(page),
      returned: page.length,
      next_cursor: hasMore
        ? createGraphCursor({ ...cursorContext, offset: offset + page.length })
        : null,
      has_more: hasMore,
      truncated: false,
    });
  }

  const queryType = request.type as DashboardGraphDataQueryType;
  if (!request.scope_type || !request.scope_id) {
    const fixtures = graphFixturesForProject(projectId);
    if (fixtures.length === 0) {
      return dashboardGraphEnvelope(
        projectId,
        { ...request, limit: 100 },
        queryType,
        unavailableGraphProjection(),
        { found: false, reason: "graph_projection_not_ready" },
      );
    }
    if (fixtures.length > 1) graphError("scope_required", "422");
    request = {
      ...request,
      scope_type: fixtures[0].scopeType,
      scope_id: fixtures[0].scopeId,
    };
  }

  const fixture = graphScopeFixture(projectId, request);
  const scopeNodes = graphNodes(fixture);
  const scopeEdges = graphEdges(fixture);

  if (request.type === "overview") {
    return dashboardGraphEnvelope(projectId, request, queryType, fixture.projection, { found: true });
  }

  if (request.type === "search") {
    const normalizedQuery = normalizeGraphQuery(request.query ?? "");
    if (normalizedQuery === "") graphError("invalid_query", "422");
    const query = normalizedQuery.toLocaleLowerCase();
    const matches = scopeNodes
      .filter((node) => node.label?.toLocaleLowerCase().includes(query))
      .map((node) => {
        const label = node.label?.toLocaleLowerCase() || "";
        const exactName = label === query;
        const exactRoute = node.kind === "route" && label.replace(/^\w+\s+/u, "") === query;
        const priority = exactName ? 3 : exactRoute ? 2 : 1;
        return {
          ...node,
          score: priority,
          match_type: exactName ? "exact_symbol_name" : exactRoute ? "exact_route_path" : "fuzzy",
          match_reason: exactName ? "Exact symbol name" : exactRoute ? "Exact route path" : "Fuzzy label match",
        };
      })
      .sort((a, b) => b.score - a.score || (a.label || "").localeCompare(b.label || ""));
    const exactMatch = matches.some((node) => node.match_type === "exact_symbol_name" || node.match_type === "exact_route_path");
    if (isExactLookingGraphQuery(normalizedQuery) && !exactMatch) {
      return dashboardGraphEnvelope(projectId, request, queryType, fixture.projection, {
        found: true,
        reason: "exact_match_not_found",
        completeness: fixture.projection.quality === "complete" ? "verified_none" : "partial",
      });
    }
    const cursorContext = {
      projectId,
      queryType: "search" as const,
      scopeType: request.scope_type ?? null,
      scopeId: request.scope_id ?? null,
      query: normalizedQuery,
    };
    const offset = cursorOffset(request.cursor, cursorContext);
    const limit = request.limit ?? 50;
    const items = matches.slice(offset, offset + limit);
    const hasMore = offset + items.length < matches.length;
    return dashboardGraphEnvelope(projectId, request, queryType, fixture.projection, {
      found: true,
      completeness: matches.length === 0 ? (fixture.projection.quality === "complete" ? "verified_none" : "partial") : fixture.projection.quality === "complete" ? "complete" : "partial",
      items: clone(items),
      returned: items.length,
      next_cursor: hasMore
        ? createGraphCursor({ ...cursorContext, offset: offset + items.length })
        : null,
      has_more: hasMore,
      truncated: false,
    });
  }

  if (request.type === "detail") {
    const selected = graphNode(fixture, request.node_handle);
    if (!selected) graphError("node_not_found", "404");
    return dashboardGraphEnvelope(projectId, request, queryType, fixture.projection, {
      found: true,
      node: clone(selected),
    });
  }

  if (request.type === "neighborhood") {
    const selected = graphNode(fixture, request.node_handle);
    if (!selected) graphError("node_not_found", "404");
    const direction = request.direction ?? "any";
    const families = new Set(request.families ?? []);
    const matchingEdges = scopeEdges.filter((edge) => {
      const directed = direction === "in"
        ? edge.to_handle === selected.handle
        : direction === "out"
          ? edge.from_handle === selected.handle
          : graphEdgeTouches(edge, selected.handle);
      return directed && (families.size === 0 || families.has(edge.family));
    });
    const relatedHandles = new Set(matchingEdges.flatMap((edge) => [edge.from_handle, edge.to_handle]));
    relatedHandles.delete(selected.handle);
    const allItems = scopeNodes.filter((node) => relatedHandles.has(node.handle));
    const limit = request.limit ?? 50;
    const items = allItems.slice(0, limit);
    const visible = new Set([selected.handle, ...items.map((node) => node.handle)]);
    const edges = matchingEdges.filter((edge) => visible.has(edge.from_handle) && visible.has(edge.to_handle));
    const truncated = items.length < allItems.length;
    return dashboardGraphEnvelope(projectId, request, queryType, fixture.projection, {
      found: true, node: clone(selected), items: clone(items), edges: clone(edges), returned: items.length,
      truncated,
    });
  }

  if (request.type === "path") {
    const from = graphNode(fixture, request.from_handle);
    const to = graphNode(fixture, request.to_handle);
    if (!from || !to) graphError("node_not_found", "404");
    const edge = scopeEdges.find((candidate) =>
      (candidate.from_handle === from.handle && candidate.to_handle === to.handle)
      || (candidate.from_handle === to.handle && candidate.to_handle === from.handle),
    );
    if (!edge) graphError("path_not_found", "404");
    const limit = request.limit ?? 50;
    const allItems = [from, to];
    const items = allItems.slice(0, limit);
    const visibleHandles = new Set(items.map((node) => node.handle));
    const edges = [edge].filter((candidate) =>
      visibleHandles.has(candidate.from_handle) && visibleHandles.has(candidate.to_handle),
    );
    return dashboardGraphEnvelope(projectId, request, queryType, fixture.projection, {
      found: true,
      items: clone(items),
      edges: clone(edges),
      returned: items.length,
      truncated: items.length < allItems.length,
    });
  }

  const selected = graphNode(fixture, request.node_handle);
  if (!selected) graphError("node_not_found", "404");
  const relatedEdges = scopeEdges.filter((edge) => graphEdgeTouches(edge, selected.handle));
  const impactItems = relatedEdges.map((edge) => {
    const relatedHandle = edge.from_handle === selected.handle ? edge.to_handle : edge.from_handle;
    const node = graphNode(fixture, relatedHandle)!;
    return {
      ...node,
      family: edge.family,
      why: `${edge.family} edge ${edge.edge_type}`,
      distance: 1,
      edge_types: [edge.edge_type],
    };
  });
  const limit = request.limit ?? 50;
  const items = impactItems.slice(0, limit);
  const truncated = items.length < impactItems.length;
  return dashboardGraphEnvelope(projectId, request, queryType, fixture.projection, {
    found: true,
    items: clone(items),
    returned: items.length,
    truncated,
  });
}

export const mockApi: DevboardApi = {
  async login(payload: LoginPayload) {
    await delay();
    const role: Role = payload.role || "admin";
    if (!USERS[role]) throw { message: "Unknown role." };
    localStorage.setItem(SESSION_KEY, role);
    return clone(USERS[role]);
  },
  async logout() {
    await delay(120);
    localStorage.removeItem(SESSION_KEY);
  },
  async me() {
    await delay(120);
    return currentUser();
  },

  async getOverview() {
    await delay();
    return mockOverview();
  },
  async getKanban(projectId) {
    await delay();
    return currentBoard(projectId);
  },
  async createTask(projectId, input) {
    await delay(260);
    const task = createMockTask(projectId, input);
    return clone(task);
  },
  async updateTask(taskId, patch) {
    await delay(220);
    const t = tasks[taskId];
    if (!t) throw { message: "Task not found." };
    const input = { ...(patch as Record<string, any>) };
    const wasOpen = t.column !== "done";
    if (input.repository_ids !== undefined) {
      input.repository_ids = requireRepositoryListForProject(t.project_id, input.repository_ids);
    }
    if (input.repositories !== undefined) {
      input.repositories = requireRepositoryListForProject(t.project_id, input.repositories);
    }
    applyTaskMutation(t, input);
    const isOpen = t.column !== "done";
    updateProjectOpenTaskCount(t.project_id, isOpen === wasOpen ? 0 : isOpen ? 1 : -1);
    return clone(t);
  },
  async getTask(taskId) {
    await delay();
    const t = tasks[taskId];
    if (!t) throw { message: "Task not found." };
    return clone(t);
  },
  async clarifyTask(taskId) {
    await delay(520);
    const t = tasks[taskId];
    if (!t) throw { message: "Task not found." };

    const latest = t.assistant?.latest_suggestion;
    if (latest?.status === "pending") {
      latest.status = "superseded";
      latest.resolved_by_user_id = currentUser()?.id || null;
      latest.resolved_at = new Date().toISOString();
      latest.updated_at = latest.resolved_at;
      assistantSuggestions[latest.id] = latest;
    }

    const run = assistantRun(t.project_id, "task_clarifier", "task", t.id, "Task clarification suggestion created.");
    const suggestion = rememberSuggestion(taskClarificationSuggestion(t, run));

    return { run, suggestion: clone(suggestion) };
  },
  async resolveAssistantSuggestion(suggestionId, status): Promise<AssistantSuggestionResponse<TaskClarificationPayload>> {
    await delay(220);
    const suggestion = assistantSuggestion(suggestionId) as AssistantSuggestion<TaskClarificationPayload>;
    if (suggestion.suggestion_type !== "task_clarification") throw { message: "Only task clarification suggestions can be resolved here." };
    suggestion.status = status;
    suggestion.resolved_by_user_id = currentUser()?.id || null;
    suggestion.resolved_at = new Date().toISOString();
    suggestion.updated_at = suggestion.resolved_at;
    rememberSuggestion(suggestion);
    return { suggestion: clone(suggestion) };
  },
  async applyAssistantSuggestion(suggestionId) {
    await delay(300);
    const suggestion = assistantSuggestion(suggestionId) as AssistantSuggestion<TaskClarificationPayload>;
    if (suggestion.suggestion_type !== "task_clarification") throw { message: "Only task clarification suggestions can be applied." };
    if (suggestion.status !== "accepted") throw { message: "Accept the suggestion before applying it." };
    const taskId = suggestion.target_id;
    const t = taskId ? tasks[taskId] : null;
    if (!t) throw { message: "Task not found." };

    suggestion.status = "applied";
    suggestion.updated_at = new Date().toISOString();
    t.description = appendClarification(t.description || "", suggestion);
    t.updated_at = suggestion.updated_at;
    rememberSuggestion(suggestion);

    return { suggestion: clone(suggestion), task: clone(t) };
  },
  async uploadTaskAttachment(taskId, file) {
    await delay(260);
    const t = tasks[taskId];
    if (!t) throw { message: "Task not found." };

    const kind = file.type.startsWith("image/") ? "image" : "file";
    const url = URL.createObjectURL(file);
    const attachment: TaskAttachment = {
      id: `att-${Date.now()}`,
      task_id: taskId,
      project_id: t.project_id,
      name: file.name || "attachment",
      mime_type: file.type || "application/octet-stream",
      kind,
      status: "available",
      scan_status: "not_scanned",
      size_bytes: file.size,
      uploaded_at: new Date().toISOString(),
      uploaded_by: currentUser()?.name || "You",
      download_url: url,
      preview_url: kind === "image" ? url : null,
    };

    t.attachments = [attachment, ...(t.attachments || [])];
    t.attachment_count = t.attachments.length;
    t.image_attachment_count = t.attachments.filter((item) => item.kind === "image").length;
    t.updated_at = new Date().toISOString();

    return clone(attachment);
  },
  async deleteTaskAttachment(taskId, attachmentId) {
    await delay(180);
    const t = tasks[taskId];
    if (!t) throw { message: "Task not found." };
    if (!(t.attachments || []).some((attachment) => attachment.id === attachmentId)) {
      throw { message: "Attachment not found." };
    }

    t.attachments = (t.attachments || []).filter((attachment) => attachment.id !== attachmentId);
    t.attachment_count = t.attachments.length;
    t.image_attachment_count = t.attachments.filter((attachment) => attachment.kind === "image").length;
    t.updated_at = new Date().toISOString();

    return clone(t);
  },
  async getProjectMemory(projectId, query?: ProjectMemoryQuery) {
    await delay();
    requireProject(projectId);
    const domain = query?.domain === "all" ? undefined : query?.domain;
    const q = query?.query;
    const domains = projectMemoryDomains(projectId);

    if (domain === "wiki") {
      return {
        domain: "wiki",
        query: q?.trim() || null,
        domains,
        entries: clone(wikiMemoryEntries(projectId, q).sort((a, b) => b.occurred_at.localeCompare(a.occurred_at))),
      };
    }

    return {
      domain: domain || "all",
      query: q?.trim() || null,
      domains,
      entries: clone(memory)
        .filter((entry) => entry.project_id === projectId)
        .map((entry) => ({ ...entry, domain: projectMemoryDomain(entry) }))
        .filter((entry) => !domain || projectMemoryDomain(entry) === domain)
        .filter((entry) => memoryMatchesQuery(entry, q))
        .sort((a, b) => b.occurred_at.localeCompare(a.occurred_at) || b.created_at.localeCompare(a.created_at)),
    };
  },
  async createProjectMemory(projectId, input) {
    await delay(220);
    requireProject(projectId);
    if (input.kind === "agent_note") throw { message: "Manual dashboard memory cannot be created as an agent note." };
    const repositoryId = requireRepositoryForProject(projectId, input.repository_id);
    const taskId = requireTaskForProject(projectId, input.task_id);
    const runId = requireRunForProject(projectId, input.run_id);
    const now = new Date().toISOString();
    const entry: ProjectMemoryEntry = {
      id: `mem-${Date.now()}`,
      project_id: projectId,
      repository_id: repositoryId,
      task_id: taskId,
      run_id: runId,
      author_user_id: numericCurrentUserId(),
      agent_key: null,
      source: "user_inserted",
      kind: input.kind,
      completeness: input.completeness ?? "complete",
      summary: input.summary,
      payload: input.payload,
      occurred_at: now,
      created_at: now,
    };

    memory = [entry, ...memory];
    return clone(entry);
  },
  async updateProjectMemory(projectId, memoryId, input) {
    await delay(200);
    requireProject(projectId);
    const index = memory.findIndex((entry) => entry.project_id === projectId && entry.id === memoryId);
    if (index === -1) throw { message: "Memory entry not found." };
    const existing = memory[index];
    if (existing.kind !== "agent_note" && existing.source === "user_inserted" && input.kind === "agent_note") {
      throw { message: "Manual dashboard memory cannot be changed into an agent note." };
    }
    const repositoryId = requireRepositoryForProject(projectId, input.repository_id);
    const taskId = requireTaskForProject(projectId, input.task_id);
    const runId = requireRunForProject(projectId, input.run_id);
    const next: ProjectMemoryEntry = {
      ...existing,
      repository_id: repositoryId,
      task_id: taskId,
      run_id: runId,
      kind: input.kind,
      completeness: input.completeness,
      summary: input.summary,
      payload: input.payload,
      domain: undefined,
    };
    memory = memory.map((entry) => entry.id === memoryId ? next : entry);
    return clone({ ...next, domain: projectMemoryDomain(next) });
  },
  async deleteProjectMemory(projectId, memoryId) {
    await delay(180);
    requireProject(projectId);
    const before = memory.length;
    memory = memory.filter((entry) => !(entry.project_id === projectId && entry.id === memoryId));
    if (memory.length === before) throw { message: "Memory entry not found." };
  },

  async getProjectLogbook(projectId, query?: ProjectLogbookQuery): Promise<ProjectLogbookResponse> {
    await delay();
    requireProject(projectId);
    const matches = logbookEntries
      .filter((entry) => entry.project_id === projectId)
      .filter((entry) => !query?.types?.length || query.types.includes(entry.event_type))
      .filter((entry) => !query?.actor || query.actor === entry.actor.kind)
      .filter((entry) => !query?.severity || query.severity === entry.severity)
      .filter((entry) => !query?.q || [entry.summary, entry.narrative_markdown, entry.actor.label, entry.event_type].join(" ").toLocaleLowerCase().includes(query.q.toLocaleLowerCase()))
      .filter((entry) => !query?.from || (entry.recorded_at || "") >= query.from)
      .filter((entry) => !query?.to || (entry.recorded_at || "") <= query.to)
      .sort((left, right) => (right.recorded_at || "").localeCompare(left.recorded_at || ""));
    const limit = Math.min(Math.max(query?.limit || 20, 1), 50);
    const start = query?.cursor ? Math.max(0, Number(query.cursor)) : 0;
    const items = matches.slice(start, start + limit);
    return clone({ project_id: projectId, items, next_cursor: start + limit < matches.length ? String(start + limit) : null });
  },
  async createProjectLogbookNote(projectId, input: ProjectLogbookNoteInput): Promise<ProjectLogbookNoteResponse> {
    await delay();
    requireProject(projectId);
    const id = `logbook-note-${Date.now()}`;
    const entry: ProjectLogbookEntry = {
      id, project_id: projectId, occurred_at: new Date().toISOString(), recorded_at: new Date().toISOString(),
      actor: { kind: "user", label: currentUser()?.name || "Dashboard user", user_id: currentUser()?.id || null, agent_id: null, device_id: null, role: currentUser()?.role || null, model: null },
      event_type: input.event_type, severity: input.severity, summary: input.summary, narrative_markdown: input.narrative_markdown,
      references: input.references, correlation_id: input.correlation_id, payload: { source: "dashboard" }, supersedes_entry_id: input.supersedes_entry_id,
    };
    logbookEntries.unshift(entry);
    return clone({ entry, replayed: false });
  },
  async getProjectWorkspaceBindings(projectId) {
    await delay();
    return clone(projectWorkspaceBindings(projectId));
  },
  async getProjectMemoryImports(projectId) {
    await delay();
    requireProject(projectId);
    return clone(memoryImports)
      .filter((batch) => batch.project_id === projectId)
      .sort((a, b) => b.created_at.localeCompare(a.created_at));
  },
  async getProjectMemoryImport(projectId, batchId) {
    await delay();
    return clone(memoryImportById(projectId, batchId));
  },
  async createProjectMemoryImport(projectId, input) {
    await delay(260);
    return createMockMemoryImport(projectId, input);
  },
  async cancelProjectMemoryImport(projectId, batchId, message) {
    await delay(220);
    const batch = memoryImportById(projectId, batchId);
    if (batch.status === "cancelled" || batch.review_status === "cancelled") {
      throw { message: "Memory import has already been cancelled." };
    }

    const pendingItems = (batch.items || []).filter((item) => item.status === "proposal_created" && item.proposal_id);
    if (pendingItems.length === 0) {
      throw { message: "Memory import has no pending proposals to cancel." };
    }

    const now = new Date().toISOString();
    batch.items = (batch.items || []).map((item) => pendingItems.some((pending) => pending.id === item.id)
      ? { ...item, status: "proposal_cancelled", conflict_reason: message || "Import proposal cancelled from dashboard." }
      : item);
    batch.status = "cancelled";
    batch.review_status = "cancelled";
    batch.cancelled_at = now;
    batch.cancelled_by_user_id = numericCurrentUserId();
    batch.cancel_reason = message || null;
    batch.counts = { ...batch.counts, cancelled: pendingItems.length };
    batch.updated_at = now;
    return clone(batch);
  },
  async getAgentChats(projectId) {
    await delay();
    return { threads: agentThreads(projectId) };
  },
  async getAgentChat(projectId, threadId) {
    await delay();
    return { thread: clone(refreshThreadDerivedFields(threadById(projectId, threadId))) };
  },
  async createAgentChat(projectId, input) {
    await delay(240);
    requireProject(projectId);
    const repositoryId = requireRepositoryForProject(projectId, input.repository_id);
    const taskId = requireTaskForProject(projectId, input.task_id);
    const now = new Date().toISOString();
    const initialMessage = (input.initial_message || "").trim();
    const thread: AgentChatThread = {
      id: `chat-${Date.now()}-${Math.random().toString(16).slice(2, 6)}`,
      project_id: projectId,
      repository_id: repositoryId,
      task_id: taskId,
      created_by_user_id: numericCurrentUserId(),
      agent_key: input.agent_key,
      title: (input.title || initialMessage || `Chat with ${agentDisplayName(input.agent_key)}`).slice(0, 180),
      status: "active",
      latest_agent_work_item_id: null,
      latest_assistant_run_id: null,
      last_message_at: null,
      message_count: 0,
      last_message: null,
      metadata: input.metadata ?? {},
      created_at: now,
      updated_at: now,
      messages: [],
    };

    agentChats = [thread, ...agentChats];
    if (initialMessage) appendAgentChatTurn(thread, initialMessage, input.metadata ?? {});

    return { thread: clone(refreshThreadDerivedFields(thread)) };
  },
  async sendAgentChatMessage(projectId, threadId, input) {
    await delay(240);
    const thread = threadById(projectId, threadId);
    if (thread.status === "archived") throw { message: "Agent chat thread is archived." };
    appendAgentChatTurn(thread, input.content.trim(), input.metadata ?? {});
    return { thread: clone(refreshThreadDerivedFields(thread)) };
  },
  async archiveAgentChat(projectId, threadId, message) {
    await delay(220);
    const thread = threadById(projectId, threadId);
    if (thread.status === "archived" || thread.archived_at) {
      throw { message: "Agent chat thread is already archived." };
    }

    if (thread.latest_agent_work_item_id) {
      const item = workItems.find((candidate) => candidate.id === thread.latest_agent_work_item_id);
      if (item) archiveWorkItemInMemory(item, message || "Archived from agent chat.");
    }

    const now = new Date().toISOString();
    thread.status = "archived";
    thread.archived_at = now;
    thread.archived_by_user_id = numericCurrentUserId();
    thread.archive_reason = message || null;
    refreshThreadDerivedFields(thread, now);
    return { thread: clone(thread) };
  },
  async getAgentWork(projectId) {
    await delay();
    requireProject(projectId);
    const priorityOrder = { urgent: 0, high: 1, normal: 2, low: 3 };
    return {
      items: clone(workItems)
        .filter((item) => item.project_id === projectId && !item.archived_at)
        .sort((a, b) => priorityOrder[a.priority] - priorityOrder[b.priority] || b.created_at.localeCompare(a.created_at)),
    };
  },
  async getAgentWorkDetail(projectId, workItemId): Promise<AgentWorkDetailResponse> {
    await delay();
    requireProject(projectId);
    const item = workItems.find((candidate) => candidate.project_id === projectId && candidate.id === workItemId);
    if (!item) throw { message: "Agent work item not found." };
    const resultMemory = item.result_memory_entry_id
      ? memory.find((entry) => entry.id === item.result_memory_entry_id) || null
      : null;

    return clone({
      item: {
        ...item,
        result_memory_entry: resultMemory,
        events: [
          {
            id: `${item.id}-queued`,
            event_type: "queued",
            actor_user_id: item.requested_by_user_id,
            actor_device_id: null,
            message: "Work item queued.",
            payload: { priority: item.priority },
            created_at: item.created_at,
          },
          ...(item.completed_at ? [{
            id: `${item.id}-completed`,
            event_type: "completed",
            actor_user_id: null,
            actor_device_id: item.claimed_by_device_id,
            message: "Agent completed the work item.",
            payload: {},
            created_at: item.completed_at,
          }] : []),
        ],
        chat: {
          run_id: item.status === "completed" ? `run-${item.id}` : null,
          agent_key: item.assigned_agent_key,
          messages: [
            {
              id: `${item.id}-prompt`,
              role: "user",
              content: item.prompt,
              metadata: {},
              created_at: item.created_at,
            },
            ...(resultMemory ? [{
              id: `${item.id}-assistant`,
              role: "assistant",
              content: resultMemory.summary,
              metadata: { memory_entry_id: resultMemory.id },
              created_at: resultMemory.created_at,
            }] : []),
          ],
        },
      },
    });
  },
  async createAgentWork(projectId, input) {
    await delay(240);
    requireProject(projectId);
    const repositoryId = requireRepositoryForProject(projectId, input.repository_id);
    const taskId = requireTaskForProject(projectId, input.task_id);
    const now = new Date().toISOString();
    const item: AgentWorkItem = {
      id: `work-${Date.now()}`,
      project_id: projectId,
      repository_id: repositoryId,
      task_id: taskId,
      requested_by_user_id: numericCurrentUserId(),
      assigned_agent_key: input.assigned_agent_key,
      status: "queued",
      priority: input.priority ?? "normal",
      title: input.title,
      prompt: input.prompt,
      payload: input.payload ?? {},
      requires_memory_entry: input.requires_memory_entry ?? true,
      result_memory_entry_id: null,
      claimed_by_device_id: null,
      claimed_at: null,
      heartbeat_at: null,
      completed_at: null,
      failed_at: null,
      canceled_at: null,
      failure_reason: null,
      archived_at: null,
      archived_by_user_id: null,
      archive_reason: null,
      created_at: now,
      updated_at: now,
    };

    workItems = [item, ...workItems];
    return clone(item);
  },
  async cancelAgentWork(workItemId, message) {
    await delay(180);
    const item = workItems.find((candidate) => candidate.id === workItemId);
    if (!item) throw { message: "Agent work item not found." };
    if (item.status !== "queued" || item.claimed_by_device_id || item.heartbeat_at) {
      throw { message: "Work item is no longer cancelable." };
    }

    const now = new Date().toISOString();
    item.status = "canceled";
    item.canceled_at = now;
    item.failure_reason = message || null;
    item.updated_at = now;
    return clone(item);
  },
  async archiveAgentWork(workItemId, message) {
    await delay(180);
    const item = workItems.find((candidate) => candidate.id === workItemId);
    if (!item) throw { message: "Agent work item not found." };
    return clone(archiveWorkItemInMemory(item, message));
  },
  async getProjects(status: ProjectStatusFilter = "active") {
    await delay();
    return clone(projects.filter((project) => project.status === status));
  },
  async getProject(projectId) {
    await delay();
    const p = projectDetails[projectId];
    if (!p) throw { message: "Project not found." };
    return clone(p);
  },
  async triageProjectBacklog(projectId) {
    await delay(600);
    requireProject(projectId);
    const project = projectDetails[projectId];
    if (project.status !== "active") throw { message: "Project is not active." };

    const run = assistantRun(project.id, "backlog_triage", "project", project.id, "Project backlog triage suggestion created.");
    const suggestion = rememberSuggestion(backlogTriageSuggestion(project, run));

    return { run, suggestion: clone(suggestion) };
  },
  async normalizeIntake(projectId, rawText) {
    await delay(400);
    requireProject(projectId);

    const lower = rawText.toLowerCase();
    const isBug = lower.includes("crash") || lower.includes("error") || lower.includes("bug") || lower.includes("fail") || lower.includes("broken") || lower.includes("exception") || lower.includes("diagnos");
    const isQuestion = rawText.trim().endsWith("?");
    const isFeature = lower.includes("add") || lower.includes("feature") || lower.includes("implement") || lower.includes("new");
    const needsRootCause = lower.includes("root cause") || lower.includes("diagnose") || lower.includes("diagnosis");

    let taskType: IntakeNormalizationType;
    if (isBug) taskType = "bug";
    else if (isQuestion) taskType = "question";
    else if (isFeature) taskType = "feature";
    else taskType = "task";

    const lines = rawText.split("\n").filter((l) => l.trim()).slice(0, 3);
    const title = lines[0]?.trim()?.slice(0, 120) || "Normalized task";
    const description = lines.slice(1).join("\n").trim().slice(0, 5000) || rawText.trim().slice(0, 5000);

    const clarifyingQuestions: string[] = [];
    if (!lower.includes("why") && !lower.includes("reason")) {
      clarifyingQuestions.push("What is the expected behavior?");
    }
    if (!lower.includes("step") && !lower.includes("how")) {
      clarifyingQuestions.push("What are the reproduction steps?");
    }
    if (lower.length < 60) {
      clarifyingQuestions.push("Can you provide more context or details?");
    }

    return {
      normalization: {
        task_type: taskType,
        suggested_title: title,
        suggested_description: description,
        clarifying_questions: clarifyingQuestions,
        requires_root_cause: needsRootCause,
        confidence: Math.min(0.95, Math.max(0.2, 0.5 + (lines.length * 0.1))),
        execution_mode: "mock_deterministic",
      },
    };
  },
  async createProject(payload) {
    await delay(300);
    const key = slugify(payload.key || payload.name);
    if (!key) throw { message: "Project key is required." };
    if (projects.some((project) => project.key === key)) throw { message: "Project key is already in use." };

    const now = new Date().toISOString();
    const project = {
      id: `proj-${Date.now()}`,
      key,
      name: payload.name,
      description: payload.description || "",
      owner: currentUser()?.name || "You",
      repository_count: 0,
      open_tasks: 0,
      risk_level: "low" as const,
      wiki_freshness: "complete" as const,
      genesis_status: "not_started" as const,
      delta_status: "not_started" as const,
      graph_status: "not_started" as const,
      updated_at: now,
      status: "active" as const,
      operational_status: {
        source: "mock_project_operational_records",
        graph: { status: "not_indexed" as const, canonical: false, scope_type: null, scope_id: null, quality: null, node_count: 0, relationship_count: 0, reason: "No ready canonical graph projection is indexed yet." },
        workspace: { status: "missing" as const, linked_count: 0, repository_count: 0, reason: "No workspace is linked to this project yet." },
        genesis: { status: "not_started" as const, reason: "Genesis analysis has not completed yet." },
        artifacts: { status: "empty" as const, legacy_count: 0, reason: "No graph artifacts are available yet." },
      },
      archived_at: null,
      deleted_at: null,
      restored_at: null,
    };
    const detail = {
      ...project,
      repositories: [],
      kickstart: { ...buildKickstart("awaiting_repository_declaration"), operational_status: project.operational_status },
      policy: {
        code_write_allowed: true,
        destructive_scans_allowed: false,
        auto_import_on_snapshot: false,
        require_review_above_risk: "medium" as const,
        retention_days: 90,
      },
      recent_run_ids: [],
      latest_artifact_ids: [],
    };

    projects = [...projects, project];
    projectDetails = { ...projectDetails, [project.id]: detail };

    return clone(detail);
  },
  async createProjectRepository(projectId, input: RepositoryDeclarationInput) {
    await delay(260);
    requireProject(projectId);

    const detail = projectDetails[projectId];
    if (detail.status !== "active") throw { message: "Project is not active." };

    const key = slugify(input.key || input.name);
    if (!key) throw { message: "Repository key is required." };
    if (detail.repositories.some((repository) => (repository.key || slugify(repository.name)) === key)) {
      throw { message: "Repository key is already in use for this project." };
    }

    const now = new Date().toISOString();
    const repository = {
      id: `repo-${key}-${Date.now()}`,
      project_id: projectId,
      key,
      name: input.name.trim(),
      default_branch: input.default_branch.trim() || "main",
      git_mode: "local_clone" as const,
      last_local_snapshot: null,
      local_workspace: { status: "missing" as const },
      genesis_status: "not_started" as const,
      delta_status: "not_started" as const,
      graph_status: "not_started" as const,
      wiki_status: "not_started" as const,
      risk_level: "low" as const,
      latest_run_id: null,
      latest_run_status: null,
      protected_paths: listFromInput(input.protected_paths),
      excluded_paths: listFromInput(input.excluded_paths),
      stack_hints: listFromInput(input.stack_hints),
      source: {
        type: "user_manual" as const,
        status: "needs_verification" as const,
        origin: `manual entry / ${currentUser()?.name || "You"}`,
        generated_at: now,
      },
    };
    const repositories = [...detail.repositories, repository];
    const operationalStatus = detail.operational_status
      ? {
          ...detail.operational_status,
          workspace: {
            ...detail.operational_status.workspace,
            repository_count: repositories.length,
            reason: "No workspace is linked to this project yet.",
          },
        }
      : undefined;
    const nextDetail = {
      ...detail,
      repositories,
      repository_count: repositories.length,
      operational_status: operationalStatus,
      kickstart: { ...buildKickstart("awaiting_local_workspace_link"), operational_status: operationalStatus },
      updated_at: now,
    };

    projectDetails = { ...projectDetails, [projectId]: nextDetail };
    projects = projects.map((project) => project.id === projectId
      ? {
          ...project,
          repository_count: repositories.length,
          genesis_status: "not_started" as const,
          delta_status: "not_started" as const,
          graph_status: "not_started" as const,
          operational_status: operationalStatus,
          updated_at: now,
        }
      : project);

    return clone(nextDetail);
  },
  async updateProject(projectId, payload) {
    await delay(260);
    requireProject(projectId);
    const key = slugify(payload.key || payload.name);
    if (!key) throw { message: "Project key is required." };
    if (projects.some((project) => project.id !== projectId && project.key === key)) {
      throw { message: "Project key is already in use." };
    }

    const now = new Date().toISOString();
    projects = projects.map((project) => project.id === projectId
      ? { ...project, key, name: payload.name, description: payload.description || "", updated_at: now }
      : project);
    projectDetails = {
      ...projectDetails,
      [projectId]: {
        ...projectDetails[projectId],
        key,
        name: payload.name,
        description: payload.description || "",
        updated_at: now,
      },
    };

    return clone(projectDetails[projectId]);
  },
  async archiveProject(projectId, payload) {
    await delay(260);
    return transitionProject(projectId, "archived", payload);
  },
  async restoreProject(projectId, payload) {
    await delay(260);
    return transitionProject(projectId, "active", payload);
  },
  async deleteProject(projectId, payload) {
    await delay(260);
    return transitionProject(projectId, "deleted", payload);
  },

  async getRuns(projectId) {
    await delay();
    requireProject(projectId);
    return clone(runSummaries().filter((run) => !projectId || run.project_id === projectId));
  },
  async getRun(runId) {
    await delay();
    const r = runs[runId];
    if (!r) throw { message: "Run not found." };
    return clone(r);
  },
  async retryImport(runId) {
    await delay(600);
    const r = runs[runId];
    if (!r) throw { message: "Run not found." };
    r.status = "running";
    r.finished_at = null;
    r.timeline.push({ id: `e${r.timeline.length + 1}`, ts: new Date().toISOString(), label: "Import retry queued", status: "info" });
    r.audit_events.push({ id: `a${r.audit_events.length + 1}`, ts: new Date().toISOString(), actor: "you", action: "run.retry_import", target: runId, result: "ok" });
    return clone(r);
  },
  async reviewRun(runId) {
    await delay(400);
    const r = runs[runId];
    if (!r) throw { message: "Run not found." };
    r.status = "reviewed";
    r.reviewed_by = currentUser()?.name || "you";
    r.timeline.push({ id: `e${r.timeline.length + 1}`, ts: new Date().toISOString(), label: `Reviewed by ${r.reviewed_by}`, status: "ok" });
    r.audit_events.push({ id: `a${r.audit_events.length + 1}`, ts: new Date().toISOString(), actor: r.reviewed_by, action: "run.review", target: runId, result: "ok" });
    return clone(r);
  },

  async getWiki(projectId) {
    await delay();
    requireProject(projectId);
    return clone(wikiSummaries().filter((page) => !projectId || page.project_id === projectId));
  },
  async getWikiPage(pageId, projectId) {
    await delay();
    requireProject(projectId);
    const w = WIKI[pageId];
    if (!w) throw { message: "Wiki page not found." };
    if (projectId && w.project_id !== projectId) throw { message: "Wiki page not found." };
    return clone(w);
  },
  async createWikiPage(projectId, input: WikiPageWriteInput): Promise<WikiPageDetail> {
    await delay(240);
    requireProject(projectId);
    if (!input.slug) throw { message: "Slug is required." };
    const existing = Object.values(WIKI).find((page) => page.project_id === projectId && page.id === input.slug);
    if (existing) throw { message: "Wiki page already exists." };

    const id = `wiki-${slugify(input.slug)}-${Date.now()}`;
    const sourceStatus = "needs_verification" as const;
    const evidence = evidenceFromRefs(input.evidence_refs, sourceStatus);
    const page: WikiPageDetail = {
      id,
      title: input.title,
      project_id: projectId,
      category: input.page_type,
      source_status: sourceStatus,
      has_evidence: evidence.length > 0,
      updated_at: new Date().toISOString(),
      source: wikiSource(sourceStatus, id),
      body_markdown: input.content_markdown,
      evidence,
      related_run_ids: [],
      related_node_ids: [],
    };

    WIKI[id] = page;
    return clone(page);
  },
  async updateWikiPage(pageId, input: WikiPageWriteInput, projectId?: string): Promise<WikiPageDetail> {
    await delay(240);
    requireProject(projectId);
    const page = WIKI[pageId];
    if (!page) throw { message: "Wiki page not found." };
    if (projectId && page.project_id !== projectId) throw { message: "Wiki page not found." };
    const sourceStatus = "needs_verification" as const;
    const evidence = input.evidence_refs ? evidenceFromRefs(input.evidence_refs, sourceStatus) : page.evidence;
    const next: WikiPageDetail = {
      ...page,
      title: input.title,
      category: input.page_type,
      source_status: sourceStatus,
      has_evidence: evidence.length > 0,
      updated_at: new Date().toISOString(),
      source: wikiSource(sourceStatus, pageId),
      body_markdown: input.content_markdown,
      evidence,
    };
    WIKI[pageId] = next;
    return clone(next);
  },
  async getWikiRefreshRequests(projectId) {
    await delay();
    return wikiRefreshRequests(projectId);
  },
  async createWikiRefreshRequest(projectId, input) {
    await delay(280);
    return createMockWikiRefreshRequest(projectId, input);
  },

  async getGraph(projectId, params) {
    await delay();
    requireProject(projectId);
    const g = clone(GRAPH);
    if (params?.runId) g.run_id = params.runId;
    if (params?.snapshotId) g.snapshot_id = params.snapshotId;
    return g;
  },
  async queryProjectGraph(projectId, request) {
    await delay();
    requireProject(projectId);
    return clone(mockDashboardGraphQuery(projectId, request));
  },

  async getArtifacts(projectId) {
    await delay();
    requireProject(projectId);
    return clone(ARTIFACTS.filter((artifact) => !projectId || artifact.project_id === projectId));
  },
  async downloadArtifact(runId, artifactId) {
    await delay(300);
    const a = ARTIFACTS.find((x) => x.id === artifactId);
    if (!a) throw { message: "Artifact not found." };
    if (!a.downloadable) throw { message: "Artifact is not validated and cannot be downloaded." };
    const blob = new Blob([JSON.stringify({ artifact: a, note: "mock artifact payload" }, null, 2)], { type: "application/json" });
    return { url: URL.createObjectURL(blob), name: a.name };
  },

  async getQualityOverview() { await delay(); return clone(QUALITY_OVERVIEW); },
  async getQualityCurrentState() { await delay(); return clone(QUALITY_CURRENT_STATE); },
  async getQualityReports() { await delay(); return clone(buildReports()); },
  async getRouteInventory() { await delay(); return clone(ROUTE_INVENTORY); },
  async getRouteSmoke() { await delay(); return { rows: clone(ROUTE_SMOKE), matrix: clone(AUTH_MATRIX) }; },
  async getQualityGate(gate) { await delay(); return clone(buildGate(gate)); },
  async getQualityRoadmap() {
    await delay();
    return { phases: clone(ROADMAP), checks: clone(SECURITY_CHECKS), truth: clone(TRUTH_REGISTRY) };
  },
  async runQualityCheck(tool, confirm) {
    await delay(700);
    const report = buildReports().find((r) => r.tool === tool) || buildReports()[0];
    const out = clone(report);
    out.tool = tool;
    out.generated_at = new Date().toISOString();
    if (!confirm) {
      // safe re-run of read-only tool
      return out;
    }
    return out;
  },

  async getPluginTokens() { await delay(); return tokens.map((t) => { const c = clone(t); delete c.plain_token; return c; }); },
  async createPluginToken(name, scopes) {
    await delay(400);
    const id = `tok-${Date.now()}`;
    const prefix = `dvb_${Math.random().toString(16).slice(2, 6)}`;
    const plain = `${prefix}_${Math.random().toString(36).slice(2)}${Math.random().toString(36).slice(2)}`;
    const t = { id, name, prefix, scopes, created_at: new Date().toISOString(), last_used_at: null, created_by: currentUser()?.name || "you", revoked: false, plain_token: plain };
    tokens.push(clone(t) as any);
    delete (tokens[tokens.length - 1] as any).plain_token; // never store the secret
    return t;
  },
  async rotatePluginToken(tokenId) {
    await delay(400);
    const t = tokens.find((x) => x.id === tokenId);
    if (!t) throw { message: "Token not found." };
    t.prefix = `dvb_${Math.random().toString(16).slice(2, 6)}`;
    t.last_used_at = null;
    const plain = `${t.prefix}_${Math.random().toString(36).slice(2)}${Math.random().toString(36).slice(2)}`;
    return { ...clone(t), plain_token: plain };
  },
  async revokePluginToken(tokenId) {
    await delay(300);
    const t = tokens.find((x) => x.id === tokenId);
    if (!t) throw { message: "Token not found." };
    t.revoked = true;
  },
  async getPluginDevices() { await delay(); return clone(devices); },
  async revokeDevice(deviceId) {
    await delay(300);
    const d = devices.find((x) => x.id === deviceId);
    if (!d) throw { message: "Device not found." };
    d.status = "revoked";
  },
  async getAiAgents() {
    await delay();
    return aiSnapshot();
  },
  async updateAiModelProvider(providerKey, input) {
    await delay(320);
    return updateMockAiProvider(providerKey, input);
  },
  async validateAiModelProvider(providerKey) {
    await delay(360);
    return validateMockAiModelProvider(providerKey);
  },
  async createAiModelProfile(input) {
    await delay(320);
    return createMockAiModelProfile(input);
  },
  async updateAiModelProfile(profileKey, input) {
    await delay(320);
    return updateMockAiModelProfile(profileKey, input);
  },
  async deleteAiModelProfile(profileKey) {
    await delay(260);
    deleteMockAiModelProfile(profileKey);
  },
  async getHadesAdmin() {
    await delay();
    return {
      supported_capabilities: clone(MOCK_HADES_SUPPORTED_CAPABILITIES),
      projects: clone(projects.filter((project) => project.status === "active").map((project) => ({ id: project.id, name: project.name, slug: project.key }))),
      bootstrapTokens: clone(hadesBootstrapTokens),
      workspaces: clone([...hadesWorkspaces, ...workspaceBindings].map((workspace) => ({
        ...workspace,
        declared_capabilities: workspace.declared_capabilities ?? [],
        effective_capabilities: workspace.effective_capabilities ?? [],
      }))),
      jobs: clone(hadesJobs),
      memoryProposals: clone(hadesMemoryProposals),
    };
  },
  async createHadesBootstrapToken(input) {
    await delay(240);
    const project = projects.find((candidate) => candidate.id === input.project_id);
    if (!project) throw { message: "Project not found." };
    const now = new Date().toISOString();
    const id = `hades-boot-${Date.now()}`;
    const plain = `hades_bootstrap_${id}|mock-secret-${Math.random().toString(16).slice(2)}`;
    const token = {
      id,
      project_id: project.id,
      project_name: project.name,
      token_prefix: `hades_bootstrap_${id}`,
      name: input.name,
      allowed_capabilities: input.allowed_capabilities === undefined || input.allowed_capabilities === null
        ? clone(MOCK_HADES_SUPPORTED_CAPABILITIES)
        : clone(input.allowed_capabilities),
      expires_at: null,
      revoked_at: null,
      last_used_at: null,
      created_at: now,
    };
    hadesBootstrapTokens = [token, ...hadesBootstrapTokens];
    const base = input.base_url || window.location.origin;
    const projectName = input.project_name ? ` --backend-project-name '${input.project_name.replace(/'/g, "'\\''")}'` : "";
    return {
      plain_token: plain,
      token: clone(token),
      install: {
        posix: `curl -fsSL '${base}/install.sh' | bash -s -- --backend-url '${base}' --backend-project-id ${project.id} --backend-project-token '${plain}'${projectName}`,
        windows: `powershell -NoProfile -ExecutionPolicy Bypass -Command "iwr -UseB ${base}/install.ps1 -OutFile install.ps1; .\\install.ps1 -BackendUrl ${base} -BackendProjectId ${project.id} -BackendProjectToken '${plain}'"`,
      },
    };
  },
  async revokeHadesBootstrapToken(tokenId) {
    await delay(160);
    hadesBootstrapTokens = hadesBootstrapTokens.map((token) => token.id === tokenId ? { ...token, revoked_at: new Date().toISOString() } : token);
  },
  async createHadesJob(input) {
    await delay(220);
    const now = new Date().toISOString();
    const job = {
      id: `hades-job-${Date.now()}`,
      project_id: input.project_id,
      workspace_binding_id: input.workspace_binding_id,
      capability: input.capability,
      status: "queued",
      policy: input.policy ?? "manual_review",
      requires_confirmation: input.requires_confirmation ?? false,
      created_at: now,
      completed_at: null,
      failed_at: null,
      cancelled_at: null,
      payload: input.payload,
    };
    hadesJobs = [job, ...hadesJobs];
    return { job: clone(job) };
  },
  async reviewHadesMemoryProposal(proposalId, input) {
    await delay(180);
    let proposal = hadesMemoryProposals.find((candidate) => candidate.id === proposalId);
    if (!proposal) throw { message: "Memory proposal not found." };
    proposal = { ...proposal, status: input.status, reason_code: input.reason_code ?? null, decided_at: new Date().toISOString() };
    hadesMemoryProposals = hadesMemoryProposals.map((candidate) => candidate.id === proposalId ? proposal : candidate);
    return { proposal: clone(proposal) };
  },
  async updateAiAgentProfile(agentKey, input) {
    await delay(260);
    return updateMockAiAgentProfile(agentKey, input);
  },

  async getSystem() { await delay(); return clone(system); },
  async setArtifactRetention(days, autoPurge) {
    await delay(350);
    system.retention = { artifact_retention_days: days, auto_purge_enabled: autoPurge };
    system.last_operation = { label: `Retention set to ${days}d`, status: "ok", at: new Date().toISOString() };
    return clone(system);
  },
  async runAuditExport(rangeDays) {
    await delay(800);
    system.last_operation = { label: `Audit export (range ${rangeDays}d)`, status: "ok", at: new Date().toISOString() };
    return clone(system);
  },
  async getBackupReadiness() {
    await delay();
    return clone(backupReadiness);
  },
  async exportBackup() {
    await delay(700);
    const id = `mock-backup-${Date.now()}`;
    const createdAt = new Date().toISOString();
    const result = {
      id,
      format: "devboard-backup-v1",
      filename: `${id}.json`,
      path: `devboard/backups/${id}.json`,
      size_bytes: 184320,
      sha256: `sha256:${Math.random().toString(16).slice(2).padEnd(16, "0")}`,
      download_url: `/api/dashboard/system/backups/${id}/download`,
      manifest: {
        backup_id: id,
        created_at: createdAt,
        compatibility_version: 1,
        counts: { tables: 22, rows: 640, storage_files: 18, storage_bytes: 142120, task_attachments: 4 },
        components: { database: { tables: 22, rows: 640 }, storage: { files: 18, bytes: 142120 }, secrets: { included: false } },
      },
      restore_requirements: {
        required_secrets: backupReadiness.secret_policy.required_secrets,
        policy: { plaintext_secrets_included: false },
      },
    };
    backupReadiness.last_backup = { path: result.path, filename: result.filename, size_bytes: result.size_bytes, sha256: result.sha256 };
    system.last_operation = { label: "Backup export", status: "ok", at: createdAt };
    return clone(result);
  },
  async validateBackupBundle(file) {
    await delay(500);
    const valid = file.name.endsWith(".json");
    return {
      mode: "dry_run",
      valid,
      can_restore: valid,
      manifest: valid ? {
        backup_id: "mock-dry-run",
        created_at: new Date().toISOString(),
        compatibility_version: 1,
        source_host_label: "mock-host",
        counts: { tables: 22, rows: 640, storage_files: 18, storage_bytes: 142120 },
      } : null,
      summary: { tables: valid ? 22 : 0, rows: valid ? 640 : 0, storage_files: valid ? 18 : 0, storage_bytes: valid ? 142120 : 0, required_secrets: 3 },
      checks: [
        { key: "format", label: "Bundle format", status: valid ? "ok" : "error", detail: valid ? "devboard-backup-v1" : "Unsupported file" },
      ],
      blockers: valid ? [] : [{ code: "invalid_format", severity: "error", message: "Mock restore accepts .json backup bundles only." }],
      warnings: [],
    } as const;
  },
};
