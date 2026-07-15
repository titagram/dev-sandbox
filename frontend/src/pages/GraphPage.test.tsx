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
jest.mock("react-router-dom", () => {
  const state = { params: {}, search: new URLSearchParams() };
  const navigate = require("jest-mock").fn();
  const setSearch = require("jest-mock").fn((next: URLSearchParams | ((current: URLSearchParams) => URLSearchParams)) => {
    state.search = typeof next === "function" ? next(state.search) : next;
  });
  return {
    Link: ({ children }: any) => children,
    __state: state,
    __navigate: navigate,
    __setSearch: setSearch,
    useNavigate: () => navigate,
    useParams: () => state.params,
    useSearchParams: () => [state.search, (next: any, options?: any) => setSearch(next, options)],
  };
}, { virtual: true });
jest.mock("@/hooks/useApi", () => {
  const React = require("react");
  return { useApi: (fn: () => Promise<unknown>) => {
    const [state, setState] = React.useState({ data: null, loading: true, error: null });
    React.useEffect(() => { fn().then((data) => setState({ data, loading: false, error: null })); }, []);
    return { ...state, reload: require("jest-mock").fn() };
  } };
}, { virtual: true });
jest.mock("@/components/devboard/DataState", () => ({ DataState: ({ state, children }: any) => state.data ? children(state.data) : <div>Loading</div> }), { virtual: true });
jest.mock("@/components/devboard/GraphExplorer", () => {
  const seen: any[] = [];
  const Component = (props: any) => {
    seen.push(props);
    return <button onClick={() => props.onQueryParamsChange({ scope_type: "repository", scope_id: "repo-1", symbol: "opaque-symbol" })}>Explorer</button>;
  };
  (Component as any).__seen = seen;
  return Component;
}, { virtual: true });
jest.mock("@/components/devboard/Badges", () => ({ SourceMetaInline: () => <span>Source</span> }), { virtual: true });
jest.mock("@/components/devboard/Layout", () => ({
  PageHeader: ({ title }: any) => <header>{title}</header>,
  Panel: ({ title, children }: any) => <section><h2>{title}</h2>{children}</section>,
  MetricCard: ({ label, value }: any) => <span>{label} {value}</span>,
}), { virtual: true });
jest.mock("@/lib/format", () => ({ relativeTime: (value: string) => value }), { virtual: true });
jest.mock("lucide-react", () => ({ Boxes: () => null }), { virtual: true });

import GraphPage from "./GraphPage";
import { api } from "@/api/devboardApi";

const mockGetProjects = api.getProjects as any;
const mockGetGraph = api.getGraph as any;
const mockQueryProjectGraph = api.queryProjectGraph as any;
const mockNavigate = require("react-router-dom").__navigate as any;
const mockRouterState = require("react-router-dom").__state as any;
const mockSetSearch = require("react-router-dom").__setSearch as any;
const mockGraphExplorerSeen = require("@/components/devboard/GraphExplorer").__seen as any[];

const project = {
  id: "project-1", key: "P1", name: "Project One", description: "", owner: "admin", repository_count: 1,
  open_tasks: 0, risk_level: "low", wiki_freshness: "passed", genesis_status: "passed", delta_status: "passed",
  graph_status: "passed", updated_at: "2026-07-14T10:00:00Z", status: "active", archived_at: null, deleted_at: null, restored_at: null,
};
const graph = {
  generated_at: "2026-07-14T10:00:00Z", source: { type: "canonical_graph", status: "verified_from_code", origin: "canonical projection", generated_at: "2026-07-14T10:00:00Z" },
  stats: { nodes: 4, edges: 3, modules: 1, routes: 1 }, nodes: [], edges: [], quality: "complete", projection_status: "ready",
};
const scopeResponse = {
  protocol_version: "v1", project_id: "project-1", query_type: "scopes", found: true, reason: null, scope: null,
  projection: { status: "unavailable", quality: null, generated_at: null, active_graph_version: null, node_count: 0, relationship_count: 0, unknown_kind_count: 0, missing_label_count: 0, excluded_node_count: 0 },
  node: null, items: [{ source_scope_type: "repository", source_scope_id: "repo-1", status: "ready", quality: "complete" }], edges: [], returned: 1, limit: 100,
  next_cursor: null, has_more: false, truncated: false, source: { type: "canonical_graph", status: "verified_from_code", origin: "canonical projection" },
};

let container: HTMLDivElement;
let root: Root;

async function settle() { await act(async () => { await new Promise((resolve) => setTimeout(resolve, 0)); }); }

