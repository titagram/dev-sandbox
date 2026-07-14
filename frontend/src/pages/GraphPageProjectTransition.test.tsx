import React, { act } from "react";
import { createRoot, Root } from "react-dom/client";

declare const jest: any;
declare const beforeEach: any;
declare const afterEach: any;
declare const describe: any;
declare const it: any;
declare const expect: any;

(globalThis as any).IS_REACT_ACT_ENVIRONMENT = true;

jest.mock("@/api/devboardApi", () => ({ api: {
  getProjects: require("jest-mock").fn(),
  getGraph: require("jest-mock").fn(),
  queryProjectGraph: require("jest-mock").fn(),
} }), { virtual: true });
jest.mock("@/hooks/useApi", () => require("../hooks/useApi"), { virtual: true });
jest.mock("react-router-dom", () => {
  const state = {
    params: { projectId: "project-old" },
    search: new URLSearchParams("run_id=run-7&snapshot_id=snapshot-9&scope_type=repository&scope_id=shared-repo&symbol=opaque-symbol"),
  };
  return {
    Link: ({ children }: any) => children,
    __state: state,
    useNavigate: () => require("jest-mock").fn(),
    useParams: () => state.params,
    useSearchParams: () => [state.search, require("jest-mock").fn()],
  };
}, { virtual: true });
jest.mock("@/components/devboard/GraphExplorer", () => {
  const seen: any[] = [];
  const Component = (props: any) => { seen.push(props); return <div>Explorer {props.projectId}</div>; };
  (Component as any).__seen = seen;
  return Component;
}, { virtual: true });
jest.mock("@/components/devboard/DataState", () => ({
  DataState: ({ state, children }: any) => state.data ? children(state.data) : <div>Loading</div>,
}), { virtual: true });
jest.mock("@/components/devboard/Badges", () => ({ SourceMetaInline: () => <span>Source</span> }), { virtual: true });
jest.mock("@/components/devboard/Layout", () => ({
  PageHeader: ({ title }: any) => <header>{title}</header>,
  Panel: ({ title, children }: any) => <section><h2>{title}</h2>{children}</section>,
  MetricCard: ({ label, value }: any) => <span>{label} {value}</span>,
}), { virtual: true });
jest.mock("@/lib/format", () => ({ relativeTime: (value: string) => value }), { virtual: true });
jest.mock("lucide-react", () => ({
  Boxes: () => null,
  Inbox: () => null,
  Loader2: () => null,
  RefreshCw: () => null,
  TriangleAlert: () => null,
}), { virtual: true });

import GraphPage from "./GraphPage";
import { api } from "@/api/devboardApi";

const mockGetGraph = api.getGraph as any;
const mockQueryProjectGraph = api.queryProjectGraph as any;
const mockRouterState = require("react-router-dom").__state as any;
const mockGraphExplorerSeen = require("@/components/devboard/GraphExplorer").__seen as any[];

function deferred<T>() {
  let resolve!: (value: T) => void;
  const promise = new Promise<T>((yes) => { resolve = yes; });
  return { promise, resolve };
}

function graph(projectId: string) {
  return {
    generated_at: "2026-07-15T10:00:00Z",
    source: { type: "canonical_graph", status: "verified_from_code", origin: "canonical projection" },
    stats: { nodes: projectId === "project-old" ? 1 : 2, edges: 0, modules: 1, routes: 0 },
    nodes: [], edges: [], quality: "complete", projection_status: "ready",
  };
}

function scopes(projectId: string, scopeId: string) {
  return {
    protocol_version: "v1", project_id: projectId, query_type: "scopes", found: true, reason: null, scope: null,
    projection: { status: "ready", quality: "complete", generated_at: "2026-07-15T10:00:00Z", active_graph_version: "v1", node_count: 1, relationship_count: 0, unknown_kind_count: 0, missing_label_count: 0, excluded_node_count: 0 },
    node: null, items: [{ source_scope_type: "repository", source_scope_id: scopeId, status: "ready", quality: "complete" }],
    edges: [], returned: 1, limit: 100, next_cursor: null, has_more: false, truncated: false,
    source: { type: "canonical_graph", status: "verified_from_code", origin: "canonical projection" },
  };
}

let container: HTMLDivElement;
let root: Root;

async function settle() {
  await act(async () => { await new Promise((resolve) => setTimeout(resolve, 0)); });
}

describe("GraphPage project transition", () => {
  beforeEach(() => {
    container = document.createElement("div");
    document.body.appendChild(container);
    root = createRoot(container);
    mockGetGraph.mockReset();
    mockQueryProjectGraph.mockReset();
    mockGraphExplorerSeen.splice(0);
    mockRouterState.params = { projectId: "project-old" };
    mockRouterState.search = new URLSearchParams("run_id=run-7&snapshot_id=snapshot-9&scope_type=repository&scope_id=shared-repo&symbol=opaque-symbol");
  });

  afterEach(() => { act(() => root.unmount()); container.remove(); });

  it("does not combine stale useApi data with a new project query while requests are unresolved", async () => {
    const oldGraph = deferred<any>();
    const oldScopes = deferred<any>();
    const newGraph = deferred<any>();
    const newScopes = deferred<any>();
    mockGetGraph.mockImplementation((projectId: string) => projectId === "project-old" ? oldGraph.promise : newGraph.promise);
    mockQueryProjectGraph.mockImplementation((projectId: string, request: any) => {
      if (request.type !== "scopes") throw new Error("Unexpected data query");
      return projectId === "project-old" ? oldScopes.promise : newScopes.promise;
    });

    await act(async () => { root.render(<GraphPage />); });
    await act(async () => {
      oldGraph.resolve(graph("project-old"));
      oldScopes.resolve(scopes("project-old", "shared-repo"));
      await Promise.resolve();
    });
    await settle();
    const oldRenderCount = mockGraphExplorerSeen.length;
    expect(mockGraphExplorerSeen[oldRenderCount - 1]).toEqual(expect.objectContaining({
      projectId: "project-old",
      scopes: [expect.objectContaining({ source_scope_id: "shared-repo" })],
    }));

    mockRouterState.params = { projectId: "project-new" };
    await act(async () => { root.render(<GraphPage />); });
    await settle();

    expect(mockGetGraph).toHaveBeenCalledWith("project-new", { runId: "run-7", snapshotId: "snapshot-9" });
    expect(mockQueryProjectGraph).toHaveBeenCalledWith("project-new", { type: "scopes", limit: 100 });
    expect(mockGraphExplorerSeen).toHaveLength(oldRenderCount);

    await act(async () => {
      newGraph.resolve(graph("project-new"));
      newScopes.resolve(scopes("project-new", "new-repo"));
      await Promise.resolve();
    });
    await settle();
    expect(mockGraphExplorerSeen[mockGraphExplorerSeen.length - 1]).toEqual(expect.objectContaining({
      projectId: "project-new",
      scopes: [expect.objectContaining({ source_scope_id: "new-repo" })],
      initialScopeType: "repository",
      initialScopeId: "shared-repo",
      initialSymbol: "opaque-symbol",
    }));
  });
});
