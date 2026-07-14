import { API_BASE_URL, DevboardApi } from "@/api/devboardApi";
import { KanbanBoard, ProjectLifecycleInput, ProjectStatusFilter, TaskAttachment, TaskDetail, User } from "@/types/devboard";

const MUTATING_METHODS = new Set(["POST", "PUT", "PATCH", "DELETE"]);
let csrfCookieRequest: Promise<void> | null = null;

function xsrfToken(): string | null {
  if (typeof document === "undefined") return null;

  const cookie = document.cookie
    .split("; ")
    .find((entry) => entry.startsWith("XSRF-TOKEN="));

  return cookie ? decodeURIComponent(cookie.slice("XSRF-TOKEN=".length)) : null;
}

async function ensureCsrfCookie(): Promise<void> {
  if (typeof document === "undefined" || xsrfToken()) return;

  csrfCookieRequest ??= fetch(`${API_BASE_URL}/sanctum/csrf-cookie`, {
    method: "GET",
    credentials: "include",
    headers: {
      Accept: "application/json",
      "X-Requested-With": "XMLHttpRequest",
    },
  })
    .then((res) => {
      if (!res.ok) {
        throw { message: `CSRF cookie request failed (${res.status})`, code: String(res.status) };
      }
    })
    .finally(() => {
      csrfCookieRequest = null;
    });

  await csrfCookieRequest;
}

function parseJson(text: string): any {
  if (!text) return null;

  try {
    return JSON.parse(text);
  } catch {
    return { message: text };
  }
}

const VALID_ROLES = new Set(["admin", "pm", "developer", "sysadmin", "agent"]);

function isUserPayload(value: unknown): value is User {
  if (!value || typeof value !== "object") return false;
  const user = value as Record<string, unknown>;
  return (
    typeof user.id === "string" &&
    typeof user.name === "string" &&
    user.name.trim().length > 0 &&
    typeof user.email === "string" &&
    typeof user.avatar_color === "string" &&
    typeof user.role === "string" &&
    VALID_ROLES.has(user.role)
  );
}

function normalizeRepositoryString(value: unknown): string | null {
  if (typeof value === "string") return value;
  if (!value || typeof value !== "object") return null;

  const repository = value as Record<string, unknown>;
  if (typeof repository.name === "string") return repository.name;
  if (typeof repository.id === "string") return repository.id;

  return null;
}

function normalizeTaskRepositories<T>(task: T): T {
  if (!task || typeof task !== "object") return task;

  const record = task as Record<string, unknown>;
  if (!Array.isArray(record.repositories)) return task;

  return {
    ...record,
    repositories: record.repositories
      .map(normalizeRepositoryString)
      .filter((repository): repository is string => Boolean(repository)),
  } as T;
}

function normalizeKanbanTasks<T>(board: T): T {
  if (!board || typeof board !== "object") return board;

  const record = board as Record<string, unknown>;
  const tasks = record.tasks;
  if (!tasks || typeof tasks !== "object" || Array.isArray(tasks)) return board;

  return {
    ...record,
    tasks: Object.fromEntries(
      Object.entries(tasks as Record<string, unknown>).map(([taskId, task]) => [
        taskId,
        normalizeTaskRepositories(task),
      ]),
    ),
  } as T;
}

/**
 * Real Laravel 13 implementation. Cookie/session auth via `credentials: "include"`.
 * Endpoint paths are isolated here so they can be adjusted without touching components.
 * The browser UI uses dashboard endpoints only; plugin API endpoints are for the local
 * Python CLI/MCP plugin.
 */
async function req<T>(method: string, path: string, body?: unknown): Promise<T> {
  const normalizedMethod = method.toUpperCase();
  if (MUTATING_METHODS.has(normalizedMethod)) {
    await ensureCsrfCookie();
  }

  const headers: Record<string, string> = {
    "Content-Type": "application/json",
    Accept: "application/json",
    "X-Requested-With": "XMLHttpRequest",
  };
  const token = xsrfToken();
  if (token) {
    headers["X-XSRF-TOKEN"] = token;
  }

  const res = await fetch(`${API_BASE_URL}${path}`, {
    method: normalizedMethod,
    credentials: "include",
    headers,
    body: body !== undefined ? JSON.stringify(body) : undefined,
  });
  if (res.status === 204) return undefined as T;
  const text = await res.text();
  const data = parseJson(text);
  if (!res.ok) {
    throw { message: data?.message || data?.reason || `Request failed (${res.status})`, code: String(res.status) };
  }
  // Laravel resources commonly wrap payloads in { data: ... }
  return (data && typeof data === "object" && "data" in data ? data.data : data) as T;
}

