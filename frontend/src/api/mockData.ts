import {
  Artifact,
  AgentWorkItem,
  AuthMatrixRow,
  BackupReadiness,
  GraphView,
  KanbanBoard,
  LocalWorkspace,
  PluginDevice,
  PluginToken,
  Project,
  ProjectDetail,
  ProjectKickstart,
  ProjectKickstartState,
  ProjectMemoryEntry,
  QualityCurrentState,
  QualityGate,
  QualityOverview,
  QualityReport,
  Repository,
  RoadmapPhase,
  RouteInventoryEntry,
  RouteSmokeRow,
  RunDetail,
  RunSummary,
  SecurityCheck,
  SourceMeta,
  SystemStatus,
  TaskDetail,
  TruthRegistryEntry,
  User,
  WikiPageDetail,
  WikiPageSummary,
} from "@/types/devboard";

const now = Date.now();
const iso = (minsAgo: number) => new Date(now - minsAgo * 60000).toISOString();
const slug = (value: string) =>
  value
    .trim()
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-+|-+$/g, "");

const src = (
  type: SourceMeta["type"],
  status: SourceMeta["status"],
  origin: string,
  minsAgo: number,
  ref?: string,
): SourceMeta => ({ type, status, origin, generated_at: iso(minsAgo), ref });

const KICKSTART_STEPS: ProjectKickstart["steps"] = [
  { key: "project_intake", status: "pending" },
  { key: "repository_declaration", status: "pending" },
  { key: "local_workspace_link", status: "pending" },
  { key: "genesis", status: "pending" },
  { key: "knowledge_review", status: "pending" },
];

export function buildKickstart(state: ProjectKickstartState): ProjectKickstart {
  const currentIndexByState: Record<ProjectKickstartState, number> = {
    draft: 0,
    awaiting_project_intake: 0,
    awaiting_repository_declaration: 1,
    awaiting_local_workspace_link: 2,
    awaiting_genesis: 3,
    analyzing: 3,
    knowledge_review: 4,
    active: KICKSTART_STEPS.length,
  };
  const currentIndex = currentIndexByState[state];

  return {
    state,
    steps: KICKSTART_STEPS.map((step, index) => ({
      key: step.key,
      status: index < currentIndex ? "complete" : index === currentIndex ? "current" : "pending",
    })),
    pairing: { api_base: "/api/plugin/v1" },
  };
}

// ---------------- Users ----------------

export const USERS: Record<string, User> = {
  admin: { id: "u-admin", name: "Avery Cole", email: "admin@devboard.local", role: "admin", avatar_color: "#0ea5a3" },
  pm: { id: "u-pm", name: "Priya Menon", email: "pm@devboard.local", role: "pm", avatar_color: "#7c5cff" },
  developer: { id: "u-dev", name: "Dane Okoro", email: "dev@devboard.local", role: "developer", avatar_color: "#f59e0b" },
  sysadmin: { id: "u-sys", name: "Sam Reyes", email: "sysadmin@devboard.local", role: "sysadmin", avatar_color: "#ef4444" },
};

// ---------------- Repositories ----------------

export const REPOSITORIES: Repository[] = [
  {
    id: "repo-api", project_id: "proj-core", name: "devboard-api", default_branch: "main",
    git_mode: "local_clone", last_local_snapshot: iso(42),
    genesis_status: "complete", delta_status: "complete", graph_status: "complete", wiki_status: "complete",
    risk_level: "medium", latest_run_id: "run-1001", latest_run_status: "needs_review",
    source: src("local_plugin_snapshot", "verified_from_code", "plugin@dev-mbp / snapshot #4821", 42, "snap-4821"),
  },
  {
    id: "repo-web", project_id: "proj-core", name: "devboard-web", default_branch: "main",
    git_mode: "local_worktree", last_local_snapshot: iso(120),
    genesis_status: "complete", delta_status: "in_progress", graph_status: "stale", wiki_status: "stale",
    risk_level: "low", latest_run_id: "run-1002", latest_run_status: "running",
    source: src("local_plugin_diff", "developer_provided", "plugin@dev-mbp / diff #931", 120, "diff-931"),
  },
  {
    id: "repo-worker", project_id: "proj-core", name: "devboard-worker", default_branch: "develop",
    git_mode: "local_bare", last_local_snapshot: iso(1440),
    genesis_status: "complete", delta_status: "stale", graph_status: "complete", wiki_status: "stale",
    risk_level: "high", latest_run_id: "run-1003", latest_run_status: "failed",
    source: src("local_plugin_snapshot", "stale", "plugin@dev-mbp / snapshot #4790", 1440, "snap-4790"),
  },
  {
    id: "repo-billing", project_id: "proj-pay", name: "payments-svc", default_branch: "main",
    git_mode: "local_clone", last_local_snapshot: iso(310),
    genesis_status: "complete", delta_status: "complete", graph_status: "complete", wiki_status: "complete",
    risk_level: "critical", latest_run_id: "run-1004", latest_run_status: "passed",
    source: src("local_analyzer", "verified_from_code", "analyzer@dev-mbp / scan #218", 310, "scan-218"),
  },
  {
    id: "repo-infra", project_id: "proj-pay", name: "infra-iac", default_branch: "main",
    git_mode: "local_clone", last_local_snapshot: null,
    genesis_status: "not_started", delta_status: "not_started", graph_status: "not_started", wiki_status: "not_started",
    risk_level: "medium", latest_run_id: null, latest_run_status: null,
    source: src("user_manual", "needs_verification", "manual entry / Sam Reyes", 5000),
  },
  {
    id: "repo-docs", project_id: "proj-docs", name: "knowledge-base", default_branch: "main",
    git_mode: "local_worktree", last_local_snapshot: iso(880),
    genesis_status: "complete", delta_status: "complete", graph_status: "pending", wiki_status: "complete",
    risk_level: "low", latest_run_id: "run-1006", latest_run_status: "reviewed",
    source: src("local_plugin_snapshot", "verified_from_code", "plugin@docs-box / snapshot #112", 880, "snap-112"),
  },
];

// fix worker wiki_status cleanly
REPOSITORIES[2].wiki_status = "stale";

