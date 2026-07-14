import React from "react";
import { useNavigate } from "react-router-dom";
import { api } from "@/api/devboardApi";
import { useApi } from "@/hooks/useApi";
import { PageHeader, MetricCard, Panel } from "@/components/devboard/Layout";
import { DataState } from "@/components/devboard/DataState";
import { PipelineBadge, RiskBadge, Pill } from "@/components/devboard/Badges";
import { relativeTime } from "@/lib/format";
import { Activity, AlertTriangle, Boxes, FolderGit2, PlayCircle, Wifi } from "lucide-react";

export default function OverviewPage() {
  const state = useApi(() => api.getOverview(), []);
  const nav = useNavigate();

  return (
    <div className="space-y-5" data-testid="overview-page">
      <PageHeader
        title="Overview"
        subtitle="Cross-project operating view. Project resources stay scoped when you open a project."
      />

      <DataState state={state}>
        {(overview) => (
          <>
            <div className="grid grid-cols-2 gap-3 lg:grid-cols-6">
              <MetricCard label="Active projects" value={overview.summary.active_projects} testId="metric-active-projects" />
              <MetricCard label="Awaiting Genesis" value={overview.summary.repositories_awaiting_genesis} tone={overview.summary.repositories_awaiting_genesis ? "warn" : "good"} />
              <MetricCard label="Open tasks" value={overview.tasks.total - overview.tasks.by_state.done} />
              <MetricCard label="Blocked tasks" value={overview.tasks.blocked} tone={overview.tasks.blocked ? "warn" : "good"} />
              <MetricCard label="Running runs" value={overview.runs.running} />
              <MetricCard label="Failed runs" value={overview.runs.failed} tone={overview.runs.failed ? "bad" : "good"} />
            </div>

            <div className="grid grid-cols-1 gap-4 xl:grid-cols-3">
              <Panel title="Project health" className="xl:col-span-2" dense>
                <div className="overflow-x-auto">
                  <table className="w-full min-w-[900px] text-sm">
                    <thead>
                      <tr className="border-b border-border text-left text-[11px] uppercase tracking-wide text-muted-foreground">
                        <th className="px-4 py-2 font-medium">Project</th>
                        <th className="px-3 py-2 font-medium">Owner</th>
                        <th className="px-3 py-2 font-medium">Risk</th>
                        <th className="px-3 py-2 font-medium">Genesis</th>
                        <th className="px-3 py-2 font-medium">Delta</th>
                        <th className="px-3 py-2 font-medium">Wiki</th>
                        <th className="px-3 py-2 font-medium">Open tasks</th>
                        <th className="px-3 py-2 font-medium">Updated</th>
                      </tr>
                    </thead>
                    <tbody>
                      {overview.projects.map((project) => (
                        <tr
                          key={project.id}
                          onClick={() => nav(`/projects/${project.id}`)}
                          className="cursor-pointer border-b border-border/60 last:border-0 hover:bg-accent/40"
                          data-testid={`overview-project-${project.id}`}
                        >
                          <td className="px-4 py-2.5">
                            <div className="flex items-center gap-2 font-medium">
                              <Boxes className="h-3.5 w-3.5 text-muted-foreground" />
                              <span>{project.name}</span>
                              <span className="font-mono text-[11px] text-muted-foreground">{project.key}</span>
                            </div>
                          </td>
                          <td className="px-3 py-2.5 text-xs text-muted-foreground">{project.owner}</td>
                          <td className="px-3 py-2.5"><RiskBadge level={project.risk_level} /></td>
                          <td className="px-3 py-2.5"><PipelineBadge status={project.genesis_status} /></td>
                          <td className="px-3 py-2.5"><PipelineBadge status={project.delta_status} /></td>
                          <td className="px-3 py-2.5"><PipelineBadge status={project.wiki_freshness} /></td>
                          <td className="px-3 py-2.5 tabular-nums">{project.open_tasks}</td>
                          <td className="px-3 py-2.5 text-xs text-muted-foreground">{relativeTime(project.updated_at)}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </Panel>

              <div className="space-y-4">
                <Panel title="Task distribution">
                  <div className="space-y-2">
                    {Object.entries(overview.tasks.by_state).map(([stateKey, count]) => (
                      <div key={stateKey} className="flex items-center justify-between text-sm">
                        <span className="text-muted-foreground">{stateKey.replace(/_/g, " ")}</span>
                        <Pill tone={stateKey === "blocked" && count > 0 ? "red" : "slate"}>{count}</Pill>
                      </div>
                    ))}
                  </div>
                </Panel>
                <Panel title="Operational signals">
                  <div className="space-y-3 text-sm">
                    <Signal icon={FolderGit2} label="Repositories awaiting Genesis" value={overview.summary.repositories_awaiting_genesis} tone={overview.summary.repositories_awaiting_genesis ? "warn" : "good"} />
                    <Signal icon={PlayCircle} label="Failed runs" value={overview.runs.failed} tone={overview.runs.failed ? "bad" : "good"} />
                    <Signal icon={AlertTriangle} label="Stale wiki pages" value={overview.wiki.stale_pages} tone={overview.wiki.stale_pages ? "warn" : "good"} />
                    <Signal icon={Wifi} label="Online agents" value={overview.agents.online} tone="neutral" />
                    <Signal icon={Activity} label="Offline agents" value={overview.agents.offline} tone={overview.agents.offline ? "warn" : "good"} />
                  </div>
                </Panel>
              </div>
            </div>
          </>
        )}
      </DataState>
    </div>
  );
}

function Signal({
  icon: Icon,
  label,
  value,
  tone,
}: {
  icon: any;
  label: string;
  value: number;
  tone: "neutral" | "good" | "warn" | "bad";
}) {
  const cls = {
    neutral: "text-muted-foreground",
    good: "text-emerald-600 dark:text-emerald-400",
    warn: "text-amber-600 dark:text-amber-400",
    bad: "text-red-600 dark:text-red-400",
  }[tone];

  return (
    <div className="flex items-center justify-between gap-3">
      <span className="flex min-w-0 items-center gap-2 text-muted-foreground">
        <Icon className="h-3.5 w-3.5 shrink-0" />
        <span className="truncate">{label}</span>
      </span>
      <span className={`font-mono text-xs font-semibold tabular-nums ${cls}`}>{value}</span>
    </div>
  );
}