async function reqForm<T>(method: string, path: string, body: FormData): Promise<T> {
  const normalizedMethod = method.toUpperCase();
  if (MUTATING_METHODS.has(normalizedMethod)) {
    await ensureCsrfCookie();
  }

  const headers: Record<string, string> = {
    Accept: "application/json",
    "X-Requested-With": "XMLHttpRequest",
  };
  const token = xsrfToken();
  if (token) {
    headers["X-XSRF-TOKEN"] = token;
  }

  const res = await fetch(`${API_BASE_URL}${path}`, {
    method: normalizedMethod,
    credentials: "include",
    headers,
    body,
  });
  if (res.status === 204) return undefined as T;
  const text = await res.text();
  const data = parseJson(text);
  if (!res.ok) {
    throw { message: data?.message || `Request failed (${res.status})`, code: String(res.status) };
  }

  return (data && typeof data === "object" && "data" in data ? data.data : data) as T;
}

const D = "/api/dashboard";
const projectResource = (projectId: string | undefined, resource: string) =>
  projectId ? `${D}/projects/${encodeURIComponent(projectId)}/${resource}` : `${D}/${resource}`;
const projectListPath = (status?: ProjectStatusFilter) =>
  status ? `${D}/projects?status=${encodeURIComponent(status)}` : `${D}/projects`;
const queryString = (params: Record<string, string | null | undefined>) => {
  const q = new URLSearchParams();
  Object.entries(params).forEach(([key, value]) => {
    if (value) q.set(key, value);
  });
  const qs = q.toString();
  return qs ? `?${qs}` : "";
};