describe("GraphPage global project selection", () => {
  beforeEach(() => {
    container = document.createElement("div"); document.body.appendChild(container); root = createRoot(container);
    mockGetProjects.mockReset().mockResolvedValue([project]);
    mockGetGraph.mockReset().mockResolvedValue(graph);
    mockQueryProjectGraph.mockReset().mockImplementation((_: string, request: any) => request.type === "scopes" ? Promise.resolve(scopeResponse) : Promise.reject(new Error("unexpected")));
    mockNavigate.mockReset();
    mockSetSearch.mockClear();
    mockRouterState.params = {};
    mockRouterState.search = new URLSearchParams();
    mockGraphExplorerSeen.splice(0);
    window.localStorage.clear();
  });
  afterEach(() => { act(() => root.unmount()); container.remove(); });

  it("does not issue graph POSTs or undefined project URLs before a real project is chosen", async () => {
    mockRouterState.search = new URLSearchParams("run_id=run-7&snapshot_id=snapshot-9");
    await act(async () => {
      root.render(<GraphPage />);
    });
    await settle();
    expect(container.textContent).toContain("Choose a project");
    expect(mockQueryProjectGraph).not.toHaveBeenCalled();
    expect(mockGetGraph).not.toHaveBeenCalled();

    const select = container.querySelector("select[aria-label='Project']") as HTMLSelectElement;
    await act(async () => { select.value = "project-1"; select.dispatchEvent(new Event("change", { bubbles: true })); });
    await settle();
    await settle();
    expect(mockNavigate).toHaveBeenCalledWith("/projects/project-1/graph?run_id=run-7&snapshot_id=snapshot-9");
    expect(mockNavigate.mock.calls.flat().join(" ")).not.toContain("undefined");
  });

  it("redirects a persisted active project without requesting the obsolete global graph", async () => {
    window.localStorage.setItem("devboard.selectedProjectScope.v1", "project-1");
    mockRouterState.search = new URLSearchParams("run_id=run-7&snapshot_id=snapshot-9");

    await act(async () => { root.render(<GraphPage />); });
    await settle();
    await settle();

    expect(mockNavigate).toHaveBeenCalledWith(
      "/projects/project-1/graph?run_id=run-7&snapshot_id=snapshot-9",
      { replace: true },
    );
    expect(mockGetGraph).not.toHaveBeenCalled();
    expect(mockQueryProjectGraph).not.toHaveBeenCalled();
  });

  it("keeps the chooser visible when the persisted project is no longer active", async () => {
    window.localStorage.setItem("devboard.selectedProjectScope.v1", "stale-project");

    await act(async () => { root.render(<GraphPage />); });
    await settle();

    expect(container.textContent).toContain("Choose a project");
    expect(mockNavigate).not.toHaveBeenCalled();
    expect(mockGetGraph).not.toHaveBeenCalled();
    expect(mockQueryProjectGraph).not.toHaveBeenCalled();
  });

  it("encodes a persisted project ID before redirecting", async () => {
    const encodedProject = { ...project, id: "project/with space" };
    mockGetProjects.mockResolvedValue([encodedProject]);
    window.localStorage.setItem("devboard.selectedProjectScope.v1", encodedProject.id);

    await act(async () => { root.render(<GraphPage />); });
    await settle();

    expect(mockNavigate).toHaveBeenCalledWith(
      "/projects/project%2Fwith%20space/graph",
      { replace: true },
    );
    expect(mockGetGraph).not.toHaveBeenCalled();
    expect(mockQueryProjectGraph).not.toHaveBeenCalled();
  });

  it("preserves existing URL state while writing scope and opaque symbol parameters", async () => {
    mockRouterState.params = { projectId: "project-1" };
    mockRouterState.search = new URLSearchParams("run_id=run-7");
    await act(async () => { root.render(<GraphPage />); });
    await settle();
    await settle();
    const explorer = Array.from(container.querySelectorAll("button")).find((item) => item.textContent === "Explorer");
    await act(async () => { explorer?.click(); });
    const next = mockRouterState.search as URLSearchParams;
    expect(next.get("run_id")).toBe("run-7");
    expect(next.get("scope_type")).toBe("repository");
    expect(next.get("scope_id")).toBe("repo-1");
    expect(next.get("symbol")).toBe("opaque-symbol");
  });

  it("keeps GraphExplorer callbacks stable across a same-project URL rerender", async () => {
    mockRouterState.params = { projectId: "project-1" };
    mockRouterState.search = new URLSearchParams("run_id=run-7");
    await act(async () => { root.render(<GraphPage />); });
    await settle(); await settle();
    const first = mockGraphExplorerSeen[mockGraphExplorerSeen.length - 1];
    await act(async () => { first.onQueryParamsChange({ scope_type: "repository", scope_id: "repo-1", symbol: "opaque-next" }); });
    await act(async () => { root.render(<GraphPage />); });
    await settle();
    const second = mockGraphExplorerSeen[mockGraphExplorerSeen.length - 1];
    expect(second.queryGraph).toBe(first.queryGraph);
    expect(second.onQueryParamsChange).toBe(first.onQueryParamsChange);
  });

  it("treats rebuild-required scope envelopes as unavailable", async () => {
    mockRouterState.params = { projectId: "project-1" };
    mockQueryProjectGraph.mockResolvedValue({
      ...scopeResponse,
      found: false,
      reason: "graph_projection_rebuild_required",
      items: [],
      returned: 0,
    });
    await act(async () => { root.render(<GraphPage />); });
    await settle(); await settle();

    const props = mockGraphExplorerSeen[mockGraphExplorerSeen.length - 1];
    expect(props.projectionUnavailable).toBe(true);
  });

  it("treats a rebuild-required overview projection as unavailable", async () => {
    mockRouterState.params = { projectId: "project-1" };
    mockGetGraph.mockResolvedValue({ ...graph, projection_status: "graph_projection_rebuild_required" });
    await act(async () => { root.render(<GraphPage />); });
    await settle(); await settle();

    const props = mockGraphExplorerSeen[mockGraphExplorerSeen.length - 1];
    expect(props.projectionUnavailable).toBe(true);
  });
});
