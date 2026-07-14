import React, { act } from "react";
import { createRoot, Root } from "react-dom/client";

declare const jest: any;
declare const beforeEach: any;
declare const afterEach: any;
declare const describe: any;
declare const it: any;
declare const expect: any;

(globalThis as any).IS_REACT_ACT_ENVIRONMENT = true;

jest.mock("@/api/devboardApi", () => ({
  api: {
    getHadesAdmin: require("jest-mock").fn(),
    createHadesBootstrapToken: require("jest-mock").fn(),
    revokeHadesBootstrapToken: require("jest-mock").fn(),
    createHadesJob: require("jest-mock").fn(),
    reviewHadesMemoryProposal: require("jest-mock").fn(),
  },
}), { virtual: true });

jest.mock("@/hooks/useApi", () => {
  const React = require("react");
  return {
    useApi: (fn: () => Promise<unknown>) => {
      const [data, setData] = React.useState(null);
      const [error, setError] = React.useState(null);
      const [reloadCount, setReloadCount] = React.useState(0);
      React.useEffect(() => {
        fn().then(setData).catch(setError);
      }, [reloadCount]);
      return { data, loading: data === null && error === null, error, reload: () => setReloadCount((count: number) => count + 1) };
    },
  };
}, { virtual: true });

jest.mock("@/components/devboard/DataState", () => {
  const React = require("react");
  return {
    DataState: ({ state, children }: any) => state.data ? children(state.data) : React.createElement("div", null, "Loading"),
    EmptyState: ({ title }: any) => React.createElement("div", null, title),
  };
}, { virtual: true });

jest.mock("@/components/devboard/Layout", () => {
  const React = require("react");
  return {
    PageHeader: ({ title, children, actions }: any) => React.createElement("header", null, title, actions, children),
    Panel: ({ title, children }: any) => React.createElement("section", null, title, children),
  };
}, { virtual: true });

jest.mock("@/components/devboard/Badges", () => {
  const React = require("react");
  return { Pill: ({ children }: any) => React.createElement("span", null, children) };
}, { virtual: true });

jest.mock("@/components/ui/button", () => {
  const React = require("react");
  return { Button: ({ children, ...props }: any) => React.createElement("button", props, children) };
}, { virtual: true });

jest.mock("@/components/ui/input", () => {
  const React = require("react");
  return { Input: (props: any) => React.createElement("input", props) };
}, { virtual: true });

jest.mock("@/components/ui/label", () => {
  const React = require("react");
  return { Label: ({ children, ...props }: any) => React.createElement("label", props, children) };
}, { virtual: true });

jest.mock("@/components/ui/textarea", () => {
  const React = require("react");
  return { Textarea: (props: any) => React.createElement("textarea", props) };
}, { virtual: true });

jest.mock("@/components/devboard/ConfirmDialog", () => ({ ConfirmDialog: () => null }), { virtual: true });
jest.mock("@/lib/format", () => ({ relativeTime: (value: string) => value }), { virtual: true });

import { api } from "@/api/devboardApi";
import HadesAdminPage from "./HadesAdminPage";

const supportedCapabilities = [
  "read_files",
  "read_source_slice",
  "project_inspection",
  "sync_git_tree",
  "populate_backend_ast",
  "populate_project_wiki",
];

const snapshot = {
  supported_capabilities: supportedCapabilities,
  projects: [{ id: "project-1", name: "Project One", slug: "project-one" }],
  bootstrapTokens: [],
  workspaces: [{
    id: "workspace-1",
    project_id: "project-1",
    project_name: "Project One",
    display_path: "~/work/project-one",
    agent_label: "agent-one",
    status: "linked",
    declared_capabilities: supportedCapabilities,
    effective_capabilities: ["read_files"],
    updated_at: "2026-07-14T12:00:00Z",
  }],
  jobs: [{
    id: "job-1",
    capability: "read_files",
    status: "queued",
    policy: "manual_review",
    created_at: "2026-07-14T12:00:00Z",
  }],
  memoryProposals: [],
};