export const httpApi: DevboardApi = {
  login: (p) => req("POST", `${D}/login`, p),
  logout: () => req("POST", `${D}/logout`),
  me: async () => {
    try {
      const user = await req<unknown>("GET", `${D}/me`);
      return isUserPayload(user) ? user : null;
    } catch {
      return null;
    }
  },

  getOverview: () => req("GET", `${D}/overview`),
  getKanban: async (projectId) => normalizeKanbanTasks(await req<KanbanBoard>("GET", projectResource(projectId, "kanban"))),
  createTask: (projectId, input) =>
    req<TaskDetail>("POST", `${D}/projects/${encodeURIComponent(projectId)}/tasks`, input).then(normalizeTaskRepositories),
  updateTask: (id, patch) => req<TaskDetail>("PATCH", `${D}/tasks/${encodeURIComponent(id)}`, patch).then(normalizeTaskRepositories),
  getTask: (id) => req<TaskDetail>("GET", `${D}/tasks/${encodeURIComponent(id)}`).then(normalizeTaskRepositories),
  clarifyTask: (id) => req("POST", `${D}/tasks/${encodeURIComponent(id)}/assistant/clarify`),
  resolveAssistantSuggestion: (id, status) =>
    req("PATCH", `${D}/assistant-suggestions/${encodeURIComponent(id)}`, { status }),
  applyAssistantSuggestion: (id) =>
    req("POST", `${D}/assistant-suggestions/${encodeURIComponent(id)}/apply`),
  uploadTaskAttachment: (taskId, file): Promise<TaskAttachment> => {
    const form = new FormData();
    form.append("file", file);
    return reqForm("POST", `${D}/tasks/${encodeURIComponent(taskId)}/attachments`, form);
  },
  deleteTaskAttachment: (taskId, attachmentId): Promise<TaskDetail> =>
    req("DELETE", `${D}/tasks/${encodeURIComponent(taskId)}/attachments/${encodeURIComponent(attachmentId)}`),
  getProjectMemory: (projectId, query) =>
    req("GET", `${D}/projects/${encodeURIComponent(projectId)}/memory${queryString({ domain: query?.domain, q: query?.query })}`),
  createProjectMemory: (projectId, input) =>
    req("POST", `${D}/projects/${encodeURIComponent(projectId)}/memory`, input),
  updateProjectMemory: (projectId, memoryId, input) =>
    req("PATCH", `${D}/projects/${encodeURIComponent(projectId)}/memory/${encodeURIComponent(memoryId)}`, input),
  deleteProjectMemory: (projectId, memoryId) =>
    req("DELETE", `${D}/projects/${encodeURIComponent(projectId)}/memory/${encodeURIComponent(memoryId)}`),
  getProjectWorkspaceBindings: (projectId) =>
    req("GET", `${D}/projects/${encodeURIComponent(projectId)}/workspace-bindings`)
      .then((payload: any) => payload?.workspace_bindings ?? payload),
  getProjectMemoryImports: (projectId) =>
    req("GET", `${D}/projects/${encodeURIComponent(projectId)}/memory/imports`)
      .then((payload: any) => payload?.import_batches ?? payload),
  getProjectMemoryImport: (projectId, batchId) =>
    req("GET", `${D}/projects/${encodeURIComponent(projectId)}/memory/imports/${encodeURIComponent(batchId)}`)
      .then((payload: any) => payload?.import_batch ?? payload),
  createProjectMemoryImport: (projectId, input) =>
    req("POST", `${D}/projects/${encodeURIComponent(projectId)}/memory/imports`, input)
      .then((payload: any) => payload?.import_batch ?? payload),
  cancelProjectMemoryImport: (projectId, batchId, message) =>
    req("POST", `${D}/projects/${encodeURIComponent(projectId)}/memory/imports/${encodeURIComponent(batchId)}/cancel`, { message })
      .then((payload: any) => payload?.import_batch ?? payload),
  getAgentChats: (projectId) =>
    req("GET", `${D}/projects/${encodeURIComponent(projectId)}/agent-chats`),
  getAgentChat: (projectId, threadId) =>
    req("GET", `${D}/projects/${encodeURIComponent(projectId)}/agent-chats/${encodeURIComponent(threadId)}`),
  createAgentChat: (projectId, input) =>
    req("POST", `${D}/projects/${encodeURIComponent(projectId)}/agent-chats`, input),
  sendAgentChatMessage: (projectId, threadId, input) =>
    req("POST", `${D}/projects/${encodeURIComponent(projectId)}/agent-chats/${encodeURIComponent(threadId)}/messages`, input),
  archiveAgentChat: (projectId, threadId, message) =>
    req("DELETE", `${D}/projects/${encodeURIComponent(projectId)}/agent-chats/${encodeURIComponent(threadId)}`, { message }),
  getAgentWork: (projectId) =>
    req("GET", `${D}/projects/${encodeURIComponent(projectId)}/agent-work`),
  getAgentWorkDetail: (projectId, workItemId) =>
    req("GET", `${D}/projects/${encodeURIComponent(projectId)}/agent-work/${encodeURIComponent(workItemId)}`),
  createAgentWork: (projectId, input) =>
    req("POST", `${D}/projects/${encodeURIComponent(projectId)}/agent-work`, input),
  cancelAgentWork: (workItemId, message) =>
    req("POST", `${D}/agent-work/${encodeURIComponent(workItemId)}/cancel`, { message }),
  archiveAgentWork: (workItemId, message) =>
    req("DELETE", `${D}/agent-work/${encodeURIComponent(workItemId)}`, { message }),
  getProjects: (status) => req("GET", projectListPath(status)),
  getProject: (id) => req("GET", `${D}/projects/${id}`),
  createProject: (payload) => req("POST", `${D}/projects`, payload),
  createProjectRepository: (projectId, input) =>
    req("POST", `${D}/projects/${encodeURIComponent(projectId)}/repositories`, input),
  updateProject: (id, payload) => req("PATCH", `${D}/projects/${encodeURIComponent(id)}`, payload),
  archiveProject: (id, payload?: ProjectLifecycleInput) =>
    req("POST", `${D}/projects/${encodeURIComponent(id)}/archive`, payload ?? {}),
  restoreProject: (id, payload?: ProjectLifecycleInput) =>
    req("POST", `${D}/projects/${encodeURIComponent(id)}/restore`, payload ?? {}),
  deleteProject: (id, payload?: ProjectLifecycleInput) =>
    req("POST", `${D}/projects/${encodeURIComponent(id)}/delete`, payload ?? {}),
  triageProjectBacklog: (id) =>
    req("POST", `${D}/projects/${encodeURIComponent(id)}/assistant/backlog-triage`),
  normalizeIntake: (projectId, rawText) =>
    req("POST", `${D}/projects/${encodeURIComponent(projectId)}/intake/normalize`, { raw_text: rawText }),

  getRuns: (projectId) => req("GET", projectResource(projectId, "runs")),
  getRun: (id) => req("GET", `${D}/runs/${id}`),
  retryImport: (id) => req("POST", `${D}/runs/${id}/retry-import`),
  reviewRun: (id) => req("POST", `${D}/runs/${id}/review`),

  getWiki: (projectId) => req("GET", projectResource(projectId, "wiki")),
  getWikiPage: (id, projectId) =>
    req("GET", projectId
      ? `${D}/projects/${encodeURIComponent(projectId)}/wiki/pages/${encodeURIComponent(id)}`
      : `${D}/wiki/pages/${encodeURIComponent(id)}`),
  createWikiPage: (projectId, input) =>
    req("POST", `${D}/projects/${encodeURIComponent(projectId)}/wiki/pages`, input),
  updateWikiPage: (id, input) =>
    req("PATCH", `${D}/wiki/pages/${encodeURIComponent(id)}`, input),
  getWikiRefreshRequests: (projectId) =>
    req("GET", `${D}/projects/${encodeURIComponent(projectId)}/wiki/refresh-requests`)
      .then((payload: any) => payload?.refresh_requests ?? payload),
  createWikiRefreshRequest: (projectId, input) =>
    req("POST", `${D}/projects/${encodeURIComponent(projectId)}/wiki/refresh-requests`, input)
      .then((payload: any) => payload?.refresh_request ?? payload?.job ?? payload),

  getGraph: (projectId, params) => {
    const q = new URLSearchParams();
    if (params?.snapshotId) q.set("snapshot_id", params.snapshotId);
    if (params?.runId) q.set("run_id", params.runId);
    const qs = q.toString();
    const base = projectId
      ? `${D}/projects/${encodeURIComponent(projectId)}/graph`
      : `${D}/graph`;
    return req("GET", `${base}${qs ? `?${qs}` : ""}`);
  },
  queryProjectGraph: (projectId, request) =>
    req("POST", `${D}/projects/${encodeURIComponent(projectId)}/graph/query`, request),

  getArtifacts: (projectId) => req("GET", projectResource(projectId, "artifacts")),
  downloadArtifact: (runId, artifactId) =>
    req("GET", `${D}/runs/${runId}/artifacts/${artifactId}/download`),

  getQualityOverview: () => req("GET", `${D}/quality/overview`),
  getQualityCurrentState: () => req("GET", `${D}/quality/current-state`),
  getQualityReports: () => req("GET", `${D}/quality/reports`),
  getRouteInventory: () => req("GET", `${D}/quality/route-inventory`),
  getRouteSmoke: () => req("GET", `${D}/quality/route-smoke`),
  getQualityGate: (gate) => req("GET", `${D}/quality/gates/${gate}`),
  getQualityRoadmap: () => req("GET", `${D}/quality/roadmap`),
  runQualityCheck: (tool, confirm) => req("POST", `${D}/quality/runs`, { tool, confirm: !!confirm }),

  getPluginTokens: () => req("GET", `${D}/admin/plugin-tokens`),
  createPluginToken: (name, scopes) => req("POST", `${D}/admin/plugin-tokens`, { name, scopes }),
  rotatePluginToken: (id) => req("POST", `${D}/admin/plugin-tokens/${id}/rotate`),
  revokePluginToken: (id) => req("DELETE", `${D}/admin/plugin-tokens/${id}`),
  getPluginDevices: () => req("GET", `${D}/admin/devices`),
  revokeDevice: (id) => req("DELETE", `${D}/admin/devices/${id}`),
  getAiAgents: () => req("GET", `${D}/admin/ai-agents`),
  updateAiModelProvider: (providerKey, input) =>
    req("PUT", `${D}/admin/ai-model-providers/${encodeURIComponent(providerKey)}`, input).then((payload: any) => payload.provider),
  validateAiModelProvider: (providerKey) =>
    req("POST", `${D}/admin/ai-model-providers/${encodeURIComponent(providerKey)}/validate`)
      .then((payload: any) => payload?.validation ?? payload),
  createAiModelProfile: (input) =>
    req("POST", `${D}/admin/ai-model-profiles`, input).then((payload: any) => payload.model_profile ?? payload),
  updateAiModelProfile: (profileKey, input) =>
    req("PUT", `${D}/admin/ai-model-profiles/${encodeURIComponent(profileKey)}`, input).then((payload: any) => payload.model_profile),
  deleteAiModelProfile: (profileKey) =>
    req("DELETE", `${D}/admin/ai-model-profiles/${encodeURIComponent(profileKey)}`),
  updateAiAgentProfile: (agentKey, input) =>
    req("PATCH", `${D}/admin/ai-agent-profiles/${encodeURIComponent(agentKey)}`, input).then((payload: any) => payload.agent_profile),

  getHadesAdmin: () => req("GET", `${D}/admin/hades`),
  createHadesBootstrapToken: (input) => req("POST", `${D}/admin/hades/bootstrap-tokens`, input),
  revokeHadesBootstrapToken: (id) => req("DELETE", `${D}/admin/hades/bootstrap-tokens/${encodeURIComponent(id)}`),
  createHadesJob: (input) => req("POST", `${D}/admin/hades/jobs`, input),
  reviewHadesMemoryProposal: (id, input) =>
    req("POST", `${D}/admin/hades/memory-proposals/${encodeURIComponent(id)}/review`, input),

  getSystem: () => req("GET", `${D}/system`),
  setArtifactRetention: (days, autoPurge) =>
    req("POST", `${D}/system/artifact-retention`, { retention_days: days, auto_purge_enabled: autoPurge }),
  runAuditExport: (rangeDays) => req("POST", `${D}/system/audit-exports`, { range_days: rangeDays }),
  getBackupReadiness: () => req("GET", `${D}/system/backups/readiness`),
  exportBackup: () => req("POST", `${D}/system/backups/export`),
  validateBackupBundle: (file) => {
    const form = new FormData();
    form.append("bundle", file);
    return reqForm("POST", `${D}/system/backups/validate`, form);
  },
};
