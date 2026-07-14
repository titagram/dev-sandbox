declare const jest: any;
declare const describe: any;
declare const beforeEach: any;
declare const it: any;
declare const expect: any;

jest.mock("@/api/devboardApi", () => ({
  API_BASE_URL: "http://127.0.0.1:8000",
}), { virtual: true });

import { httpApi } from "./httpApi";
import { DashboardGraphQueryRequest } from "@/types/devboard";

const forbiddenPluginNamespace = ["/api", "plugin", "v1"].join("/");

function jsonResponse(payload: unknown): Response {
  return {
    ok: true,
    status: 200,
    text: async () => JSON.stringify(payload),
  } as Response;
}

describe("httpApi multiproject dashboard endpoints", () => {
  const fetchMock = jest.fn();

  beforeEach(() => {
    fetchMock.mockReset();
    (global as any).fetch = fetchMock;
  });

  it("loads the cross-project overview from the dashboard API", async () => {
    fetchMock.mockResolvedValueOnce(jsonResponse({
      summary: { active_projects: 2, repositories_awaiting_genesis: 1 },
      tasks: {
        total: 3,
        blocked: 1,
        by_state: { backlog: 0, ready: 1, in_progress: 1, blocked: 1, review: 0, done: 0 },
        by_risk: { low: 0, medium: 2, high: 1, critical: 0 },
      },
      runs: { failed: 1, running: 1 },
      wiki: { stale_pages: 1 },
      agents: { online: 1, offline: 0 },
      projects: [],
    }));

    await httpApi.getOverview();

    expect(fetchMock).toHaveBeenCalledWith(
      "http://127.0.0.1:8000/api/dashboard/overview",
      expect.objectContaining({ method: "GET" }),
    );
  });

  it("uses project-scoped dashboard resource endpoints when a project id is supplied", async () => {
    fetchMock
      .mockResolvedValueOnce(jsonResponse({ columns: [], tasks: {} }))
      .mockResolvedValueOnce(jsonResponse([]))
      .mockResolvedValueOnce(jsonResponse([]))
      .mockResolvedValueOnce(jsonResponse([]))
      .mockResolvedValueOnce(jsonResponse({ snapshot_id: "snap-1", run_id: "run-1", stats: { nodes: 0, edges: 0, modules: 0, routes: 0 }, nodes: [], edges: [] }));

    await httpApi.getKanban("proj-core");
    await httpApi.getRuns("proj-core");
    await httpApi.getWiki("proj-core");
    await httpApi.getArtifacts("proj-core");
    await httpApi.getGraph("proj-core", { runId: "run-1" });

    const urls = fetchMock.mock.calls.map(([url]) => url);

    expect(urls).toEqual([
      "http://127.0.0.1:8000/api/dashboard/projects/proj-core/kanban",
      "http://127.0.0.1:8000/api/dashboard/projects/proj-core/runs",
      "http://127.0.0.1:8000/api/dashboard/projects/proj-core/wiki",
      "http://127.0.0.1:8000/api/dashboard/projects/proj-core/artifacts",
      "http://127.0.0.1:8000/api/dashboard/projects/proj-core/graph?run_id=run-1",
    ]);
    expect(urls.some((url) => String(url).includes(forbiddenPluginNamespace))).toBe(false);
  });

  it("posts graph explorer queries to the dashboard endpoint with the exact request body", async () => {
    Object.defineProperty(document, "cookie", {
      configurable: true,
      value: "XSRF-TOKEN=test-token",
    });
    fetchMock.mockResolvedValueOnce(jsonResponse({
      protocol_version: "v1",
      project_id: "proj/core",
      query_type: "neighborhood",
      found: true,
      reason: null,
      scope: { type: "repository", id: "repo-api" },
      projection: {
        status: "ready",
        quality: "complete",
        generated_at: "2026-07-14T10:00:00.000Z",
        active_graph_version: "graph-v1",
        node_count: 3,
        relationship_count: 2,
        unknown_kind_count: 0,
        missing_label_count: 0,
        excluded_node_count: 0,
      },
      items: [],
      edges: [],
      returned: 0,
      limit: 25,
      next_cursor: null,
      has_more: false,
      truncated: false,
      source: { type: "canonical_graph", status: "verified_from_code", origin: "canonical projection" },
    }));
    const request: DashboardGraphQueryRequest = {
      type: "neighborhood",
      scope_type: "repository",
      scope_id: "repo-api",
      node_handle: `gh1_${"a".repeat(43)}`,
      direction: "in",
      families: ["call", "dependency"],
      max_depth: 2,
      limit: 25,
    };

    await httpApi.queryProjectGraph("proj/core", request);

    expect(fetchMock).toHaveBeenCalledTimes(1);
    expect(fetchMock).toHaveBeenCalledWith(
      "http://127.0.0.1:8000/api/dashboard/projects/proj%2Fcore/graph/query",
      expect.objectContaining({ method: "POST", body: JSON.stringify(request) }),
    );
    const [url, options] = fetchMock.mock.calls[0];
    expect(String(url)).not.toContain(forbiddenPluginNamespace);
    expect(JSON.parse(options.body)).toEqual(request);
  });

  it("uses dashboard API endpoints for project wiki refresh requests", async () => {
    Object.defineProperty(document, "cookie", {
      configurable: true,
      value: "XSRF-TOKEN=test-token",
    });
    fetchMock
      .mockResolvedValueOnce(jsonResponse({ refresh_requests: [{ id: "wiki-job-1", status: "queued" }] }))
      .mockResolvedValueOnce(jsonResponse({ refresh_request: { id: "wiki-job-2", status: "queued" } }));

    const refreshRequests = await (httpApi as any).getWikiRefreshRequests("proj/core");
    const createdRefresh = await (httpApi as any).createWikiRefreshRequest("proj/core", {
      workspace_binding_id: "binding/one",
      repository_id: "repo-api",
      scope: "repository",
      reason: "Refresh stale API runbook",
      sections: ["overview", "runbook"],
      policy: "manual",
    });

    expect(refreshRequests).toEqual([{ id: "wiki-job-1", status: "queued" }]);
    expect(createdRefresh).toEqual({ id: "wiki-job-2", status: "queued" });
    expect(fetchMock).toHaveBeenNthCalledWith(
      1,
      "http://127.0.0.1:8000/api/dashboard/projects/proj%2Fcore/wiki/refresh-requests",
      expect.objectContaining({ method: "GET" }),
    );
    expect(fetchMock).toHaveBeenNthCalledWith(
      2,
      "http://127.0.0.1:8000/api/dashboard/projects/proj%2Fcore/wiki/refresh-requests",
      expect.objectContaining({
        method: "POST",
        body: JSON.stringify({
          workspace_binding_id: "binding/one",
          repository_id: "repo-api",
          scope: "repository",
          reason: "Refresh stale API runbook",
          sections: ["overview", "runbook"],
          policy: "manual",
        }),
      }),
    );
    expect(fetchMock.mock.calls.map(([url]) => String(url)).some((url) => url.includes(forbiddenPluginNamespace))).toBe(false);
  });

  it("uses dashboard API endpoints for project memory updates and scoped wiki pages", async () => {
    Object.defineProperty(document, "cookie", {
      configurable: true,
      value: "XSRF-TOKEN=test-token",
    });
    fetchMock
      .mockResolvedValueOnce(jsonResponse({ id: "mem/1", summary: "Updated memory" }))
      .mockResolvedValueOnce(jsonResponse({ id: "wiki/1", project_id: "proj/core" }));

    await (httpApi as any).updateProjectMemory("proj/core", "mem/1", {
      repository_id: null,
      task_id: null,
      run_id: null,
      kind: "decision",
      completeness: "complete",
      summary: "Updated memory",
      payload: { note: "Updated" },
    });
    await (httpApi as any).getWikiPage("wiki/1", "proj/core");

    expect(fetchMock).toHaveBeenNthCalledWith(
      1,
      "http://127.0.0.1:8000/api/dashboard/projects/proj%2Fcore/memory/mem%2F1",
      expect.objectContaining({
        method: "PATCH",
        body: JSON.stringify({
          repository_id: null,
          task_id: null,
          run_id: null,
          kind: "decision",
          completeness: "complete",
          summary: "Updated memory",
          payload: { note: "Updated" },
        }),
      }),
    );
    expect(fetchMock).toHaveBeenNthCalledWith(
      2,
      "http://127.0.0.1:8000/api/dashboard/projects/proj%2Fcore/wiki/pages/wiki%2F1",
      expect.objectContaining({ method: "GET" }),
    );
    expect(fetchMock.mock.calls.map(([url]) => String(url)).some((url) => url.includes(forbiddenPluginNamespace))).toBe(false);
  });

  it("uses dashboard API endpoints for persistent agent chats", async () => {
    Object.defineProperty(document, "cookie", {
      configurable: true,
      value: "XSRF-TOKEN=test-token",
    });
    fetchMock
      .mockResolvedValueOnce(jsonResponse({ threads: [] }))
      .mockResolvedValueOnce(jsonResponse({ thread: { id: "chat-1", messages: [] } }))
      .mockResolvedValueOnce(jsonResponse({ thread: { id: "chat-1", messages: [] } }))
      .mockResolvedValueOnce(jsonResponse({ thread: { id: "chat-1", messages: [{ role: "user", content: "Next" }] } }));

    await (httpApi as any).getAgentChats("proj/core");
    await (httpApi as any).getAgentChat("proj/core", "chat/1");
    await (httpApi as any).createAgentChat("proj/core", {
      agent_key: "local_agent",
      initial_message: "First",
    });
    await (httpApi as any).sendAgentChatMessage("proj/core", "chat/1", { content: "Next" });

    expect(fetchMock).toHaveBeenNthCalledWith(
      1,
      "http://127.0.0.1:8000/api/dashboard/projects/proj%2Fcore/agent-chats",
      expect.objectContaining({ method: "GET" }),
    );
    expect(fetchMock).toHaveBeenNthCalledWith(
      2,
      "http://127.0.0.1:8000/api/dashboard/projects/proj%2Fcore/agent-chats/chat%2F1",
      expect.objectContaining({ method: "GET" }),
    );
    expect(fetchMock).toHaveBeenNthCalledWith(
      3,
      "http://127.0.0.1:8000/api/dashboard/projects/proj%2Fcore/agent-chats",
      expect.objectContaining({ method: "POST" }),
    );
    expect(fetchMock).toHaveBeenNthCalledWith(
      4,
      "http://127.0.0.1:8000/api/dashboard/projects/proj%2Fcore/agent-chats/chat%2F1/messages",
      expect.objectContaining({ method: "POST", body: JSON.stringify({ content: "Next" }) }),
    );
    expect(fetchMock.mock.calls.map(([url]) => String(url)).some((url) => url.includes(forbiddenPluginNamespace))).toBe(false);
  });

  it("keeps flat dashboard endpoints as compatibility fallbacks", async () => {
    fetchMock
      .mockResolvedValueOnce(jsonResponse({ columns: [], tasks: {} }))
      .mockResolvedValueOnce(jsonResponse([]))
      .mockResolvedValueOnce(jsonResponse([]))
      .mockResolvedValueOnce(jsonResponse([]));

    await httpApi.getKanban();
    await httpApi.getRuns();
    await httpApi.getWiki();
    await httpApi.getArtifacts();

    expect(fetchMock.mock.calls.map(([url]) => url)).toEqual([
      "http://127.0.0.1:8000/api/dashboard/kanban",
      "http://127.0.0.1:8000/api/dashboard/runs",
      "http://127.0.0.1:8000/api/dashboard/wiki",
      "http://127.0.0.1:8000/api/dashboard/artifacts",
    ]);
  });

  it("creates and updates projects through dashboard API mutating endpoints", async () => {
    Object.defineProperty(document, "cookie", {
      configurable: true,
      value: "XSRF-TOKEN=test-token",
    });
    fetchMock
      .mockResolvedValueOnce(jsonResponse({ id: "proj-client", key: "client-portal" }))
      .mockResolvedValueOnce(jsonResponse({ id: "proj-client", key: "client-ops" }));

    await (httpApi as any).createProject({
      name: "Client Portal",
      key: "client-portal",
      description: "Client-facing project workspace.",
    });
    await (httpApi as any).updateProject("proj-client", {
      name: "Client Ops",
      key: "client-ops",
      description: "Updated description.",
    });

    expect(fetchMock).toHaveBeenNthCalledWith(
      1,
      "http://127.0.0.1:8000/api/dashboard/projects",
      expect.objectContaining({
        method: "POST",
        body: JSON.stringify({
          name: "Client Portal",
          key: "client-portal",
          description: "Client-facing project workspace.",
        }),
      }),
    );
    expect(fetchMock).toHaveBeenNthCalledWith(
      2,
      "http://127.0.0.1:8000/api/dashboard/projects/proj-client",
      expect.objectContaining({
        method: "PATCH",
        body: JSON.stringify({
          name: "Client Ops",
          key: "client-ops",
          description: "Updated description.",
        }),
      }),
    );
    expect(fetchMock.mock.calls.map(([url]) => String(url)).some((url) => url.includes(forbiddenPluginNamespace))).toBe(false);
  });

  it("declares project repositories through dashboard API mutating endpoints only", async () => {
    Object.defineProperty(document, "cookie", {
      configurable: true,
      value: "XSRF-TOKEN=test-token",
    });
    fetchMock.mockResolvedValueOnce(jsonResponse({
      id: "proj-client",
      key: "client",
      repository_count: 1,
      repositories: [{ id: "repo-target", key: "target-app", name: "Target App" }],
    }));

    await (httpApi as any).createProjectRepository("proj-client", {
      name: "Target App",
      key: "target-app",
      default_branch: "main",
      protected_paths: [".env", "*.pem"],
      excluded_paths: ["node_modules/", "vendor/"],
      stack_hints: ["laravel", "react"],
    });

    expect(fetchMock).toHaveBeenCalledWith(
      "http://127.0.0.1:8000/api/dashboard/projects/proj-client/repositories",
      expect.objectContaining({
        method: "POST",
        body: JSON.stringify({
          name: "Target App",
          key: "target-app",
          default_branch: "main",
          protected_paths: [".env", "*.pem"],
          excluded_paths: ["node_modules/", "vendor/"],
          stack_hints: ["laravel", "react"],
        }),
      }),
    );
    expect(fetchMock.mock.calls.map(([url]) => String(url)).some((url) => url.includes(forbiddenPluginNamespace))).toBe(false);
  });

  it("loads project workspace git metadata through the dashboard project endpoint", async () => {
    fetchMock.mockResolvedValueOnce(jsonResponse({
      id: "proj-client",
      key: "client",
      name: "Client",
      description: "",
      owner: "PM",
      repository_count: 1,
      open_tasks: 0,
      risk_level: "low",
      wiki_freshness: "complete",
      genesis_status: "not_started",
      delta_status: "not_started",
      graph_status: "not_started",
      updated_at: "2026-06-25T16:30:00Z",
      status: "active",
      archived_at: null,
      deleted_at: null,
      restored_at: null,
      repositories: [{
        id: "repo-target",
        project_id: "proj-client",
        key: "target-app",
        name: "Target App",
        default_branch: "main",
        git_mode: "local_clone",
        last_local_snapshot: null,
        local_workspace: {
          status: "linked",
          current_branch: "feature/git-sync",
          dirty_status: "dirty",
          remote_url_host: "github.com",
          upstream_branch: "origin/feature/git-sync",
          ahead_count: 2,
          behind_count: 1,
          git_state_observed_at: "2026-06-25T16:30:00Z",
          source_truth: "local_agent_reported",
        },
        genesis_status: "not_started",
        delta_status: "not_started",
        graph_status: "not_started",
        wiki_status: "not_started",
        risk_level: "low",
        latest_run_id: null,
        latest_run_status: null,
        source: { type: "user_manual", status: "needs_verification", origin: "test", generated_at: "2026-06-25T16:30:00Z" },
      }],
      policy: {
        code_write_allowed: true,
        destructive_scans_allowed: false,
        auto_import_on_snapshot: false,
        require_review_above_risk: "medium",
        retention_days: 90,
      },
      recent_run_ids: [],
      latest_artifact_ids: [],
    }));

    const project = await httpApi.getProject("proj-client");
    const workspace = project.repositories[0].local_workspace;

    expect(fetchMock).toHaveBeenCalledWith(
      "http://127.0.0.1:8000/api/dashboard/projects/proj-client",
      expect.objectContaining({ method: "GET" }),
    );
    expect(workspace?.remote_url_host).toBe("github.com");
    expect(workspace?.upstream_branch).toBe("origin/feature/git-sync");
    expect(workspace?.ahead_count).toBe(2);
    expect(workspace?.behind_count).toBe(1);
    expect(workspace?.source_truth).toBe("local_agent_reported");
    expect(fetchMock.mock.calls.map(([url]) => String(url)).some((url) => url.includes(forbiddenPluginNamespace))).toBe(false);
  });

  it("filters projects by lifecycle status through dashboard API query params", async () => {
    fetchMock
      .mockResolvedValueOnce(jsonResponse([]))
      .mockResolvedValueOnce(jsonResponse([]))
      .mockResolvedValueOnce(jsonResponse([]));

    await httpApi.getProjects();
    await httpApi.getProjects("archived");
    await httpApi.getProjects("deleted");

    expect(fetchMock.mock.calls.map(([url]) => url)).toEqual([
      "http://127.0.0.1:8000/api/dashboard/projects",
      "http://127.0.0.1:8000/api/dashboard/projects?status=archived",
      "http://127.0.0.1:8000/api/dashboard/projects?status=deleted",
    ]);
  });

  it("uses dashboard API endpoints for lifecycle actions", async () => {
    Object.defineProperty(document, "cookie", {
      configurable: true,
      value: "XSRF-TOKEN=test-token",
    });
    fetchMock
      .mockResolvedValueOnce(jsonResponse({ id: "proj-client", status: "archived" }))
      .mockResolvedValueOnce(jsonResponse({ id: "proj-client", status: "active" }))
      .mockResolvedValueOnce(jsonResponse({ id: "proj-client", status: "deleted" }));

    await httpApi.archiveProject("proj-client", { reason: "Done." });
    await httpApi.restoreProject("proj-client", { reason: "Resume." });
    await httpApi.deleteProject("proj-client", { reason: "Trash." });

    expect(fetchMock).toHaveBeenNthCalledWith(
      1,
      "http://127.0.0.1:8000/api/dashboard/projects/proj-client/archive",
      expect.objectContaining({ method: "POST", body: JSON.stringify({ reason: "Done." }) }),
    );
    expect(fetchMock).toHaveBeenNthCalledWith(
      2,
      "http://127.0.0.1:8000/api/dashboard/projects/proj-client/restore",
      expect.objectContaining({ method: "POST", body: JSON.stringify({ reason: "Resume." }) }),
    );
    expect(fetchMock).toHaveBeenNthCalledWith(
      3,
      "http://127.0.0.1:8000/api/dashboard/projects/proj-client/delete",
      expect.objectContaining({ method: "POST", body: JSON.stringify({ reason: "Trash." }) }),
    );

    expect(fetchMock.mock.calls.map(([url]) => String(url)).some((url) => url.includes(forbiddenPluginNamespace))).toBe(false);
  });

  it("returns null when the dashboard me endpoint returns a non-user payload", async () => {
    fetchMock.mockResolvedValueOnce({
      ok: true,
      status: 200,
      text: async () => "<!doctype html><html><body>SPA fallback</body></html>",
    } as Response);

    await expect(httpApi.me()).resolves.toBeNull();
  });

  it("uploads task attachments with multipart dashboard endpoints", async () => {
    Object.defineProperty(document, "cookie", {
      configurable: true,
      value: "XSRF-TOKEN=test-token",
    });
    fetchMock.mockResolvedValueOnce(jsonResponse({
      id: "att-1",
      task_id: "task/with space",
      project_id: "proj-core",
      name: "screen.png",
      mime_type: "image/png",
      kind: "image",
      status: "available",
      scan_status: "not_scanned",
      size_bytes: 3,
      uploaded_at: "2026-06-24T12:00:00Z",
      uploaded_by: "PM",
      download_url: "/api/dashboard/tasks/task%2Fwith%20space/attachments/att-1/download",
      preview_url: "/api/dashboard/tasks/task%2Fwith%20space/attachments/att-1/download",
    }));

    const file = new File(["png"], "screen.png", { type: "image/png" });
    await (httpApi as any).uploadTaskAttachment("task/with space", file);

    const [url, init] = fetchMock.mock.calls[0];
    const headers = init.headers as Record<string, string>;

    expect(url).toBe("http://127.0.0.1:8000/api/dashboard/tasks/task%2Fwith%20space/attachments");
    expect(init).toEqual(expect.objectContaining({
      method: "POST",
      body: expect.any(FormData),
    }));
    expect(headers["Content-Type"]).toBeUndefined();
    expect(headers.Accept).toBe("application/json");
    expect(headers["X-Requested-With"]).toBe("XMLHttpRequest");
    expect(String(url).includes(forbiddenPluginNamespace)).toBe(false);
  });

  it("soft-deletes task attachments through dashboard API endpoints", async () => {
    Object.defineProperty(document, "cookie", {
      configurable: true,
      value: "XSRF-TOKEN=test-token",
    });
    fetchMock.mockResolvedValueOnce(jsonResponse({
      id: "task/with space",
      attachments: [],
      attachment_count: 0,
      image_attachment_count: 0,
    }));

    await (httpApi as any).deleteTaskAttachment("task/with space", "att/one");

    expect(fetchMock).toHaveBeenCalledWith(
      "http://127.0.0.1:8000/api/dashboard/tasks/task%2Fwith%20space/attachments/att%2Fone",
      expect.objectContaining({ method: "DELETE" }),
    );
    expect(fetchMock.mock.calls.map(([url]) => String(url)).some((url) => url.includes(forbiddenPluginNamespace))).toBe(false);
  });

  it("uses dashboard API endpoints for task assistant clarification lifecycle", async () => {
    Object.defineProperty(document, "cookie", {
      configurable: true,
      value: "XSRF-TOKEN=test-token",
    });
    fetchMock
      .mockResolvedValueOnce(jsonResponse({ run: { id: "run-clarify" }, suggestion: { id: "sugg/one" } }))
      .mockResolvedValueOnce(jsonResponse({ suggestion: { id: "sugg/one", status: "accepted" } }))
      .mockResolvedValueOnce(jsonResponse({ suggestion: { id: "sugg/one", status: "applied" }, task: { id: "task/with space" } }));

    await (httpApi as any).clarifyTask("task/with space");
    await (httpApi as any).resolveAssistantSuggestion("sugg/one", "accepted");
    await (httpApi as any).applyAssistantSuggestion("sugg/one");

    expect(fetchMock).toHaveBeenNthCalledWith(
      1,
      "http://127.0.0.1:8000/api/dashboard/tasks/task%2Fwith%20space/assistant/clarify",
      expect.objectContaining({ method: "POST" }),
    );
    expect(fetchMock).toHaveBeenNthCalledWith(
      2,
      "http://127.0.0.1:8000/api/dashboard/assistant-suggestions/sugg%2Fone",
      expect.objectContaining({
        method: "PATCH",
        body: JSON.stringify({ status: "accepted" }),
      }),
    );
    expect(fetchMock).toHaveBeenNthCalledWith(
      3,
      "http://127.0.0.1:8000/api/dashboard/assistant-suggestions/sugg%2Fone/apply",
      expect.objectContaining({ method: "POST" }),
    );
    expect(fetchMock.mock.calls.map(([url]) => String(url)).some((url) => url.includes(forbiddenPluginNamespace))).toBe(false);
  });

  it("uses dashboard API endpoint for project backlog triage", async () => {
    Object.defineProperty(document, "cookie", {
      configurable: true,
      value: "XSRF-TOKEN=test-token",
    });
    fetchMock.mockResolvedValueOnce(jsonResponse({ run: { id: "run-triage" }, suggestion: { id: "sugg-triage" } }));

    await (httpApi as any).triageProjectBacklog("proj/client");

    expect(fetchMock).toHaveBeenCalledWith(
      "http://127.0.0.1:8000/api/dashboard/projects/proj%2Fclient/assistant/backlog-triage",
      expect.objectContaining({ method: "POST" }),
    );
    expect(fetchMock.mock.calls.map(([url]) => String(url)).some((url) => url.includes(forbiddenPluginNamespace))).toBe(false);
  });

  it("uses dashboard Admin AI registry endpoints for model providers profiles and agents", async () => {
    Object.defineProperty(document, "cookie", {
      configurable: true,
      value: "XSRF-TOKEN=test-token",
    });
    fetchMock
      .mockResolvedValueOnce(jsonResponse({ providers: [], modelProfiles: [], agentProfiles: [] }))
      .mockResolvedValueOnce(jsonResponse({ provider: { id: "provider-openai", provider_key: "openai", api_key_configured: true } }))
      .mockResolvedValueOnce(jsonResponse({ validation: { status: "ready_for_runtime", checked_at: "2026-07-01T00:00:00Z" } }))
      .mockResolvedValueOnce(jsonResponse({ model_profile: { id: "profile-opencode-go", profile_key: "opencode_go_default" } }))
      .mockResolvedValueOnce(jsonResponse({ model_profile: { id: "profile-openai", profile_key: "openai_default_text" } }))
      .mockResolvedValueOnce(jsonResponse({ agent_profile: { id: "agent-task", agent_key: "task_clarifier" } }));

    await (httpApi as any).getAiAgents();
    await (httpApi as any).updateAiModelProvider("openai", {
      display_name: "OpenAI Gateway",
      base_url: "https://api.openai.com/v1",
      api_key: "sk-test",
      clear_api_key: false,
      enabled: true,
    });
    const validation = await (httpApi as any).validateAiModelProvider("openai");
    await (httpApi as any).createAiModelProfile({
      provider_key: "opencode_go",
      profile_key: "opencode_go_default",
      display_name: "OpenCode Go default",
      model_name: "opencode-go",
      runtime_profile: "text",
      max_context: null,
      max_output_tokens: 4096,
      temperature: 0.2,
      timeout_seconds: 60,
      enabled: true,
    });
    await (httpApi as any).updateAiModelProfile("openai_default_text", {
      display_name: "Default text",
      model_name: "gpt-4.1-mini",
      runtime_profile: "text",
      max_context: 128000,
      max_output_tokens: 4096,
      temperature: 0.2,
      timeout_seconds: 60,
      enabled: true,
    });
    await (httpApi as any).updateAiAgentProfile("task_clarifier", {
      default_model_profile_id: "profile-openai",
      enabled: true,
    });

    expect(validation).toEqual({ status: "ready_for_runtime", checked_at: "2026-07-01T00:00:00Z" });
    expect(fetchMock).toHaveBeenNthCalledWith(
      1,
      "http://127.0.0.1:8000/api/dashboard/admin/ai-agents",
      expect.objectContaining({ method: "GET" }),
    );
    expect(fetchMock).toHaveBeenNthCalledWith(
      2,
      "http://127.0.0.1:8000/api/dashboard/admin/ai-model-providers/openai",
      expect.objectContaining({
        method: "PUT",
        body: JSON.stringify({
          display_name: "OpenAI Gateway",
          base_url: "https://api.openai.com/v1",
          api_key: "sk-test",
          clear_api_key: false,
          enabled: true,
        }),
      }),
    );
    expect(fetchMock).toHaveBeenNthCalledWith(
      3,
      "http://127.0.0.1:8000/api/dashboard/admin/ai-model-providers/openai/validate",
      expect.objectContaining({ method: "POST" }),
    );
    expect(fetchMock).toHaveBeenNthCalledWith(
      4,
      "http://127.0.0.1:8000/api/dashboard/admin/ai-model-profiles",
      expect.objectContaining({
        method: "POST",
        body: JSON.stringify({
          provider_key: "opencode_go",
          profile_key: "opencode_go_default",
          display_name: "OpenCode Go default",
          model_name: "opencode-go",
          runtime_profile: "text",
          max_context: null,
          max_output_tokens: 4096,
          temperature: 0.2,
          timeout_seconds: 60,
          enabled: true,
        }),
      }),
    );
    expect(fetchMock).toHaveBeenNthCalledWith(
      5,
      "http://127.0.0.1:8000/api/dashboard/admin/ai-model-profiles/openai_default_text",
      expect.objectContaining({ method: "PUT" }),
    );
    expect(fetchMock).toHaveBeenNthCalledWith(
      6,
      "http://127.0.0.1:8000/api/dashboard/admin/ai-agent-profiles/task_clarifier",
      expect.objectContaining({
        method: "PATCH",
        body: JSON.stringify({
          default_model_profile_id: "profile-openai",
          enabled: true,
        }),
      }),
    );
    expect(fetchMock.mock.calls.map(([url]) => String(url)).some((url) => url.includes(forbiddenPluginNamespace))).toBe(false);
  });

  it("uses dashboard API endpoints for project workspace memory imports", async () => {
    Object.defineProperty(document, "cookie", {
      configurable: true,
      value: "XSRF-TOKEN=test-token",
    });
    fetchMock
      .mockResolvedValueOnce(jsonResponse({ workspace_bindings: [{ id: "binding-source", display_path: "~/src/source" }] }))
      .mockResolvedValueOnce(jsonResponse({ import_batch: { id: "import-1", status: "completed" } }))
      .mockResolvedValueOnce(jsonResponse({ import_batches: [{ id: "import-1", status: "completed" }] }));

    const bindings = await (httpApi as any).getProjectWorkspaceBindings("proj/core");
    const createdImport = await (httpApi as any).createProjectMemoryImport("proj/core", {
      source_workspace_binding_id: "binding-source",
      target_workspace_binding_id: "binding-target",
      mode: "copy_as_proposals",
      filters: { kinds: ["decision"], limit: 25 },
      dedupe_strategy: "summary_payload_hash",
      conflict_policy: "proposal",
      reason: "Seed project context from previous workspace",
    });
    const imports = await (httpApi as any).getProjectMemoryImports("proj/core");

    expect(bindings).toEqual([{ id: "binding-source", display_path: "~/src/source" }]);
    expect(createdImport).toEqual({ id: "import-1", status: "completed" });
    expect(imports).toEqual([{ id: "import-1", status: "completed" }]);
    expect(fetchMock).toHaveBeenNthCalledWith(
      1,
      "http://127.0.0.1:8000/api/dashboard/projects/proj%2Fcore/workspace-bindings",
      expect.objectContaining({ method: "GET" }),
    );
    expect(fetchMock).toHaveBeenNthCalledWith(
      2,
      "http://127.0.0.1:8000/api/dashboard/projects/proj%2Fcore/memory/imports",
      expect.objectContaining({
        method: "POST",
        body: JSON.stringify({
          source_workspace_binding_id: "binding-source",
          target_workspace_binding_id: "binding-target",
          mode: "copy_as_proposals",
          filters: { kinds: ["decision"], limit: 25 },
          dedupe_strategy: "summary_payload_hash",
          conflict_policy: "proposal",
          reason: "Seed project context from previous workspace",
        }),
      }),
    );
    expect(fetchMock).toHaveBeenNthCalledWith(
      3,
      "http://127.0.0.1:8000/api/dashboard/projects/proj%2Fcore/memory/imports",
      expect.objectContaining({ method: "GET" }),
    );
    expect(fetchMock.mock.calls.map(([url]) => String(url)).some((url) => url.includes(forbiddenPluginNamespace))).toBe(false);
  });

  it("uses dashboard system backup endpoints for readiness export download and restore dry-run validation", async () => {
    Object.defineProperty(document, "cookie", {
      configurable: true,
      value: "XSRF-TOKEN=test-token",
    });
    fetchMock
      .mockResolvedValueOnce(jsonResponse({ format: "devboard-backup-v1", can_export: true }))
      .mockResolvedValueOnce(jsonResponse({
        id: "01KVZBACKUP00000000000000",
        download_url: "/api/dashboard/system/backups/01KVZBACKUP00000000000000/download",
      }))
      .mockResolvedValueOnce(jsonResponse({ mode: "dry_run", valid: true, can_restore: true }));

    await (httpApi as any).getBackupReadiness();
    await (httpApi as any).exportBackup();
    await (httpApi as any).validateBackupBundle(new File(["{}"], "devboard-backup.json", { type: "application/json" }));

    const calls = fetchMock.mock.calls;
    expect(calls[0][0]).toBe("http://127.0.0.1:8000/api/dashboard/system/backups/readiness");
    expect(calls[1][0]).toBe("http://127.0.0.1:8000/api/dashboard/system/backups/export");
    expect(calls[1][1]).toEqual(expect.objectContaining({ method: "POST" }));
    expect(calls[2][0]).toBe("http://127.0.0.1:8000/api/dashboard/system/backups/validate");
    expect(calls[2][1]).toEqual(expect.objectContaining({
      method: "POST",
      body: expect.any(FormData),
    }));
    expect((calls[2][1].headers as Record<string, string>)["Content-Type"]).toBeUndefined();
    expect(calls.map(([url]) => String(url)).some((url) => url.includes(forbiddenPluginNamespace))).toBe(false);
  });

  it("normalizes intake text through the project dashboard intake endpoint", async () => {
    Object.defineProperty(document, "cookie", {
      configurable: true,
      value: "XSRF-TOKEN=test-token",
    });
    fetchMock.mockResolvedValueOnce(jsonResponse({
      normalization: {
        task_type: "bug",
        suggested_title: "Login crashes on invalid email",
        suggested_description: "500 error when submitting invalid email on /login.",
        clarifying_questions: ["Which browser?"],
        requires_root_cause: false,
        confidence: 0.87,
        execution_mode: "deterministic_fallback",
      },
    }));

    const response = await (httpApi as any).normalizeIntake("proj-core", "The login page crashes with a 500 error when I submit an invalid email.");

    expect(fetchMock).toHaveBeenCalledWith(
      "http://127.0.0.1:8000/api/dashboard/projects/proj-core/intake/normalize",
      expect.objectContaining({
        method: "POST",
        body: JSON.stringify({ raw_text: "The login page crashes with a 500 error when I submit an invalid email." }),
      }),
    );
    expect(response.normalization.task_type).toBe("bug");
    expect(response.normalization.suggested_title).toBe("Login crashes on invalid email");
    expect(response.normalization.clarifying_questions).toContain("Which browser?");
    expect(response.normalization.execution_mode).toBe("deterministic_fallback");
    expect(fetchMock.mock.calls.map(([url]) => String(url)).some((url) => url.includes(forbiddenPluginNamespace))).toBe(false);
  });
});