let container: HTMLDivElement;
let root: Root;

async function mountPage(responses = [snapshot]) {
  const queuedResponses = [...responses];
  (api.getHadesAdmin as any).mockImplementation(() => Promise.resolve(
    queuedResponses.shift() ?? responses[responses.length - 1] ?? snapshot,
  ));
  (api.createHadesBootstrapToken as any).mockResolvedValue({
    plain_token: "hades_bootstrap_test|secret",
    token: { id: "token-1", allowed_capabilities: supportedCapabilities },
    install: { posix: "install", windows: "install" },
  });

  await act(async () => {
    root.render(<HadesAdminPage />);
    await new Promise((resolve) => setTimeout(resolve, 0));
  });
  await act(async () => {
    await new Promise((resolve) => setTimeout(resolve, 0));
  });
}

async function refreshPage() {
  const refreshButton = Array.from(container.querySelectorAll("button")).find((button) => button.textContent?.includes("Refresh"));
  await act(async () => {
    (refreshButton as HTMLButtonElement).click();
    await new Promise((resolve) => setTimeout(resolve, 0));
  });
  await act(async () => {
    await new Promise((resolve) => setTimeout(resolve, 0));
  });
}

function capabilityButton(capability: string): HTMLButtonElement {
  const button = Array.from(container.querySelectorAll("button")).find((candidate) => candidate.textContent === capability);
  if (!(button instanceof HTMLButtonElement)) throw new Error(`Missing capability button: ${capability}`);
  return button;
}