const LOCAL_WORKSPACES: Record<string, LocalWorkspace> = {
  "repo-api": {
    id: "lw-api",
    status: "linked",
    display_path: "/home/dev/projects/devboard-api",
    current_branch: "main",
    last_head_sha: "a7c9f22",
    dirty_status: "clean",
    last_seen_at: iso(42),
    device_name: "dev-mbp",
    remote_name: "origin",
    remote_url_host: "github.com",
    remote_url_hash: "sha256:mock-api",
    upstream_branch: "origin/main",
    ahead_count: 0,
    behind_count: 0,
    git_state_observed_at: iso(5),
    source_truth: "local_agent_reported",
  },
  "repo-web": {
    id: "lw-web",
    status: "linked",
    display_path: "/home/dev/projects/devboard-web",
    current_branch: "main",
    last_head_sha: "b41e77c",
    dirty_status: "dirty",
    last_seen_at: iso(8),
    device_name: "dev-mbp",
    remote_name: "origin",
    remote_url_host: "github.com",
    remote_url_hash: "sha256:mock-web",
    upstream_branch: "origin/main",
    ahead_count: 2,
    behind_count: 1,
    git_state_observed_at: iso(8),
    source_truth: "local_agent_reported",
  },
  "repo-worker": {
    id: "lw-worker",
    status: "stale",
    display_path: "/home/dev/projects/devboard-worker",
    current_branch: "develop",
    last_head_sha: "09df812",
    dirty_status: "unknown",
    last_seen_at: iso(1440),
    device_name: "dev-mbp",
    remote_name: "origin",
    remote_url_host: "gitlab.com",
    remote_url_hash: "sha256:mock-worker",
    upstream_branch: "origin/develop",
    ahead_count: null,
    behind_count: null,
    git_state_observed_at: iso(1440),
    source_truth: "local_agent_reported",
  },
  "repo-billing": {
    id: "lw-billing",
    status: "linked",
    display_path: "/home/dev/projects/payments-svc",
    current_branch: "main",
    last_head_sha: "f7a2c10",
    dirty_status: "clean",
    last_seen_at: iso(310),
    device_name: "finance-ws",
    remote_name: "origin",
    remote_url_host: "github.com",
    remote_url_hash: "sha256:mock-billing",
    upstream_branch: "origin/main",
    ahead_count: 0,
    behind_count: 0,
    git_state_observed_at: iso(310),
    source_truth: "local_agent_reported",
  },
  "repo-docs": {
    id: "lw-docs",
    status: "linked",
    display_path: "/home/dev/projects/knowledge-base",
    current_branch: "main",
    last_head_sha: "94e2dd8",
    dirty_status: "clean",
    last_seen_at: iso(880),
    device_name: "docs-box",
    remote_name: "origin",
    remote_url_host: "local",
    remote_url_hash: "sha256:mock-docs",
    upstream_branch: "origin/main",
    ahead_count: 0,
    behind_count: 0,
    git_state_observed_at: iso(880),
    source_truth: "local_agent_reported",
  },
};

REPOSITORIES.forEach((repository) => {
  repository.key = slug(repository.name);
  repository.local_workspace = LOCAL_WORKSPACES[repository.id] ?? { status: "missing" };
});

// ---------------- Projects ----------------

export const PROJECTS: Project[] = [
  {
    id: "proj-core", key: "CORE", name: "Hades Agent Core Platform",
    description: "Self-hosted dashboard, API, and worker for AI coding-agent operations.",
    owner: "Priya Menon", repository_count: 3, open_tasks: 9, risk_level: "high",
    wiki_freshness: "stale", genesis_status: "complete", delta_status: "in_progress", graph_status: "stale",
    status: "active", archived_at: null, deleted_at: null, restored_at: null,
    updated_at: iso(42),
  },
  {
    id: "proj-pay", key: "PAY", name: "Payments Service",
    description: "Critical billing + IaC. High-risk, review-gated, destructive scans disabled.",
    owner: "Avery Cole", repository_count: 2, open_tasks: 4, risk_level: "critical",
    wiki_freshness: "complete", genesis_status: "complete", delta_status: "complete", graph_status: "complete",
    status: "active", archived_at: null, deleted_at: null, restored_at: null,
    updated_at: iso(310),
  },
  {
    id: "proj-docs", key: "DOCS", name: "Knowledge Base",
    description: "Wiki evidence + analyzer imports for internal documentation.",
    owner: "Dane Okoro", repository_count: 1, open_tasks: 2, risk_level: "low",
    wiki_freshness: "complete", genesis_status: "complete", delta_status: "complete", graph_status: "pending",
    status: "active", archived_at: null, deleted_at: null, restored_at: null,
    updated_at: iso(880),
  },
];

export const PROJECT_DETAILS: Record<string, ProjectDetail> = {
  "proj-core": {
    ...PROJECTS[0],
    repositories: REPOSITORIES.filter((r) => r.project_id === "proj-core"),
    kickstart: buildKickstart("active"),
    policy: {
      code_write_allowed: true, destructive_scans_allowed: false, auto_import_on_snapshot: true,
      require_review_above_risk: "high", retention_days: 30,
    },
    recent_run_ids: ["run-1001", "run-1002", "run-1003"],
    latest_artifact_ids: ["art-5001", "art-5002", "art-5003"],
  },
  "proj-pay": {
    ...PROJECTS[1],
    repositories: REPOSITORIES.filter((r) => r.project_id === "proj-pay"),
    kickstart: buildKickstart("awaiting_local_workspace_link"),
    policy: {
      code_write_allowed: false, destructive_scans_allowed: false, auto_import_on_snapshot: false,
      require_review_above_risk: "medium", retention_days: 90,
    },
    recent_run_ids: ["run-1004"],
    latest_artifact_ids: ["art-5004"],
  },
  "proj-docs": {
    ...PROJECTS[2],
    repositories: REPOSITORIES.filter((r) => r.project_id === "proj-docs"),
    kickstart: buildKickstart("active"),
    policy: {
      code_write_allowed: true, destructive_scans_allowed: false, auto_import_on_snapshot: true,
      require_review_above_risk: "high", retention_days: 14,
    },
    recent_run_ids: ["run-1006"],
    latest_artifact_ids: ["art-5006"],
  },
};

// ---------------- Kanban ----------------

const T = (
  id: string, title: string, column: TaskDetailColumn, owner: keyof typeof USERS,
  risk: TaskDetail["risk"], repos: string[], runId: string | null, runStatus: any,
  wikiId: string | null, sourceStatus: TaskDetail["source_status"], blocked: boolean, reason?: string,
): TaskDetail => ({
  id, title, column, owner: USERS[owner].name, owner_color: USERS[owner].avatar_color, risk,
  project_id: repos.includes("repo-billing") || repos.includes("repo-infra") ? "proj-pay" : repos.includes("repo-docs") ? "proj-docs" : "proj-core",
  repositories: repos, linked_run_id: runId, linked_run_status: runStatus, wiki_page_id: wikiId,
  attachment_count: 0, image_attachment_count: 0,
  source_status: sourceStatus, blocked, blocked_reason: reason, updated_at: iso(Math.floor(Math.random() * 800) + 10),
  description: "Imported task context derived from the latest local plugin snapshot. Verify against repository state before code-write actions.",
  acceptance_criteria: [
    "Behaviour matches verified domain rule in Truth Registry.",
    "Linked run passes safety checks with no blocking findings.",
    "Affected wiki page evidence is refreshed from code.",
  ],
  attachments: [],
  audit_ids: ["aud-1", "aud-2"],
  graph_node_ids: ["n-route-runs", "n-svc-import"],
  source: src("local_plugin_snapshot", sourceStatus as any, "plugin@dev-mbp / snapshot #4821", 60, "snap-4821"),
});
type TaskDetailColumn = TaskDetail["column"];

