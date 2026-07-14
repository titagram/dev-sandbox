declare const jest: any;
declare const describe: any;
declare const expect: any;
declare const it: any;

jest.mock("@/api/devboardApi", () => ({}), { virtual: true });
jest.mock("@/api/mockData", () => require("./mockData"), { virtual: true });

import { mockApi } from "./mockApi";
import { DashboardGraphResponse } from "@/types/devboard";

function scopeIds(response: DashboardGraphResponse): string[] {
  if (response.query_type !== "scopes") return [];

  return response.items.map((item) => item.source_scope_id);
}

describe("mockApi approved Hades Agent contracts", () => {
  it("serves every bounded dashboard graph query through opaque handles", async () => {
    const scopes = await mockApi.queryProjectGraph("proj-core", { type: "scopes", limit: 1 });
    expect(scopes).toEqual(expect.objectContaining({
      protocol_version: "v1",
      project_id: "proj-core",
      query_type: "scopes",
      found: true,
      returned: 1,
      limit: 1,
      has_more: true,
      truncated: false,
      next_cursor: expect.any(String),
    }));
    expect(scopes.items[0]).toEqual(expect.objectContaining({
      source_scope_type: expect.any(String),
      source_scope_id: expect.any(String),
    }));
    expect(scopeIds(scopes)).toEqual(["repo-api"]);

    const scope = { scope_type: "repository" as const, scope_id: "repo-api" };
    const search = await mockApi.queryProjectGraph("proj-core", {
      type: "search", ...scope, query: "Import", limit: 1,
    });
    if (search.query_type === "scopes") throw new Error("Expected graph-node search results.");
    const selected = search.items[0];
    expect(selected.handle).toMatch(/^gh1_[A-Za-z0-9_-]{43}$/);
    expect(selected).toEqual({
      handle: expect.any(String),
      label: "ImportService",
      kind: "class",
      score: expect.any(Number),
    });
    expect(search.next_cursor).toEqual(expect.any(String));
    expect(search).toEqual(expect.objectContaining({ has_more: true, truncated: false }));

    const detail = await mockApi.queryProjectGraph("proj-core", {
      type: "detail", ...scope, node_handle: selected.handle,
    });
    const neighborhood = await mockApi.queryProjectGraph("proj-core", {
      type: "neighborhood", ...scope, node_handle: selected.handle, direction: "any",
      families: ["call", "dependency", "route", "test", "table"],
    });
    const path = await mockApi.queryProjectGraph("proj-core", {
      type: "path", ...scope, from_handle: selected.handle, to_handle: `gh1_${"b".repeat(43)}`,
    });
    const impact = await mockApi.queryProjectGraph("proj-core", {
      type: "impact", ...scope, node_handle: selected.handle, max_depth: 2, limit: 2,
    });

    expect(search.query_type).toBe("search");
    expect(detail).toEqual(expect.objectContaining({
      query_type: "detail", found: true, reason: null, items: [],
      node: { handle: selected.handle, label: "ImportService", kind: "class" },
    }));
    expect(path).toEqual(expect.objectContaining({ query_type: "path", found: true, reason: null }));
    expect(neighborhood.edges.length).toBeGreaterThan(0);
    expect(neighborhood.edges[0]).toEqual({
      from_handle: expect.any(String),
      to_handle: expect.any(String),
      edge_type: expect.any(String),
      family: expect.any(String),
    });
    const routeNeighborhood = await mockApi.queryProjectGraph("proj-core", {
      type: "neighborhood", ...scope, node_handle: `gh1_${"b".repeat(43)}`, direction: "any",
    });
    expect([...neighborhood.edges, ...routeNeighborhood.edges].map((edge) => edge.edge_type)).toEqual(expect.arrayContaining([
      "CALLS_METHOD", "USES_DEPENDENCY", "ROUTE_HANDLER", "TEST_COVERS_SYMBOL", "QUERY_TABLE",
    ]));
    expect(impact).toEqual(expect.objectContaining({
      query_type: "impact",
      returned: 2,
      limit: 2,
      has_more: false,
      truncated: true,
      next_cursor: null,
    }));
    expect(impact.items[0]).toEqual(expect.objectContaining({
      handle: expect.any(String), family: expect.any(String), why: expect.any(String),
      distance: 1, edge_types: expect.any(Array),
    }));
  });

  it("rejects requests with the same validation reasons and status classes as the controller", async () => {
    const scope = { scope_type: "repository" as const, scope_id: "repo-api" };
    const validHandle = `gh1_${"a".repeat(43)}`;

    await expect(mockApi.queryProjectGraph("proj-core", { type: "overview" }))
      .rejects.toEqual(expect.objectContaining({ message: "scope_required", code: "422" }));
    await expect(mockApi.queryProjectGraph("proj-core", { type: "overview", ...scope, limit: 51 }))
      .rejects.toEqual(expect.objectContaining({ message: "validation_failed", code: "422" }));
    await expect(mockApi.queryProjectGraph("proj-core", { type: "scopes", limit: 101 }))
      .rejects.toEqual(expect.objectContaining({ message: "validation_failed", code: "422" }));
    await expect(mockApi.queryProjectGraph("proj-core", { type: "search", ...scope, query: "" }))
      .rejects.toEqual(expect.objectContaining({ message: "validation_failed", code: "422" }));
    await expect(mockApi.queryProjectGraph("proj-core", { type: "search", ...scope, query: "   " }))
      .rejects.toEqual(expect.objectContaining({ message: "invalid_query", code: "422" }));
    await expect(mockApi.queryProjectGraph("proj-core", {
      type: "neighborhood", ...scope, node_handle: validHandle, direction: "sideways",
    } as any)).rejects.toEqual(expect.objectContaining({ message: "validation_failed", code: "422" }));
    await expect(mockApi.queryProjectGraph("proj-core", {
      type: "neighborhood", ...scope, node_handle: validHandle, families: ["mystery"],
    } as any)).rejects.toEqual(expect.objectContaining({ message: "validation_failed", code: "422" }));
    await expect(mockApi.queryProjectGraph("proj-core", {
      type: "overview", ...scope, internal_id: "must-not-cross-dashboard-boundary",
    } as any)).rejects.toEqual(expect.objectContaining({ message: "validation_failed", code: "422" }));
    await expect(mockApi.queryProjectGraph("proj-core", {
      type: "detail", ...scope, node_handle: "preview-node-id",
    })).rejects.toEqual(expect.objectContaining({ message: "invalid_handle", code: "422" }));
    await expect(mockApi.queryProjectGraph("proj-core", {
      type: "overview", ...scope, cursor: "not-allowed",
    })).rejects.toEqual(expect.objectContaining({ message: "validation_failed", code: "422" }));
    await expect(mockApi.queryProjectGraph("proj-core", {
      type: "scopes", cursor: "tampered-cursor",
    })).rejects.toEqual(expect.objectContaining({ message: "invalid_cursor", code: "422" }));
    await expect(mockApi.queryProjectGraph("proj-core", {
      type: "impact", ...scope, node_handle: validHandle, max_depth: 1,
    })).rejects.toEqual(expect.objectContaining({ message: "validation_failed", code: "422" }));
  });

  it("binds pagination cursors to their project, scope, normalized query, and query type", async () => {
    const repositoryScope = { scope_type: "repository" as const, scope_id: "repo-api" };
    const bindingScope = { scope_type: "workspace_binding" as const, scope_id: "binding-core-api" };
    const firstSearchPage = await mockApi.queryProjectGraph("proj-core", {
      type: "search", ...repositoryScope, query: "Import", limit: 1,
    });
    if (firstSearchPage.query_type === "scopes" || !firstSearchPage.next_cursor) {
      throw new Error("Expected a paginated search response.");
    }

    const normalizedContinuation = await mockApi.queryProjectGraph("proj-core", {
      type: "search", ...repositoryScope, query: "  Import  ", limit: 1,
      cursor: firstSearchPage.next_cursor,
    });
    expect(normalizedContinuation.query_type).toBe("search");

    await expect(mockApi.queryProjectGraph("proj-pay", {
      type: "search", ...repositoryScope, query: "Import", limit: 1,
      cursor: firstSearchPage.next_cursor,
    })).rejects.toEqual(expect.objectContaining({ message: "invalid_cursor", code: "422" }));
    await expect(mockApi.queryProjectGraph("proj-core", {
      type: "search", ...bindingScope, query: "Import", limit: 1,
      cursor: firstSearchPage.next_cursor,
    })).rejects.toEqual(expect.objectContaining({ message: "invalid_cursor", code: "422" }));
    await expect(mockApi.queryProjectGraph("proj-core", {
      type: "search", ...repositoryScope, query: "Artifact", limit: 1,
      cursor: firstSearchPage.next_cursor,
    })).rejects.toEqual(expect.objectContaining({ message: "invalid_cursor", code: "422" }));
    await expect(mockApi.queryProjectGraph("proj-core", {
      type: "scopes", limit: 1, cursor: firstSearchPage.next_cursor,
    })).rejects.toEqual(expect.objectContaining({ message: "invalid_cursor", code: "422" }));

    const firstScopesPage = await mockApi.queryProjectGraph("proj-core", { type: "scopes", limit: 1 });
    if (firstScopesPage.query_type !== "scopes" || !firstScopesPage.next_cursor) {
      throw new Error("Expected a paginated scopes response.");
    }
    await expect(mockApi.queryProjectGraph("proj-pay", {
      type: "scopes", limit: 1, cursor: firstScopesPage.next_cursor,
    })).rejects.toEqual(expect.objectContaining({ message: "invalid_cursor", code: "422" }));
  });

  it("returns coherent graph outcomes for empty searches and unknown well-formed handles", async () => {
    const scope = { scope_type: "repository" as const, scope_id: "repo-api" };
    const missingQuery = await mockApi.queryProjectGraph("proj-core", {
      type: "search", ...scope, query: "NoSuchSymbol",
    });

    expect(missingQuery).toEqual(expect.objectContaining({ found: true, reason: null, returned: 0 }));
    await expect(mockApi.queryProjectGraph("proj-core", {
      type: "detail", ...scope, node_handle: `gh1_${"z".repeat(43)}`,
    })).rejects.toEqual(expect.objectContaining({ message: "node_not_found", code: "404" }));
  });

  it("uses the complete backend capability catalog for defaults and preserves an explicit empty grant", async () => {
    const expected = [
      "read_files",
      "read_source_slice",
      "project_inspection",
      "sync_git_tree",
      "populate_backend_ast",
      "populate_project_wiki",
    ];
    const snapshot = await mockApi.getHadesAdmin();
    expect(snapshot.supported_capabilities).toEqual(expected);

    const defaultToken = await mockApi.createHadesBootstrapToken({
      project_id: "proj-core",
      name: "Default catalog",
    });
    expect(defaultToken.token.allowed_capabilities).toEqual(expected);

    const emptyToken = await mockApi.createHadesBootstrapToken({
      project_id: "proj-core",
      name: "Empty grant",
      allowed_capabilities: [],
    });
    expect(emptyToken.token.allowed_capabilities).toEqual([]);
  });

  it("creates manual project memory as user_inserted", async () => {
    await mockApi.login({ email: "pm@devboard.local", password: "demo", role: "pm" });

    const entry = await mockApi.createProjectMemory("proj-core", {
      kind: "decision",
      summary: "Keep wiki refresh requests manual until backend confirmation exists.",
      payload: {
        note: "Manual PM context for Hades.",
        created_from: "dashboard_memory_form",
      },
    });

    expect(entry.source).toBe("user_inserted");
    expect(entry.agent_key).toBeNull();
    expect(entry.payload.created_from).toBe("dashboard_memory_form");

    const updated = await mockApi.updateProjectMemory("proj-core", entry.id, {
      repository_id: null,
      task_id: null,
      run_id: null,
      kind: "verification",
      completeness: "incomplete",
      summary: "Manual project memory update remains user-owned.",
      payload: { note: "Edited by user." },
    });

    expect(updated.kind).toBe("verification");
    expect(updated.source).toBe("user_inserted");
    expect(updated.agent_key).toBeNull();
  });

  it("serves synthetic workspace bindings and memory import batches", async () => {
    const bindings = await (mockApi as any).getProjectWorkspaceBindings("proj-core");

    expect(bindings.length).toBeGreaterThan(0);
    expect(bindings[0]).toEqual(expect.objectContaining({
      id: expect.any(String),
      display_path: expect.any(String),
      memory_counts: expect.any(Object),
    }));

    const batch = await (mockApi as any).createProjectMemoryImport("proj-core", {
      source_workspace_binding_id: bindings[0].id,
      target_workspace_binding_id: bindings[0].id,
      mode: "copy_as_proposals",
      filters: { kinds: ["decision"], limit: 10 },
      dedupe_strategy: "summary_payload_hash",
      conflict_policy: "proposal",
      reason: "Import previous workspace decisions.",
    });
    const imports = await (mockApi as any).getProjectMemoryImports("proj-core");

    expect(batch.status).toBe("completed");
    expect(batch.review_status).toBe("review_pending");
    expect(imports[0].id).toBe(batch.id);
    expect(imports[0].source_workspace_binding_id).toBe(bindings[0].id);

    const detail = await (mockApi as any).getProjectMemoryImport("proj-core", batch.id);
    expect(detail.items.some((item: any) => item.status === "proposal_created")).toBe(true);

    const cancelled = await (mockApi as any).cancelProjectMemoryImport("proj-core", batch.id, "No longer needed.");
    expect(cancelled.status).toBe("cancelled");
    expect(cancelled.review_status).toBe("cancelled");
    expect(cancelled.items.some((item: any) => item.status === "proposal_cancelled")).toBe(true);
  });

  it("creates and continues persistent local-agent chat threads backed by work items", async () => {
    await mockApi.login({ email: "dev@devboard.local", password: "demo", role: "developer" });

    const created = await (mockApi as any).createAgentChat("proj-core", {
      agent_key: "local_agent",
      repository_id: "repo-web",
      task_id: "CORE-102",
      initial_message: "Inspect this before editing.",
      metadata: { source: "test" },
    });
    const continued = await (mockApi as any).sendAgentChatMessage("proj-core", created.thread.id, {
      content: "Add one more check.",
    });
    const work = await mockApi.getAgentWork("proj-core");
    const chatWork = work.items.find((item) => item.payload.agent_chat_thread_id === created.thread.id);

    expect(created.thread.status).toBe("pending_local_agent");
    expect(continued.thread.messages.map((message: any) => message.role)).toEqual(["user", "user"]);
    expect(chatWork?.payload.schema).toBe("devboard.agent_chat_turn.v1");
    expect(chatWork?.payload.agent_chat_thread_id).toBe(created.thread.id);
  });

  it("supports OpenCode Go provider validation and model profile creation without exposing full keys", async () => {
    const provider = await mockApi.updateAiModelProvider("opencode_go", {
      display_name: "OpenCode Go",
      base_url: "http://127.0.0.1:4096/v1",
      api_key: "opencode-go-secret-1234",
      clear_api_key: false,
      enabled: true,
    });

    expect((provider as any).api_key).toBeUndefined();
    expect(provider.api_key_configured).toBe(true);
    expect(provider.api_key_last_four).toBe("1234");

    const validation = await (mockApi as any).validateAiModelProvider("opencode_go");
    const profile = await (mockApi as any).createAiModelProfile({
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

    expect(validation.status).toBe("valid");
    expect(JSON.stringify(validation)).not.toContain("opencode-go-secret-1234");
    expect(profile.provider_key).toBe("opencode_go");
    expect(profile.profile_key).toBe("opencode_go_default");
  });
});
