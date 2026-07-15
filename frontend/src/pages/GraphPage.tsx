import React, { useCallback, useEffect, useRef, useState } from "react";
import { Link, useNavigate, useParams, useSearchParams } from "react-router-dom";
import { Boxes } from "lucide-react";
import { api } from "@/api/devboardApi";
import { DataState } from "@/components/devboard/DataState";
import GraphExplorer from "@/components/devboard/GraphExplorer";
import { SourceMetaInline } from "@/components/devboard/Badges";
import { MetricCard, PageHeader, Panel } from "@/components/devboard/Layout";
import { useApi } from "@/hooks/useApi";
import { relativeTime } from "@/lib/format";
import { PROJECT_SCOPE_STORAGE_KEY } from "@/lib/nav";
import { DashboardGraphScopeType } from "@/types/devboard";

function projectGraphPath(projectId: string, runId?: string, snapshotId?: string): string {
  const preserved = new URLSearchParams();
  if (runId) preserved.set("run_id", runId);
  if (snapshotId) preserved.set("snapshot_id", snapshotId);
  const suffix = preserved.toString();

  return `/projects/${encodeURIComponent(projectId)}/graph${suffix ? `?${suffix}` : ""}`;
}

function GlobalGraphPage() {
  const navigate = useNavigate();
  const [params] = useSearchParams();
  const runId = params.get("run_id") || undefined;
  const snapshotId = params.get("snapshot_id") || undefined;
  const projects = useApi(() => api.getProjects("active"), []);
  const [persistedProjectId] = useState(() => {
    if (typeof window === "undefined") return undefined;
    return window.localStorage.getItem(PROJECT_SCOPE_STORAGE_KEY) || undefined;
  });

  useEffect(() => {
    if (!persistedProjectId || !projects.data?.some((project) => project.id === persistedProjectId)) return;
    navigate(projectGraphPath(persistedProjectId, runId, snapshotId), { replace: true });
  }, [navigate, persistedProjectId, projects.data, runId, snapshotId]);

  return <div className="min-w-0 space-y-5" data-testid="graph-page">
    <PageHeader title="Graph" subtitle="Choose a project before querying its canonical code graph." />
    <DataState state={projects}>{(items) => <Panel title="Choose a project">
      <label className="text-sm">Project
        <select aria-label="Project" defaultValue="" onChange={(event) => {
          if (event.target.value) {
            navigate(projectGraphPath(event.target.value, runId, snapshotId));
          }
        }} className="mt-1 block w-full max-w-xl rounded border border-input bg-background px-3 py-2">
          <option value="">Select a project</option>
          {items.map((project) => <option key={project.id} value={project.id} label={project.name} />)}
        </select>
      </label>
      <p className="mt-3 text-sm text-muted-foreground">This overview is read-only. No graph query is sent until a project is selected.</p>
    </Panel>}</DataState>
  </div>;
}

function ProjectGraphPage({ projectId }: { projectId: string }) {
  const [params, setParams] = useSearchParams();
  const setParamsRef = useRef(setParams);
  setParamsRef.current = setParams;
  const runId = params.get("run_id") || undefined;
  const snapshotId = params.get("snapshot_id") || undefined;
  const scopeTypeValue = params.get("scope_type");
  const initialScopeType = scopeTypeValue === "repository" || scopeTypeValue === "workspace_binding"
    ? scopeTypeValue as DashboardGraphScopeType : undefined;
  const initialScopeId = params.get("scope_id") || undefined;
  const initialSymbol = params.get("symbol") || undefined;
  const graph = useApi(() => api.getGraph(projectId, { runId, snapshotId }), [projectId, runId, snapshotId]);
  const scopes = useApi(() => api.queryProjectGraph(projectId, { type: "scopes", limit: 100 }), [projectId]);
  const queryGraph = useCallback(
    (request: Parameters<typeof api.queryProjectGraph>[1]) => api.queryProjectGraph(projectId, request),
    [projectId],
  );
  const updateQuery = useCallback((values: { scope_type?: DashboardGraphScopeType; scope_id?: string; symbol?: string }) => {
    setParamsRef.current((current) => {
      const next = new URLSearchParams(current);
      (["scope_type", "scope_id", "symbol"] as const).forEach((key) => {
        const value = values[key];
        if (value) next.set(key, value); else next.delete(key);
      });
      return next;
    }, { replace: true });
  }, []);
  return <div className="min-w-0 space-y-5" data-testid="graph-page">
    <PageHeader title="Graph" subtitle="Explore bounded dependencies, callers, impact, and paths in the canonical project graph."
      meta={<Link to={`/projects/${encodeURIComponent(projectId)}`} title={`Project ${projectId}`} className="inline-flex min-w-0 max-w-full items-center gap-1 text-xs text-muted-foreground hover:text-foreground"><Boxes className="h-3.5 w-3.5 shrink-0" /><span className="break-all">Project {projectId}</span></Link>} />
    <DataState state={graph}>{(overview) => <>
      <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
        <MetricCard label="Nodes" value={overview.stats.nodes} /><MetricCard label="Edges" value={overview.stats.edges} />
        <MetricCard label="Modules" value={overview.stats.modules} /><MetricCard label="Routes" value={overview.stats.routes} />
      </div>
      <div className="min-w-0 rounded-md border border-border bg-card/40 px-4 py-2 text-[11px] text-muted-foreground" data-testid="graph-provenance">
        <div className="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1">
          <SourceMetaInline source={overview.source} /><span>· generated {relativeTime(overview.generated_at)}</span>
          {overview.quality && <span>· quality {overview.quality}</span>}
          {overview.source_scope && <span className="break-all">· scope {overview.source_scope.type}: {overview.source_scope.id}</span>}
        </div>
      </div>
      <DataState state={scopes}>{(response) => response.query_type === "scopes" ? <GraphExplorer
        projectId={projectId} scopes={response.items} queryGraph={queryGraph}
        projectionUnavailable={overview.projection_status === "unavailable" || overview.projection_status === "graph_projection_rebuild_required" || response.reason === "graph_projection_not_ready" || response.reason === "graph_projection_rebuild_required" || response.reason === "graph_scope_not_found"}
        initialScopeType={initialScopeType} initialScopeId={initialScopeId} initialSymbol={initialSymbol} onQueryParamsChange={updateQuery}
        onRetry={() => { graph.reload(); scopes.reload(); }}
      /> : <div role="status">Graph projection unavailable</div>}</DataState>
    </>}</DataState>
  </div>;
}

export default function GraphPage() {
  const { projectId } = useParams();
  return projectId ? <ProjectGraphPage key={projectId} projectId={projectId} /> : <GlobalGraphPage />;
}