export const TASKS: Record<string, TaskDetail> = {};
[
  T("CORE-101", "Genesis import for devboard-api", "review", "developer", "medium", ["repo-api"], "run-1001", "needs_review", "wiki-genesis", "verified_from_code", false),
  T("CORE-102", "Delta sync drift on devboard-web", "in_progress", "developer", "low", ["repo-web"], "run-1002", "running", "wiki-delta", "developer_provided", false),
  T("CORE-103", "Worker import failing on develop", "blocked", "developer", "high", ["repo-worker"], "run-1003", "failed", "wiki-worker", "conflict_with_code", true, "Local snapshot conflicts with analyzer graph output."),
  T("CORE-104", "Refresh stale graph for web", "ready", "pm", "low", ["repo-web"], null, null, "wiki-graph", "stale", false),
  T("CORE-105", "Document run review policy", "backlog", "pm", "low", ["repo-api"], null, null, "wiki-policy", "needs_verification", false),
  T("CORE-106", "Verify route inventory coverage", "in_progress", "admin", "medium", ["repo-api"], null, null, null, "ai_generated", false),
  T("CORE-107", "Audit export retention review", "backlog", "sysadmin", "medium", ["repo-api"], null, null, null, "developer_provided", false),
  T("CORE-108", "Reconcile wiki evidence gaps", "ready", "pm", "low", ["repo-api", "repo-web"], null, null, "wiki-genesis", "needs_verification", false),
  T("CORE-109", "Snapshot scheduler tuning", "done", "developer", "low", ["repo-worker"], "run-1003", "failed", null, "verified_from_code", false),
  T("PAY-201", "Genesis import for payments-svc", "done", "admin", "critical", ["repo-billing"], "run-1004", "passed", "wiki-pay", "verified_from_code", false),
  T("PAY-202", "Register infra-iac repository", "backlog", "sysadmin", "medium", ["repo-infra"], null, null, null, "needs_verification", false),
  T("PAY-203", "Confirm destructive scans disabled", "review", "admin", "high", ["repo-billing"], "run-1004", "passed", "wiki-pay", "verified_from_code", false),
  T("PAY-204", "Billing wiki conflict review", "blocked", "pm", "high", ["repo-billing"], null, null, "wiki-pay", "conflict_with_code", true, "Wiki rule contradicts imported code graph."),
  T("DOCS-301", "Analyzer scan for knowledge-base", "in_progress", "developer", "low", ["repo-docs"], "run-1006", "reviewed", null, "verified_from_code", false),
  T("DOCS-302", "Backfill graph for docs", "ready", "pm", "low", ["repo-docs"], null, null, null, "stale", false),
].forEach((t) => { TASKS[t.id] = t; });

TASKS["CORE-101"].attachments = [
  {
    id: "att-core-101-screen",
    task_id: "CORE-101",
    project_id: "proj-core",
    name: "review-screen.png",
    mime_type: "image/png",
    kind: "image",
    status: "available",
    scan_status: "not_scanned",
    size_bytes: 684,
    uploaded_at: iso(36),
    uploaded_by: USERS.pm.name,
    download_url: "data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==",
    preview_url: "data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==",
  },
];
TASKS["CORE-101"].attachment_count = TASKS["CORE-101"].attachments.length;
TASKS["CORE-101"].image_attachment_count = TASKS["CORE-101"].attachments.filter((a) => a.kind === "image").length;

// ---------------- Project Memory & Agent Work ----------------

export const memoryEntries: ProjectMemoryEntry[] = [
  {
    id: "mem-core-1",
    project_id: "proj-core",
    repository_id: "repo-web",
    task_id: "CORE-102",
    run_id: "run-1002",
    author_user_id: null,
    agent_key: "local_agent",
    source: "local_agent",
    kind: "implementation",
    completeness: "complete",
    summary: "Captured delta sync drift context before frontend task work.",
    payload: {
      why: "The local agent found the web repository graph was stale while the delta sync was still running.",
      changed: [
        {
          path: "src/api/httpApi.ts",
          symbols: ["httpApi.getKanban"],
          change: "Confirmed project-scoped dashboard calls stay out of plugin routes.",
        },
      ],
      tests: ["npm run build"],
      skipped_checks: [],
      risks: ["Task assignment should wait for a fresh local snapshot if repository drift continues."],
    },
    occurred_at: iso(24),
    created_at: iso(24),
  },
  {
    id: "mem-core-2",
    project_id: "proj-core",
    repository_id: "repo-api",
    task_id: "CORE-108",
    run_id: null,
    author_user_id: 1,
    agent_key: null,
    source: "dashboard_user",
    kind: "decision",
    completeness: "complete",
    summary: "Prioritize wiki evidence gaps after active API work is stable.",
    payload: {
      decision: "Keep current implementation tasks moving, then reconcile stale wiki pages against verified route inventory.",
      follow_up: "Run backlog triage after the next graph refresh completes.",
    },
    occurred_at: iso(180),
    created_at: iso(180),
  },
];