describe("HadesAdminPage capability selection", () => {
  beforeEach(() => {
    container = document.createElement("div");
    document.body.appendChild(container);
    root = createRoot(container);
    (api.getHadesAdmin as any).mockReset();
    (api.createHadesBootstrapToken as any).mockReset();
  });

  afterEach(() => {
    act(() => root.unmount());
    container.remove();
  });

  it("selects and submits the complete backend catalog once it loads", async () => {
    await mountPage();

    expect(supportedCapabilities.map((capability) => capabilityButton(capability))).toHaveLength(6);
    supportedCapabilities.forEach((capability) => {
      expect(capabilityButton(capability).className).toContain("bg-primary");
    });

    const createButton = Array.from(container.querySelectorAll("button")).find((button) => button.textContent?.includes("Create"));
    await act(async () => {
      (createButton as HTMLButtonElement).click();
      await Promise.resolve();
    });

    expect((api.createHadesBootstrapToken as any).mock.calls[0][0].allowed_capabilities)
      .toEqual(supportedCapabilities);
  });

  it("submits an intentionally empty capability grant without restoring selections", async () => {
    await mountPage();

    await act(async () => {
      supportedCapabilities.forEach((capability) => capabilityButton(capability).click());
    });

    supportedCapabilities.forEach((capability) => {
      expect(capabilityButton(capability).className).not.toContain("bg-primary");
    });

    const createButton = Array.from(container.querySelectorAll("button")).find((button) => button.textContent?.includes("Create"));
    await act(async () => {
      (createButton as HTMLButtonElement).click();
      await Promise.resolve();
    });

    expect((api.createHadesBootstrapToken as any).mock.calls[0][0].allowed_capabilities).toEqual([]);
  });

  it("keeps project and workspace fields visible in the generic tables", async () => {
    await mountPage();

    expect(container.textContent).toContain("Project One");
    expect(container.textContent).toContain("~/work/project-one");
    expect(container.textContent).toContain("agent-one");
    expect(container.textContent).toContain("job-1");
    expect(container.textContent).not.toContain("None");
  });

  it("adopts a changed backend catalog on refresh while untouched", async () => {
    const refreshed = { ...snapshot, supported_capabilities: ["read_files", "project_inspection"] };
    await mountPage([snapshot, refreshed]);

    await refreshPage();

    expect(Array.from(container.querySelectorAll("button")).filter((button) => button.className.includes("bg-primary")))
      .toHaveLength(2);
    expect(capabilityButton("read_files").className).toContain("bg-primary");
    expect(capabilityButton("project_inspection").className).toContain("bg-primary");

    const createButton = Array.from(container.querySelectorAll("button")).find((button) => button.textContent?.includes("Create"));
    await act(async () => {
      (createButton as HTMLButtonElement).click();
      await Promise.resolve();
    });
    expect((api.createHadesBootstrapToken as any).mock.calls[0][0].allowed_capabilities)
      .toEqual(refreshed.supported_capabilities);
  });

  it("filters removed capabilities after a touched selection on refresh", async () => {
    const refreshed = {
      ...snapshot,
      supported_capabilities: ["read_files", "read_source_slice", "sync_git_tree", "populate_backend_ast"],
    };
    await mountPage([snapshot, refreshed]);

    await act(async () => {
      capabilityButton("populate_project_wiki").click();
    });
    await refreshPage();

    ["read_files", "read_source_slice", "sync_git_tree", "populate_backend_ast"].forEach((capability) => {
      expect(capabilityButton(capability).className).toContain("bg-primary");
    });

    const expectedSelection = ["read_files", "read_source_slice", "sync_git_tree", "populate_backend_ast"];
    const createButton = Array.from(container.querySelectorAll("button")).find((button) => button.textContent?.includes("Create"));
    await act(async () => {
      (createButton as HTMLButtonElement).click();
      await Promise.resolve();
    });
    expect((api.createHadesBootstrapToken as any).mock.calls[0][0].allowed_capabilities)
      .toEqual(expectedSelection);
  });

  it("preserves a deliberate deny-all selection through a catalog refresh", async () => {
    await mountPage([snapshot, snapshot]);

    await act(async () => {
      supportedCapabilities.forEach((capability) => capabilityButton(capability).click());
    });
    await refreshPage();

    supportedCapabilities.forEach((capability) => {
      expect(capabilityButton(capability).className).not.toContain("bg-primary");
    });
    const createButton = Array.from(container.querySelectorAll("button")).find((button) => button.textContent?.includes("Create"));
    await act(async () => {
      (createButton as HTMLButtonElement).click();
      await Promise.resolve();
    });
    expect((api.createHadesBootstrapToken as any).mock.calls[0][0].allowed_capabilities).toEqual([]);
  });

  it("shows an upgrade state and disables token creation for a legacy snapshot", async () => {
    await mountPage([{ ...snapshot, supported_capabilities: undefined }]);

    expect(container.textContent).toContain("Backend upgrade required");
    const createButton = Array.from(container.querySelectorAll("button")).find((button) => button.textContent?.includes("Create"));
    expect((createButton as HTMLButtonElement).disabled).toBe(true);
  });

  it("distinguishes unreported, legacy-all, and explicit-none grants", async () => {
    const grantSnapshot: any = {
      ...snapshot,
      bootstrapTokens: [
        { id: "token-undefined", project_id: "project-1", project_name: "Project One", token_prefix: "undefined", name: "Undefined", allowed_capabilities: undefined },
        { id: "token-null", project_id: "project-1", project_name: "Project One", token_prefix: "null", name: "Legacy all", allowed_capabilities: null },
        { id: "token-empty", project_id: "project-1", project_name: "Project One", token_prefix: "empty", name: "Deny all", allowed_capabilities: [] },
      ],
      workspaces: [
        { ...snapshot.workspaces[0], id: "workspace-undefined", declared_capabilities: undefined, effective_capabilities: undefined },
        { ...snapshot.workspaces[0], id: "workspace-null", declared_capabilities: null, effective_capabilities: null },
        { ...snapshot.workspaces[0], id: "workspace-empty", declared_capabilities: [], effective_capabilities: [] },
      ],
    };
    await mountPage([grantSnapshot]);

    expect(container.textContent).toContain("Not reported");
    expect(container.textContent).toContain("All supported (default)");
    expect(container.textContent).toContain("None");
  });
});
