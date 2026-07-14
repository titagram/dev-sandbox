declare const jest: any;
declare const describe: any;
declare const expect: any;
declare const it: any;

jest.mock("@/api/devboardApi", () => ({}), { virtual: true });
jest.mock("@/api/mockData", () => require("./mockData"), { virtual: true });

import { mockApi } from "./mockApi";

describe("mockApi approved DevBoard Hades contracts", () => {
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
