import React, { useState } from "react";
import { Link, useNavigate, useParams } from "react-router-dom";
import { api } from "@/api/devboardApi";
import { useApi } from "@/hooks/useApi";
import { PageHeader } from "@/components/devboard/Layout";
import { DataState } from "@/components/devboard/DataState";
import { RiskBadge, RunStatusBadge, SourceMetaInline, Pill } from "@/components/devboard/Badges";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { relativeTime, formatDuration, titleCase } from "@/lib/format";
import { Boxes } from "lucide-react";

const ALL = "__all__";

export default function RunsPage() {
  const nav = useNavigate();
  const { projectId } = useParams();
  const state = useApi(() => api.getRuns(projectId), [projectId]);
  const [status, setStatus] = useState(ALL);
  const [type, setType] = useState(ALL);

  return (
    <div className="space-y-4" data-testid="runs-page">
      <PageHeader
        title="Runs"
        subtitle={projectId ? "Project-specific runs from local plugin and analyzer activity." : "Plugin runs create Genesis Import and Delta Sync artifacts from local sources."}
        meta={projectId && <Link to={`/projects/${projectId}`} className="inline-flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground"><Boxes className="h-3.5 w-3.5" />Project {projectId}</Link>}
      />

      <DataState state={state} isEmpty={(d) => d.length === 0}>
        {(runs) => {
          const filtered = runs.filter((r) => (status === ALL || r.status === status) && (type === ALL || r.type === type));
          return (
            <>
              <div className="flex flex-wrap items-center gap-2">
                <Select value={status} onValueChange={setStatus}>
                  <SelectTrigger className="h-8 w-44 text-xs" data-testid="filter-status"><span className="text-muted-foreground">Status:</span><SelectValue /></SelectTrigger>
                  <SelectContent><SelectItem value={ALL}>All</SelectItem>{["queued", "running", "passed", "failed", "needs_review", "reviewed", "cancelled"].map((s) => <SelectItem key={s} value={s}>{titleCase(s)}</SelectItem>)}</SelectContent>
                </Select>
                <Select value={type} onValueChange={setType}>
                  <SelectTrigger className="h-8 w-44 text-xs" data-testid="filter-type"><span className="text-muted-foreground">Type:</span><SelectValue /></SelectTrigger>
                  <SelectContent><SelectItem value={ALL}>All</SelectItem>{["genesis_import", "delta_sync", "analysis", "verification"].map((s) => <SelectItem key={s} value={s}>{titleCase(s)}</SelectItem>)}</SelectContent>
                </Select>
                <span className="text-xs text-muted-foreground">{filtered.length} runs</span>
              </div>

              <div className="overflow-x-auto rounded-md border border-border bg-card">
                <table className="w-full min-w-[900px] text-sm">
                  <thead>
                    <tr className="border-b border-border text-left text-[11px] uppercase tracking-wide text-muted-foreground">
                      <th className="px-4 py-2.5 font-medium">Run</th>
                      <th className="px-3 py-2.5 font-medium">Repository</th>
                      <th className="px-3 py-2.5 font-medium">Type</th>
                      <th className="px-3 py-2.5 font-medium">Status</th>
                      <th className="px-3 py-2.5 font-medium">Risk</th>
                      <th className="px-3 py-2.5 font-medium">Started</th>
                      <th className="px-3 py-2.5 font-medium">Duration</th>
                      <th className="px-3 py-2.5 font-medium">Source</th>
                    </tr>
                  </thead>
                  <tbody>
                    {filtered.map((r) => (
                      <tr key={r.id} onClick={() => nav(projectId ? `/projects/${encodeURIComponent(projectId)}/runs/${r.id}` : `/runs/${r.id}`)} className="cursor-pointer border-b border-border/60 last:border-0 hover:bg-accent/40" data-testid={`run-row-${r.id}`}>
                        <td className="px-4 py-2.5 font-mono text-xs">{r.id}</td>
                        <td className="px-3 py-2.5 font-medium">{r.repository_name}</td>
                        <td className="px-3 py-2.5"><Pill tone="slate">{titleCase(r.type)}</Pill></td>
                        <td className="px-3 py-2.5"><RunStatusBadge status={r.status} /></td>
                        <td className="px-3 py-2.5"><RiskBadge level={r.risk_level} /></td>
                        <td className="px-3 py-2.5 text-xs text-muted-foreground">{relativeTime(r.started_at)}</td>
                        <td className="px-3 py-2.5 text-xs tabular-nums">{formatDuration(r.duration_ms)}</td>
                        <td className="px-3 py-2.5"><SourceMetaInline source={r.source} className="max-w-[280px]" /></td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </>
          );
        }}
      </DataState>
    </div>
  );
}