export const agentWorkItems: AgentWorkItem[] = [
  {
    id: "work-core-1",
    project_id: "proj-core",
    repository_id: "repo-web",
    task_id: "CORE-102",
    requested_by_user_id: 1,
    assigned_agent_key: "local_agent",
    status: "queued",
    priority: "high",
    title: "Preflight shared memory before coding",
    prompt: "Fetch recent project memory, check for repository drift, and report conflicts before changing frontend files.",
    payload: {
      request: "Inspect recent implementation memory for CORE-102 and verify no active work item touches the same repository.",
      expected_output: "Short preflight summary with risks and recommended next action.",
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
    created_at: iso(18),
    updated_at: iso(18),
  },
];

const COLUMN_DEFS: { id: TaskDetailColumn; title: string }[] = [
  { id: "backlog", title: "Backlog" },
  { id: "ready", title: "Ready" },
  { id: "in_progress", title: "In Progress" },
  { id: "blocked", title: "Blocked" },
  { id: "review", title: "Review" },
  { id: "done", title: "Done" },
];

export function buildBoard(): KanbanBoard {
  const tasks: KanbanBoard["tasks"] = {};
  Object.values(TASKS).forEach((t) => {
    const { description, acceptance_criteria, attachments, audit_ids, graph_node_ids, source, ...card } = t;
    tasks[t.id] = card;
  });
  const columns = COLUMN_DEFS.map((c) => ({
    id: c.id, title: c.title,
    task_ids: Object.values(TASKS).filter((t) => t.column === c.id).map((t) => t.id),
  }));
  return { columns, tasks };
}

// ---------------- Runs ----------------

export const RUNS: Record<string, RunDetail> = {
  "run-1001": {
    id: "run-1001", project_id: "proj-core", repository_id: "repo-api", repository_name: "devboard-api",
    type: "genesis_import", status: "needs_review", risk_level: "medium",
    started_at: iso(58), finished_at: iso(42), duration_ms: 960000, task_id: "CORE-101",
    source: src("local_plugin_snapshot", "verified_from_code", "plugin@dev-mbp / snapshot #4821", 42, "snap-4821"),
    reviewed_by: null,
    local_source_labels: [
      { label: "Local snapshot", value: "snapshot #4821 (not remote Git truth)", source: src("local_plugin_snapshot", "verified_from_code", "plugin@dev-mbp", 42, "snap-4821") },
      { label: "Working tree", value: "clean @ main", source: src("local_analyzer", "verified_from_code", "analyzer@dev-mbp", 42) },
    ],
    timeline: [
      { id: "e1", ts: iso(58), label: "Run queued", status: "info" },
      { id: "e2", ts: iso(57), label: "Local snapshot acquired", status: "ok", detail: "snapshot #4821" },
      { id: "e3", ts: iso(54), label: "Analyzer pass complete", status: "ok", detail: "1,204 nodes" },
      { id: "e4", ts: iso(48), label: "Safety checks", status: "warn", detail: "1 warning" },
      { id: "e5", ts: iso(42), label: "Awaiting human review", status: "warn" },
    ],
    metrics: [
      { label: "Nodes", value: "1,204", tone: "neutral" },
      { label: "Edges", value: "3,981", tone: "neutral" },
      { label: "Tests", value: "182 / 184", tone: "warn", delta: "2 skipped" },
      { label: "Coverage", value: "78%", tone: "good" },
    ],
    risk_triggers: [
      { id: "rt1", label: "Risk ≥ medium", level: "medium", reason: "Touches auth middleware module." },
    ],
    safety_results: [
      { id: "s1", name: "No code-write performed", status: "pass", detail: "Read-only import." },
      { id: "s2", name: "Secret scan", status: "pass", detail: "No secrets detected." },
      { id: "s3", name: "Snapshot vs graph consistency", status: "warning", detail: "2 nodes drifted from prior graph." },
    ],
    artifact_ids: ["art-5001"],
    test_output: "PASS  tests/Feature/RunImportTest.php\nPASS  tests/Unit/SnapshotParserTest.php\nSKIP  tests/Feature/GraphHeavyTest.php (tagged @slow)\n\nTests: 182 passed, 2 skipped (184 total)\nDuration: 14.2s",
    graph_status: "complete", wiki_status: "complete", wiki_page_id: "wiki-genesis",
    audit_events: [
      { id: "a1", ts: iso(58), actor: "plugin@dev-mbp", action: "run.create", target: "run-1001", result: "ok" },
      { id: "a2", ts: iso(42), actor: "system", action: "run.flag_review", target: "run-1001", result: "ok" },
    ],
  },
  "run-1002": {
    id: "run-1002", project_id: "proj-core", repository_id: "repo-web", repository_name: "devboard-web",
    type: "delta_sync", status: "running", risk_level: "low",
    started_at: iso(8), finished_at: null, duration_ms: null, task_id: "CORE-102",
    source: src("local_plugin_diff", "developer_provided", "plugin@dev-mbp / diff #931", 8, "diff-931"),
    local_source_labels: [
      { label: "Local diff", value: "diff #931 vs snapshot #4820", source: src("local_plugin_diff", "developer_provided", "plugin@dev-mbp", 8, "diff-931") },
    ],
    timeline: [
      { id: "e1", ts: iso(8), label: "Run queued", status: "info" },
      { id: "e2", ts: iso(7), label: "Delta diff computed", status: "ok", detail: "12 files changed" },
      { id: "e3", ts: iso(2), label: "Applying delta to graph", status: "info" },
    ],
    metrics: [
      { label: "Files changed", value: "12", tone: "neutral" },
      { label: "Nodes touched", value: "34", tone: "neutral" },
    ],
    risk_triggers: [],
    safety_results: [
      { id: "s1", name: "No code-write performed", status: "pass", detail: "Read-only import." },
    ],
    artifact_ids: ["art-5002"],
    test_output: "Run in progress…",
    graph_status: "in_progress", wiki_status: "stale", wiki_page_id: "wiki-delta",
    audit_events: [{ id: "a1", ts: iso(8), actor: "plugin@dev-mbp", action: "run.create", target: "run-1002", result: "ok" }],
  },
  "run-1003": {
    id: "run-1003", project_id: "proj-core", repository_id: "repo-worker", repository_name: "devboard-worker",
    type: "genesis_import", status: "failed", risk_level: "high",
    started_at: iso(1460), finished_at: iso(1440), duration_ms: 1200000, task_id: "CORE-103",
    source: src("local_plugin_snapshot", "conflict_with_code", "plugin@dev-mbp / snapshot #4790", 1440, "snap-4790"),
    local_source_labels: [
      { label: "Local snapshot", value: "snapshot #4790 (stale)", source: src("local_plugin_snapshot", "stale", "plugin@dev-mbp", 1440, "snap-4790") },
    ],
    timeline: [
      { id: "e1", ts: iso(1460), label: "Run queued", status: "info" },
      { id: "e2", ts: iso(1458), label: "Local snapshot acquired", status: "ok" },
      { id: "e3", ts: iso(1450), label: "Analyzer pass", status: "error", detail: "Parser failed on 3 modules" },
      { id: "e4", ts: iso(1440), label: "Import aborted", status: "error" },
    ],
    metrics: [
      { label: "Nodes", value: "—", tone: "bad" },
      { label: "Errors", value: "3", tone: "bad" },
    ],
    risk_triggers: [
      { id: "rt1", label: "Risk ≥ high", level: "high", reason: "Worker touches queue + retention." },
      { id: "rt2", label: "Snapshot/graph conflict", level: "high", reason: "Local snapshot conflicts with analyzer graph." },
    ],
    safety_results: [
      { id: "s1", name: "No code-write performed", status: "pass", detail: "Read-only import." },
      { id: "s2", name: "Snapshot vs graph consistency", status: "fail", detail: "3 modules failed to parse." },
    ],
    artifact_ids: [],
    test_output: "FATAL  Analyzer: unexpected token in worker/queue/Dispatcher.php:88\nImport aborted after 3 module failures.",
    graph_status: "failed", wiki_status: "stale", wiki_page_id: "wiki-worker",
    audit_events: [
      { id: "a1", ts: iso(1460), actor: "plugin@dev-mbp", action: "run.create", target: "run-1003", result: "ok" },
      { id: "a2", ts: iso(1440), actor: "system", action: "run.fail", target: "run-1003", result: "error" },
    ],
  },
  "run-1004": {
    id: "run-1004", project_id: "proj-pay", repository_id: "repo-billing", repository_name: "payments-svc",
    type: "genesis_import", status: "passed", risk_level: "critical",
    started_at: iso(330), finished_at: iso(310), duration_ms: 1200000, task_id: "PAY-201",
    source: src("local_analyzer", "verified_from_code", "analyzer@dev-mbp / scan #218", 310, "scan-218"),
    reviewed_by: "Avery Cole",
    local_source_labels: [
      { label: "Local snapshot", value: "snapshot #2210 (not remote Git truth)", source: src("local_plugin_snapshot", "verified_from_code", "plugin@dev-mbp", 310, "snap-2210") },
    ],
    timeline: [
      { id: "e1", ts: iso(330), label: "Run queued", status: "info" },
      { id: "e2", ts: iso(326), label: "Analyzer pass complete", status: "ok" },
      { id: "e3", ts: iso(318), label: "Safety checks passed", status: "ok" },
      { id: "e4", ts: iso(312), label: "Reviewed by Avery Cole", status: "ok" },
    ],
    metrics: [
      { label: "Nodes", value: "642", tone: "neutral" },
      { label: "Tests", value: "311 / 311", tone: "good" },
      { label: "Coverage", value: "91%", tone: "good" },
    ],
    risk_triggers: [{ id: "rt1", label: "Critical repo", level: "critical", reason: "Billing service — review mandatory." }],
    safety_results: [
      { id: "s1", name: "No code-write performed", status: "pass", detail: "Read-only import." },
      { id: "s2", name: "Secret scan", status: "pass", detail: "No secrets detected." },
      { id: "s3", name: "Destructive scans blocked", status: "pass", detail: "Policy disables destructive scans." },
    ],
    artifact_ids: ["art-5004"],
    test_output: "PASS  311 tests\nDuration: 28.9s",
    graph_status: "complete", wiki_status: "complete", wiki_page_id: "wiki-pay",
    audit_events: [
      { id: "a1", ts: iso(330), actor: "analyzer@dev-mbp", action: "run.create", target: "run-1004", result: "ok" },
      { id: "a2", ts: iso(312), actor: "Avery Cole", action: "run.review", target: "run-1004", result: "ok" },
    ],
  },
  "run-1006": {
    id: "run-1006", project_id: "proj-docs", repository_id: "repo-docs", repository_name: "knowledge-base",
    type: "analysis", status: "reviewed", risk_level: "low",
    started_at: iso(900), finished_at: iso(880), duration_ms: 1200000, task_id: "DOCS-301",
    source: src("local_analyzer", "verified_from_code", "analyzer@docs-box / scan #112", 880, "scan-112"),
    reviewed_by: "Dane Okoro",
    local_source_labels: [
      { label: "Local snapshot", value: "snapshot #112", source: src("local_plugin_snapshot", "verified_from_code", "plugin@docs-box", 880, "snap-112") },
    ],
    timeline: [
      { id: "e1", ts: iso(900), label: "Run queued", status: "info" },
      { id: "e2", ts: iso(885), label: "Analysis complete", status: "ok" },
      { id: "e3", ts: iso(880), label: "Reviewed", status: "ok" },
    ],
    metrics: [{ label: "Nodes", value: "208", tone: "neutral" }],
    risk_triggers: [],
    safety_results: [{ id: "s1", name: "No code-write performed", status: "pass", detail: "Read-only." }],
    artifact_ids: ["art-5006"],
    test_output: "No tests configured for analysis run.",
    graph_status: "pending", wiki_status: "complete", wiki_page_id: null,
    audit_events: [{ id: "a1", ts: iso(900), actor: "analyzer@docs-box", action: "run.create", target: "run-1006", result: "ok" }],
  },
};

export function runSummaries(): RunSummary[] {
  return Object.values(RUNS).map((r) => {
    const { local_source_labels, timeline, metrics, risk_triggers, safety_results, artifact_ids, test_output, graph_status, wiki_status, wiki_page_id, audit_events, ...s } = r;
    return s;
  }).sort((a, b) => +new Date(b.started_at) - +new Date(a.started_at));
}

// ---------------- Wiki ----------------

const WIKI_BASE: WikiPageDetail[] = [
  {
    id: "wiki-genesis", title: "Genesis Import Lifecycle", project_id: "proj-core", category: "Pipelines",
    source_status: "verified_from_code", has_evidence: true, updated_at: iso(42),
    source: src("local_plugin_snapshot", "verified_from_code", "plugin@dev-mbp / snapshot #4821", 42, "snap-4821"),
    body_markdown: "## Genesis Import\n\nA **Genesis Import** ingests a full local plugin snapshot and produces the initial code graph + artifacts. It does **not** treat the snapshot as remote Git truth.\n\n- Snapshot is read-only.\n- Analyzer derives nodes/edges.\n- Review required when risk ≥ project policy threshold.",
    evidence: [
      { id: "ev1", label: "RunImportController@genesis", kind: "code_ref", ref: "app/Http/Controllers/RunImportController.php", source: src("local_analyzer", "verified_from_code", "analyzer@dev-mbp", 42) },
      { id: "ev2", label: "Run #1001", kind: "run_ref", ref: "run-1001", source: src("server_history", "verified_from_code", "server history", 42) },
    ],
    related_run_ids: ["run-1001"], related_node_ids: ["n-svc-import", "n-route-runs"],
  },
  {
    id: "wiki-delta", title: "Delta Sync Mechanics", project_id: "proj-core", category: "Pipelines",
    source_status: "developer_provided", has_evidence: true, updated_at: iso(120),
    source: src("local_plugin_diff", "developer_provided", "plugin@dev-mbp / diff #931", 120, "diff-931"),
    body_markdown: "## Delta Sync\n\nApplies an incremental diff against the last snapshot. Cheaper than Genesis, but can drift if snapshots are skipped.",
    evidence: [{ id: "ev1", label: "Run #1002", kind: "run_ref", ref: "run-1002", source: src("server_history", "developer_provided", "server history", 120) }],
    related_run_ids: ["run-1002"], related_node_ids: ["n-svc-import"],
  },
  {
    id: "wiki-worker", title: "Worker Import Notes", project_id: "proj-core", category: "Operations",
    source_status: "conflict_with_code", has_evidence: true, updated_at: iso(1440),
    source: src("user_manual", "conflict_with_code", "manual entry / Dane Okoro", 1440),
    body_markdown: "## Worker Import\n\n> ⚠️ This page conflicts with the latest imported code graph. Needs reconciliation.",
    evidence: [{ id: "ev1", label: "Run #1003 (failed)", kind: "run_ref", ref: "run-1003", source: src("server_history", "conflict_with_code", "server history", 1440) }],
    related_run_ids: ["run-1003"], related_node_ids: [],
  },
  {
    id: "wiki-graph", title: "Graph Refresh Policy", project_id: "proj-core", category: "Graph",
    source_status: "stale", has_evidence: false, updated_at: iso(2880),
    source: src("ai_generated", "stale", "ai_generated draft", 2880),
    body_markdown: "## Graph Refresh\n\n_AI-generated draft — not yet verified from code._",
    evidence: [], related_run_ids: [], related_node_ids: [],
  },
  {
    id: "wiki-policy", title: "Run Review Policy", project_id: "proj-core", category: "Policy",
    source_status: "needs_verification", has_evidence: false, updated_at: iso(4320),
    source: src("user_manual", "needs_verification", "manual entry / Priya Menon", 4320),
    body_markdown: "## Run Review Policy\n\nRuns above the project risk threshold require explicit human review before being marked reviewed.",
    evidence: [], related_run_ids: [], related_node_ids: [],
  },
  {
    id: "wiki-pay", title: "Payments Import Constraints", project_id: "proj-pay", category: "Policy",
    source_status: "verified_from_code", has_evidence: true, updated_at: iso(310),
    source: src("local_analyzer", "verified_from_code", "analyzer@dev-mbp / scan #218", 310, "scan-218"),
    body_markdown: "## Payments Constraints\n\n- Destructive scans **disabled**.\n- Review required ≥ medium risk.\n- Retention 90 days.",
    evidence: [{ id: "ev1", label: "Run #1004", kind: "run_ref", ref: "run-1004", source: src("server_history", "verified_from_code", "server history", 310) }],
    related_run_ids: ["run-1004"], related_node_ids: [],
  },
];

export const WIKI: Record<string, WikiPageDetail> = {};
WIKI_BASE.forEach((w) => { WIKI[w.id] = w; });

export function wikiSummaries(): WikiPageSummary[] {
  return Object.values(WIKI).map((w) => {
    const { body_markdown, evidence, related_run_ids, related_node_ids, ...s } = w;
    return s;
  });
}

// ---------------- Graph ----------------

export const GRAPH: GraphView = {
  snapshot_id: "snap-4821", run_id: "run-1001", generated_at: iso(42),
  source: src("local_analyzer", "verified_from_code", "analyzer@dev-mbp / snapshot #4821", 42, "snap-4821"),
  stats: { nodes: 12, edges: 14, modules: 4, routes: 3 },
  nodes: [
    { id: "n-route-runs", label: "GET /api/dashboard/runs", kind: "route", repository: "devboard-api", degree: 4, risk: "medium", source: src("local_analyzer", "verified_from_code", "analyzer", 42) },
    { id: "n-route-kanban", label: "GET /api/dashboard/kanban", kind: "route", repository: "devboard-api", degree: 3, risk: "low", source: src("local_analyzer", "verified_from_code", "analyzer", 42) },
    { id: "n-route-import", label: "POST /runs/{id}/retry-import", kind: "route", repository: "devboard-api", degree: 2, risk: "high", source: src("local_analyzer", "verified_from_code", "analyzer", 42) },
    { id: "n-ctrl-run", label: "RunController", kind: "class", repository: "devboard-api", degree: 5, risk: "medium", source: src("local_analyzer", "verified_from_code", "analyzer", 42) },
    { id: "n-svc-import", label: "ImportService", kind: "service", repository: "devboard-api", degree: 6, risk: "high", source: src("local_analyzer", "verified_from_code", "analyzer", 42) },
    { id: "n-svc-graph", label: "GraphBuilder", kind: "service", repository: "devboard-api", degree: 4, risk: "medium", source: src("local_analyzer", "verified_from_code", "analyzer", 42) },
    { id: "n-model-run", label: "Run", kind: "model", repository: "devboard-api", degree: 5, risk: "low", source: src("local_analyzer", "verified_from_code", "analyzer", 42) },
    { id: "n-model-artifact", label: "Artifact", kind: "model", repository: "devboard-api", degree: 4, risk: "low", source: src("local_analyzer", "verified_from_code", "analyzer", 42) },
    { id: "n-mod-import", label: "import/", kind: "module", repository: "devboard-api", degree: 7, risk: "medium", source: src("local_analyzer", "verified_from_code", "analyzer", 42) },
    { id: "n-mod-graph", label: "graph/", kind: "module", repository: "devboard-api", degree: 5, risk: "low", source: src("local_analyzer", "verified_from_code", "analyzer", 42) },
    { id: "n-fn-parse", label: "parseSnapshot()", kind: "function", repository: "devboard-api", degree: 3, risk: "medium", source: src("local_analyzer", "verified_from_code", "analyzer", 42) },
    { id: "n-fn-diff", label: "applyDelta()", kind: "function", repository: "devboard-api", degree: 2, risk: "low", source: src("local_analyzer", "verified_from_code", "analyzer", 42) },
  ],
  edges: [
    { id: "g1", from: "n-route-runs", to: "n-ctrl-run", kind: "routes_to" },
    { id: "g2", from: "n-route-kanban", to: "n-ctrl-run", kind: "routes_to" },
    { id: "g3", from: "n-route-import", to: "n-ctrl-run", kind: "routes_to" },
    { id: "g4", from: "n-ctrl-run", to: "n-svc-import", kind: "calls" },
    { id: "g5", from: "n-ctrl-run", to: "n-model-run", kind: "uses" },
    { id: "g6", from: "n-svc-import", to: "n-svc-graph", kind: "calls" },
    { id: "g7", from: "n-svc-import", to: "n-fn-parse", kind: "calls" },
    { id: "g8", from: "n-svc-graph", to: "n-fn-diff", kind: "calls" },
    { id: "g9", from: "n-svc-import", to: "n-model-artifact", kind: "uses" },
    { id: "g10", from: "n-mod-import", to: "n-svc-import", kind: "imports" },
    { id: "g11", from: "n-mod-graph", to: "n-svc-graph", kind: "imports" },
    { id: "g12", from: "n-svc-graph", to: "n-model-run", kind: "uses" },
    { id: "g13", from: "n-fn-parse", to: "n-model-artifact", kind: "uses" },
    { id: "g14", from: "n-ctrl-run", to: "n-route-runs", kind: "routes_to" },
  ],
};

// ---------------- Artifacts ----------------

export const ARTIFACTS: Artifact[] = [
  { id: "art-5001", name: "genesis_devboard-api_4821.json", kind: "genesis_import", state: "imported", project_id: "proj-core", repository_id: "repo-api", run_id: "run-1001", size_bytes: 4823120, checksum: "sha256:9f3a…21bc", created_at: iso(42), validated: true, downloadable: true, source: src("local_plugin_snapshot", "verified_from_code", "plugin@dev-mbp", 42, "snap-4821") },
  { id: "art-5002", name: "delta_devboard-web_931.json", kind: "delta_sync", state: "validated", project_id: "proj-core", repository_id: "repo-web", run_id: "run-1002", size_bytes: 218430, checksum: "sha256:7c11…aa90", created_at: iso(8), validated: true, downloadable: true, source: src("local_plugin_diff", "developer_provided", "plugin@dev-mbp", 8, "diff-931") },
  { id: "art-5003", name: "delta_devboard-worker_4790.json", kind: "delta_sync", state: "invalid", project_id: "proj-core", repository_id: "repo-worker", run_id: "run-1003", size_bytes: 0, checksum: "—", created_at: iso(1440), validated: false, downloadable: false, source: src("local_plugin_snapshot", "stale", "plugin@dev-mbp", 1440, "snap-4790") },
  { id: "art-5004", name: "genesis_payments-svc_2210.json", kind: "genesis_import", state: "imported", project_id: "proj-pay", repository_id: "repo-billing", run_id: "run-1004", size_bytes: 2310044, checksum: "sha256:b2d4…ff01", created_at: iso(310), validated: true, downloadable: true, source: src("local_analyzer", "verified_from_code", "analyzer@dev-mbp", 310, "scan-218") },
  { id: "art-5005", name: "route-smoke_20260601.json", kind: "report", state: "validated", project_id: "proj-core", repository_id: "repo-api", run_id: null, size_bytes: 18422, checksum: "sha256:3a90…12cd", created_at: iso(90), validated: true, downloadable: true, source: src("server_history", "verified_from_code", "quality runner", 90) },
  { id: "art-5006", name: "analysis_knowledge-base_112.json", kind: "analysis", state: "uploaded", project_id: "proj-docs", repository_id: "repo-docs", run_id: "run-1006", size_bytes: 90233, checksum: "sha256:4e21…77ab", created_at: iso(880), validated: false, downloadable: false, source: src("local_analyzer", "verified_from_code", "analyzer@docs-box", 880, "scan-112") },
];

// ---------------- Quality ----------------

export const QUALITY_OVERVIEW: QualityOverview = {
  overall_status: "warning",
  latest_gate: { gate: "pull_request", status: "warning", generated_at: iso(90) },
  latest_route_smoke: { status: "pass", generated_at: iso(90) },
  latest_security: { status: "warning", generated_at: iso(180) },
  stale_or_missing: [
    { label: "Playwright E2E", reason: "Configured but disabled — no recent run." },
    { label: "Truth registry: payments refund rule", reason: "Marked example, not yet verified from code." },
    { label: "Graph coverage for devboard-web", reason: "Stale snapshot." },
  ],
  counters: { passed: 142, failed: 3, warnings: 7, skipped: 11 },
};

export const QUALITY_CURRENT_STATE: QualityCurrentState = {
  deterministic: true,
  description: "Hades Agent quality verification is deterministic and controlled. Tests verify domain truth, not only that routes return 200.",
  current_state: "Route inventory + SAFE_READ smoke + PR gate active. DAST/load/E2E configured but disabled.",
  desired_state: "Full gate coverage with verified truth registry, human-approved destructive scans, machine + human readable evidence per run.",
  transition_notes: [
    "A change is a transition from current state to desired state.",
    "Mutating scans require explicit confirmation.",
    "Destructive scans require explicit human approval and stay disabled unless the API allows them.",
  ],
};

export const ROUTE_INVENTORY: RouteInventoryEntry[] = [
  { id: "ri1", name: "dashboard.runs.index", method: "GET", path: "/api/dashboard/runs", controller_action: "RunController@index", classification: "SAFE_READ", configured: true, parameter_provider: "not_required" },
  { id: "ri2", name: "dashboard.runs.show", method: "GET", path: "/api/dashboard/runs/{run}", controller_action: "RunController@show", classification: "SAFE_READ", configured: true, parameter_provider: "configured" },
  { id: "ri3", name: "dashboard.runs.retry", method: "POST", path: "/api/dashboard/runs/{run}/retry-import", controller_action: "RunController@retryImport", classification: "MUTATING", configured: true, parameter_provider: "configured" },
  { id: "ri4", name: "dashboard.artifacts.purge", method: "DELETE", path: "/api/dashboard/artifacts/{artifact}", controller_action: "ArtifactController@destroy", classification: "DESTRUCTIVE", configured: true, parameter_provider: "configured" },
  { id: "ri5", name: "dashboard.tokens.store", method: "POST", path: "/api/dashboard/admin/plugin-tokens", controller_action: "PluginTokenController@store", classification: "MUTATING", configured: true, parameter_provider: "not_required" },
  { id: "ri6", name: "dashboard.kanban.index", method: "GET", path: "/api/dashboard/kanban", controller_action: "KanbanController@index", classification: "SAFE_READ", configured: true, parameter_provider: "not_required" },
  { id: "ri7", name: "dashboard.system.audit-exports", method: "POST", path: "/api/dashboard/system/audit-exports", controller_action: "SystemController@auditExport", classification: "MUTATING", configured: true, parameter_provider: "configured" },
  { id: "ri8", name: "unknown.legacy.sync", method: "POST", path: "/api/dashboard/legacy/sync", controller_action: "LegacyController@sync", classification: "UNKNOWN", configured: false, parameter_provider: "missing", warning: "UNKNOWN classification — not configured, no parameter provider. Excluded from smoke." },
];

export const ROUTE_SMOKE: RouteSmokeRow[] = [
  { id: "rs1", route: "dashboard.runs.index", actor: "pm", expected_status: 200, actual_status: 200, result: "pass", blocking: false },
  { id: "rs2", route: "dashboard.runs.index", actor: "guest", expected_status: 401, actual_status: 401, result: "pass", blocking: false },
  { id: "rs3", route: "dashboard.runs.show", actor: "developer", expected_status: 200, actual_status: 200, result: "pass", blocking: false },
  { id: "rs4", route: "dashboard.kanban.index", actor: "pm", expected_status: 200, actual_status: 200, result: "pass", blocking: false },
  { id: "rs5", route: "dashboard.runs.retry", actor: "pm", expected_status: 403, actual_status: 403, result: "pass", blocking: false },
  { id: "rs6", route: "dashboard.system.index", actor: "developer", expected_status: 403, actual_status: 500, result: "fail", blocking: true, skipped_reason: undefined },
  { id: "rs7", route: "dashboard.artifacts.purge", actor: "admin", expected_status: 200, actual_status: null, result: "skipped", skipped_reason: "DESTRUCTIVE route — skipped, requires human approval.", blocking: false },
  { id: "rs8", route: "dashboard.tokens.store", actor: "admin", expected_status: 201, actual_status: null, result: "skipped", skipped_reason: "MUTATING route — skipped unless confirmed.", blocking: false },
];

export const AUTH_MATRIX: AuthMatrixRow[] = [
  { resource: "dashboard.runs.index", decisions: { guest: "denied", user: "allowed", admin: "allowed", developer: "allowed", sysadmin: "allowed" } },
  { resource: "dashboard.runs.retry", decisions: { guest: "denied", user: "denied", admin: "allowed", developer: "allowed", sysadmin: "denied" } },
  { resource: "dashboard.artifacts.purge", decisions: { guest: "denied", user: "denied", admin: "allowed", developer: "denied", sysadmin: "allowed" } },
  { resource: "dashboard.projects.show", decisions: { guest: "denied", user: "allowed_owner_only", admin: "allowed", developer: "allowed_same_tenant", sysadmin: "allowed" } },
  { resource: "dashboard.admin.plugin-tokens", decisions: { guest: "denied", user: "denied", admin: "allowed", developer: "denied", sysadmin: "unknown" } },
  { resource: "dashboard.system.index", decisions: { guest: "denied", user: "denied", admin: "allowed", developer: "denied", sysadmin: "allowed" } },
];

export const SECURITY_CHECKS: SecurityCheck[] = [
  { id: "sc1", tool: "composer audit", category: "dependency", state: "implemented", requires_human_approval: false, destructive: false, description: "Composer dependency vulnerability audit.", last_run_at: iso(180) },
  { id: "sc2", tool: "PHPStan", category: "static", state: "implemented", requires_human_approval: false, destructive: false, description: "Static analysis (level 6).", last_run_at: iso(180) },
  { id: "sc3", tool: "Semgrep", category: "static", state: "warning", requires_human_approval: false, destructive: false, description: "SAST ruleset — 2 medium findings.", last_run_at: iso(180) },
  { id: "sc4", tool: "Trivy", category: "dependency", state: "configured_disabled", requires_human_approval: false, destructive: false, description: "Container/filesystem scan — disabled in this env.", last_run_at: null },
  { id: "sc5", tool: "ZAP baseline", category: "dast", state: "configured_disabled", requires_human_approval: false, destructive: false, description: "Passive DAST baseline scan.", last_run_at: null },
  { id: "sc6", tool: "ZAP active scan", category: "dast", state: "missing_setup", requires_human_approval: true, destructive: true, description: "Active DAST — destructive, requires human approval. Never default.", last_run_at: null },
  { id: "sc7", tool: "Nuclei", category: "dast", state: "missing_setup", requires_human_approval: true, destructive: true, description: "Template-based scanner — destructive, never default.", last_run_at: null },
  { id: "sc8", tool: "Infection", category: "mutation", state: "configured_disabled", requires_human_approval: false, destructive: false, description: "Mutation testing — disabled (slow).", last_run_at: null },
  { id: "sc9", tool: "k6", category: "load", state: "missing_setup", requires_human_approval: true, destructive: true, description: "Load test — requires human approval.", last_run_at: null },
  { id: "sc10", tool: "Playwright", category: "e2e", state: "configured_disabled", requires_human_approval: false, destructive: false, description: "Browser E2E — configured, no recent run.", last_run_at: null },
  { id: "sc11", tool: "axe-core", category: "accessibility", state: "configured_disabled", requires_human_approval: false, destructive: false, description: "Accessibility checks via Playwright.", last_run_at: null },
];

export const ROADMAP: RoadmapPhase[] = [
  { id: "rp1", phase: "Phase 1", title: "Route Inventory & SAFE_READ Smoke", status: "done", items: ["Classify Laravel routes", "SAFE_READ smoke", "Parameter providers"] },
  { id: "rp2", phase: "Phase 2", title: "Truth Registry & Authorization Matrix", status: "in_progress", items: ["Feature → rule → test mapping", "Actor/role coverage", "Policy/gate verification"] },
  { id: "rp3", phase: "Phase 3", title: "Security & DAST Readiness", status: "in_progress", items: ["Composer audit + PHPStan + Semgrep", "ZAP baseline (disabled)", "Active scans gated by approval"] },
  { id: "rp4", phase: "Phase 4", title: "E2E, Load & Mutation", status: "planned", items: ["Playwright + axe-core", "k6 load (approval-gated)", "Infection mutation testing"] },
  { id: "rp5", phase: "Phase 5", title: "Release Gate Automation", status: "planned", items: ["Release gate", "Machine + human readable evidence", "Human approvals for risky operations"] },
];

export const TRUTH_REGISTRY: TruthRegistryEntry[] = [
  { id: "tr1", feature: "Run review gating", domain_rules: ["Runs above project risk threshold require human review before reviewed status."], required_tests: ["Feature/RunReviewGateTest"], risk: "high", evidence: "RunController@review + RunPolicy", marking: "verified", source: src("local_analyzer", "verified_from_code", "analyzer@dev-mbp", 42) },
  { id: "tr2", feature: "Local snapshot is not Git truth", domain_rules: ["Snapshots labelled local_plugin_snapshot, never remote Git truth."], required_tests: ["Unit/SourceLabelTest"], risk: "medium", evidence: "SourceMeta enum + UI labels", marking: "verified", source: src("local_analyzer", "verified_from_code", "analyzer@dev-mbp", 42) },
  { id: "tr3", feature: "Destructive scans disabled by default", domain_rules: ["Destructive scans require human approval and stay disabled unless API allows."], required_tests: ["Feature/DestructiveScanPolicyTest"], risk: "critical", evidence: "QualityPolicy@destructive", marking: "verified", source: src("local_analyzer", "verified_from_code", "analyzer@dev-mbp", 90) },
  { id: "tr4", feature: "Payments refund window (EXAMPLE)", domain_rules: ["Refunds allowed within 30 days of capture."], required_tests: ["Feature/RefundWindowTest (not implemented)"], risk: "high", evidence: "Illustrative — not confirmed in code.", marking: "example", source: src("ai_generated", "needs_verification", "ai_generated draft", 200) },
  { id: "tr5", feature: "Token shown once (INFERRED)", domain_rules: ["Plain plugin token returned only on create/rotate, never stored."], required_tests: ["Feature/TokenVisibilityTest"], risk: "high", evidence: "Inferred from controller behaviour.", marking: "inferred", source: src("local_analyzer", "needs_verification", "analyzer@dev-mbp", 90) },
];

export function buildReports(): QualityReport[] {
  return [
    {
      tool: "route-smoke", status: "warning", generated_at: iso(90),
      summary: { total: 8, passed: 5, failed: 1, warnings: 0, skipped: 2 },
      findings: [
        { id: "f1", severity: "high", type: "route_5xx", message: "Route returned 500 instead of expected 403.", route: "dashboard.system.index", expected: "403", actual: "500", evidence: { actor: "developer" } },
      ],
    },
    {
      tool: "route-inventory", status: "warning", generated_at: iso(95),
      summary: { total: 8, passed: 7, failed: 0, warnings: 1, skipped: 0 },
      findings: [
        { id: "f2", severity: "medium", type: "missing_config", message: "UNKNOWN route not configured and missing parameter provider.", route: "unknown.legacy.sync", expected: "classified", actual: "UNKNOWN", evidence: {} },
      ],
    },
    {
      tool: "security-audit", status: "warning", generated_at: iso(180),
      summary: { total: 11, passed: 6, failed: 0, warnings: 2, skipped: 3 },
      findings: [
        { id: "f3", severity: "medium", type: "critical_security_finding", message: "Semgrep: 2 medium findings in import parser.", route: "—", expected: "0 findings", actual: "2 findings", evidence: { tool: "semgrep" } },
      ],
    },
    {
      tool: "quality-gate", status: "pass", generated_at: iso(60),
      summary: { total: 3, passed: 3, failed: 0, warnings: 0, skipped: 0 },
      findings: [],
    },
  ];
}

export function buildGate(gate: string): QualityGate {
  const reports = buildReports();
  const smoke = reports[0];
  if (gate === "release") {
    return {
      gate: "release", status: "fail", generated_at: iso(30),
      blocking_findings: [smoke.findings[0]],
      warnings: [reports[2].findings[0]],
      human_approvals_required: [
        { id: "ap1", label: "Release sign-off (Admin)", approved: false },
        { id: "ap2", label: "Security review (Sysadmin)", approved: true },
      ],
    };
  }
  if (gate === "nightly") {
    return {
      gate: "nightly", status: "warning", generated_at: iso(720),
      blocking_findings: [],
      warnings: [reports[2].findings[0], reports[1].findings[0]],
      human_approvals_required: [],
    };
  }
  return {
    gate: "pull_request", status: "warning", generated_at: iso(90),
    blocking_findings: [],
    warnings: [reports[2].findings[0]],
    human_approvals_required: [{ id: "ap1", label: "Override PHPStan baseline", approved: false }],
  };
}

// ---------------- Admin ----------------

export const PLUGIN_TOKENS: PluginToken[] = [
  { id: "tok-1", name: "dev-mbp primary", prefix: "devb_live_8f2a", scopes: ["projects.read", "repositories.read", "policies.read", "runs.write", "artifacts.write", "wiki.write", "graph.write"], created_at: iso(20000), last_used_at: iso(42), created_by: "Avery Cole", revoked: false },
  { id: "tok-2", name: "docs-box", prefix: "devb_live_3c91", scopes: ["projects.read", "repositories.read", "policies.read", "wiki.write"], created_at: iso(43000), last_used_at: iso(880), created_by: "Dane Okoro", revoked: false },
  { id: "tok-3", name: "legacy laptop (revoked)", prefix: "devb_live_77aa", scopes: ["projects.read"], created_at: iso(90000), last_used_at: iso(60000), created_by: "Sam Reyes", revoked: true },
];

export const PLUGIN_DEVICES: PluginDevice[] = [
  { id: "dev-1", hostname: "dev-mbp", os: "macOS 15.3", plugin_version: "0.9.4", registered_at: iso(20000), last_seen_at: iso(42), token_prefix: "dvb_8f2a", status: "active" },
  { id: "dev-2", hostname: "docs-box", os: "Ubuntu 24.04", plugin_version: "0.9.2", registered_at: iso(43000), last_seen_at: iso(880), token_prefix: "dvb_3c91", status: "stale" },
  { id: "dev-3", hostname: "legacy-laptop", os: "Windows 11", plugin_version: "0.8.1", registered_at: iso(90000), last_seen_at: iso(60000), token_prefix: "dvb_77aa", status: "revoked" },
];

// ---------------- System ----------------

export const SYSTEM_STATUS: SystemStatus = {
  runtime: [
    { label: "API uptime", value: "12d 04h", tone: "good" },
    { label: "Memory", value: "412 / 1024 MB", tone: "neutral" },
    { label: "CPU load", value: "0.34", tone: "good" },
    { label: "DB pool", value: "6 / 20", tone: "neutral" },
    { label: "PHP / Laravel", value: "8.3 / 13.x", tone: "neutral" },
    { label: "Failed jobs", value: "1", tone: "warn" },
  ],
  queue: [
    { name: "imports", pending: 1, processing: 1, failed: 0 },
    { name: "graph", pending: 0, processing: 0, failed: 1 },
    { name: "exports", pending: 0, processing: 0, failed: 0 },
  ],
  graph_status: "stale",
  import_status: "in_progress",
  retention: { artifact_retention_days: 30, auto_purge_enabled: true },
  last_operation: { label: "Audit export (range 30d)", status: "ok", at: iso(240) },
  audit_export_available: true,
};

export const BACKUP_READINESS: BackupReadiness = {
  format: "devboard-backup-v1",
  compatibility_version: 1,
  can_export: true,
  can_restore_dry_run: true,
  components: [
    { key: "database", label: "Database metadata", included: true, detail: "Control-plane tables and product state." },
    { key: "storage", label: "Hades Agent storage", included: true, detail: "Artifacts, reports, task attachments, and audit exports." },
    { key: "secrets", label: "Secrets", included: false, detail: "Plaintext secrets are never included." },
  ],
  secret_policy: {
    includes_plaintext_secrets: false,
    required_secrets: [
      { name: "APP_KEY", required: true, present: true, fingerprint: "sha256:mock-app-key" },
      { name: "DB_CONNECTION", required: true, present: true, fingerprint: "sha256:mock-db" },
      { name: "NEO4J_URI", required: false, present: true, fingerprint: "sha256:mock-neo4j" },
    ],
  },
  last_backup: null,
  warnings: [],
};
