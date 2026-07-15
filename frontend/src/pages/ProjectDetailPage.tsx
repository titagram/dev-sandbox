import React from "react";
import { useParams, useNavigate, Link } from "react-router-dom";
import { api } from "@/api/devboardApi";
import { useApi } from "@/hooks/useApi";
import { useAuth } from "@/context/AuthContext";
import { PageHeader, MetricCard, Panel } from "@/components/devboard/Layout";
import { DataState } from "@/components/devboard/DataState";
import { RiskBadge, PipelineBadge, RunStatusBadge, Pill } from "@/components/devboard/Badges";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { relativeTime, titleCase, formatBytes } from "@/lib/format";
import {
  BookText, Bot, CheckCircle2, ChevronLeft, CircleAlert, CircleDot, GitBranch, HardDrive, KanbanSquare,
  Loader2, Network, Package, PlayCircle, Plus, ShieldCheck, ShieldOff,
} from "lucide-react";
import { AssistantSuggestion, BacklogTriagePayload, LocalWorkspace, ProjectDetail, ProjectKickstartStepStatus, RepositoryDeclarationInput } from "@/types/devboard";
import { toast } from "sonner";
import { workspacePresentation } from "@/lib/workspacePresentation";

const PolicyRow = ({ label, value, good }: { label: string; value: string; good?: boolean }) => (
  <div className="flex items-center justify-between py-1.5 text-sm">
    <span className="text-muted-foreground">{label}</span>
    <span className={good === undefined ? "font-medium" : good ? "font-medium text-emerald-600 dark:text-emerald-400" : "font-medium text-amber-600 dark:text-amber-400"}>{value}</span>
  </div>
);

const KICKSTART_STEP_LABELS: Record<string, string> = {
  project_intake: "Project intake",
  repository_declaration: "Repository declaration",
  local_workspace_link: "Workspace link",
  genesis: "Genesis analysis",
  knowledge_review: "Knowledge review",
};

const KICKSTART_STEPS = [
  "project_intake",
  "repository_declaration",
  "local_workspace_link",
  "genesis",
  "knowledge_review",
] as const;

const STEP_TONE: Record<ProjectKickstartStepStatus, "green" | "amber" | "red" | "slate"> = {
  complete: "green",
  current: "amber",
  pending: "slate",
  blocked: "red",
};

const STEP_ICON: Record<ProjectKickstartStepStatus, any> = {
  complete: CheckCircle2,
  current: CircleDot,
  pending: CircleDot,
  blocked: CircleAlert,
};

type RepositoryFormState = {
  name: string;
  key: string;
  default_branch: string;
  protected_paths: string;
  excluded_paths: string;
  stack_hints: string;
};

const emptyRepositoryForm: RepositoryFormState = {
  name: "",
  key: "",
  default_branch: "main",
  protected_paths: "",
  excluded_paths: "",
  stack_hints: "",
};

const slugify = (value: string) =>
  value
    .trim()
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-+|-+$/g, "");

const listFromText = (value: string): string[] =>
  value
    .split(/[\n,]/)
    .map((item) => item.trim())
    .filter(Boolean);

function workspaceForRepository(repository: ProjectDetail["repositories"][number]): LocalWorkspace {
  return repository.local_workspace || { status: "unknown" };
}

function KickstartPanel({ project }: { project: ProjectDetail }) {
  const kickstart = project.kickstart;
  const operational = project.operational_status;

  if (!kickstart || !operational) {
    return <Panel title="Kickstart" action={<Pill tone="slate">Status unavailable</Pill>}>
      <p className="text-sm text-muted-foreground">The backend has not supplied the project operational status yet.</p>
    </Panel>;
  }

  return (
    <Panel
      title="Kickstart"
      action={<Pill tone={kickstart.state === "active" ? "green" : "amber"}>{titleCase(kickstart.state)}</Pill>}
    >
      <div className="grid gap-2 sm:grid-cols-5">
        {kickstart.steps.map((step) => {
          const Icon = STEP_ICON[step.status] || CircleDot;
          return (
            <div key={step.key} className="rounded-md border border-border bg-muted/20 px-3 py-2">
              <div className="flex items-center justify-between gap-2">
                <span className="truncate text-xs font-medium">{KICKSTART_STEP_LABELS[step.key] || titleCase(step.key)}</span>
                <Pill tone={STEP_TONE[step.status] || "slate"} icon={Icon}>{titleCase(step.status)}</Pill>
              </div>
            </div>
          );
        })}
      </div>
      <div className="mt-3 grid gap-x-6 divide-y divide-border/60 sm:grid-cols-2 sm:divide-y-0">
        <PolicyRow label="Workspace links" value={`${operational.workspace.linked_count} / ${operational.workspace.repository_count}`} />
        <PolicyRow label="Workspace link" value={operational.workspace.reason} />
        <PolicyRow label="Genesis analysis" value={operational.genesis.reason} />
      </div>
    </Panel>
  );
}

function WorkspaceSummary({ workspace, canonicalLinked }: { workspace: LocalWorkspace; canonicalLinked: boolean }) {
  const status = workspace.status || "unknown";
  const presentation = workspacePresentation(status, canonicalLinked);

  if (!presentation.showLegacyDetails) {
    return (
      <div className="min-w-0 space-y-1" data-testid={canonicalLinked ? "canonical-workspace-linked" : undefined}>
        <Pill tone={presentation.tone} icon={HardDrive}>{presentation.label}</Pill>
        {canonicalLinked && <p className="max-w-[260px] text-[11px] leading-snug text-muted-foreground">Legacy clone details unavailable.</p>}
      </div>
    );
  }

  return (
    <div className="min-w-0 space-y-1">
      <Pill tone={presentation.tone as any} icon={HardDrive}>{presentation.label}</Pill>
      {status !== "missing" && status !== "unknown" && (
        <div className="space-y-0.5 text-[11px] text-muted-foreground">
          <div className="max-w-[260px] truncate font-mono">{workspace.display_path || "—"}</div>
          <div className="flex flex-wrap items-center gap-1.5">
            <span>{workspace.current_branch || "—"}</span>
            <span className="text-border">/</span>
            <span>{workspace.dirty_status || "unknown"}</span>
            {workspace.upstream_branch && (
              <>
                <span className="text-border">/</span>
                <span>{workspace.upstream_branch}</span>
              </>
            )}
          </div>
          <div className="flex flex-wrap items-center gap-1.5">
            <span>{workspace.remote_url_host || "local"}</span>
            <span className="text-border">/</span>
            <span>ahead {workspace.ahead_count ?? "—"}</span>
            <span>behind {workspace.behind_count ?? "—"}</span>
            <span className="text-border">/</span>
            <span>{relativeTime(workspace.git_state_observed_at ?? workspace.last_seen_at ?? null)}</span>
          </div>
          {workspace.source_truth === "local_agent_reported" && (
            <div className="text-[10px] uppercase tracking-wide text-muted-foreground">local agent reported</div>
          )}
        </div>
      )}
    </div>
  );
}

function RepositoryDeclarationForm({ projectId, onSaved }: { projectId: string; onSaved: () => void }) {
  const [form, setForm] = React.useState<RepositoryFormState>(emptyRepositoryForm);
  const [busy, setBusy] = React.useState(false);

  const setName = (name: string) => {
    setForm((prev) => ({
      ...prev,
      name,
      key: prev.key ? prev.key : slugify(name),
    }));
  };

  const submitRepository = async (event: React.FormEvent) => {
    event.preventDefault();
    const payload: RepositoryDeclarationInput = {
      name: form.name.trim(),
      key: slugify(form.key || form.name),
      default_branch: form.default_branch.trim() || "main",
      protected_paths: listFromText(form.protected_paths),
      excluded_paths: listFromText(form.excluded_paths),
      stack_hints: listFromText(form.stack_hints),
    };

    if (!payload.name || !payload.key) {
      toast.error("Repository name and key are required.");
      return;
    }

    setBusy(true);
    try {
      await api.createProjectRepository(projectId, payload);
      toast.success("Repository declared");
      setForm(emptyRepositoryForm);
      onSaved();
    } catch (err: any) {
      toast.error(err?.message || "Repository declaration failed.");
    } finally {
      setBusy(false);
    }
  };

  return (
    <Panel title="Declare repository">
      <form onSubmit={submitRepository} className="space-y-3" data-testid="repository-declaration-form">
        <div className="grid gap-3 md:grid-cols-3">
          <div className="space-y-1.5">
            <Label htmlFor="repo-name" className="text-xs">Name</Label>
            <Input id="repo-name" value={form.name} onChange={(event) => setName(event.target.value)} data-testid="repository-name-input" />
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="repo-key" className="text-xs">Key</Label>
            <Input id="repo-key" value={form.key} onChange={(event) => setForm({ ...form, key: slugify(event.target.value) })} data-testid="repository-key-input" />
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="repo-branch" className="text-xs">Default branch</Label>
            <Input id="repo-branch" value={form.default_branch} onChange={(event) => setForm({ ...form, default_branch: event.target.value })} data-testid="repository-branch-input" />
          </div>
        </div>
        <div className="grid gap-3 md:grid-cols-3">
          <div className="space-y-1.5">
            <Label htmlFor="repo-protected-paths" className="text-xs">Protected paths</Label>
            <Textarea id="repo-protected-paths" value={form.protected_paths} onChange={(event) => setForm({ ...form, protected_paths: event.target.value })} className="min-h-16" />
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="repo-excluded-paths" className="text-xs">Excluded paths</Label>
            <Textarea id="repo-excluded-paths" value={form.excluded_paths} onChange={(event) => setForm({ ...form, excluded_paths: event.target.value })} className="min-h-16" />
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="repo-stack-hints" className="text-xs">Stack hints</Label>
            <Textarea id="repo-stack-hints" value={form.stack_hints} onChange={(event) => setForm({ ...form, stack_hints: event.target.value })} className="min-h-16" />
          </div>
        </div>
        <div className="flex justify-end">
          <Button type="submit" size="sm" disabled={busy} data-testid="repository-declare-submit">
            {busy ? <Loader2 className="mr-1.5 h-3.5 w-3.5 animate-spin" /> : <Plus className="mr-1.5 h-3.5 w-3.5" />}
            Declare repository
          </Button>
        </div>
      </form>
    </Panel>
  );
}

function BacklogTriagePanel({ project, canUseAssistants }: { project: ProjectDetail; canUseAssistants: boolean }) {
  const latestSuggestion = project.assistant?.latest_backlog_triage_suggestion ?? null;
  const [suggestion, setSuggestion] = React.useState<AssistantSuggestion<BacklogTriagePayload> | null>(
    latestSuggestion,
  );
  const [triaging, setTriaging] = React.useState(false);
  const canRun = canUseAssistants && project.status === "active";

  React.useEffect(() => {
    setSuggestion(latestSuggestion);
  }, [project.id, latestSuggestion]);

  const runTriage = async () => {
    if (!canRun || triaging) return;

    setTriaging(true);
    try {
      const response = await api.triageProjectBacklog(project.id);
      setSuggestion(response.suggestion as AssistantSuggestion<BacklogTriagePayload>);
      toast.success("Backlog triage ready");
    } catch (err: any) {
      toast.error(err?.message || "Backlog triage failed");
    } finally {
      setTriaging(false);
    }
  };

  const payload = suggestion?.structured_payload;
  const groups = (payload?.groups ?? []).map((group) => [
    group.label,
    group.reason,
    group.task_ids?.length ? `Tasks: ${group.task_ids.join(", ")}` : null,
  ].filter(Boolean).join(" · "));
  const recommendations = (payload?.recommendations ?? []).map((recommendation) => [
    recommendation.title,
    recommendation.body,
    recommendation.priority ? `Priority: ${recommendation.priority}` : null,
  ].filter(Boolean).join(" · "));

  return (
    <Panel
      title={<span className="inline-flex items-center gap-1.5"><Bot className="h-4 w-4 text-muted-foreground" />Backlog Triage</span>}
      action={canRun && (
        <Button size="sm" type="button" onClick={runTriage} disabled={triaging} data-testid="project-assistant-triage">
          {triaging ? <Loader2 className="mr-1.5 h-3.5 w-3.5 animate-spin" /> : <Bot className="mr-1.5 h-3.5 w-3.5" />}
          Triage
        </Button>
      )}
    >
      {suggestion ? (
        <div className="space-y-4" data-testid="project-assistant-suggestion">
          <div className="flex flex-wrap items-center gap-2">
            <Pill tone={assistantStatusTone(suggestion.status)}>{titleCase(suggestion.status)}</Pill>
            <Pill tone="slate">Confidence {Math.round(Number(suggestion.confidence ?? 0) * 100)}%</Pill>
          </div>
          <div>
            <h3 className="text-sm font-medium">{suggestion.title}</h3>
            <p className="mt-1 text-sm leading-relaxed text-muted-foreground">{payload?.summary || suggestion.body_markdown || "No summary."}</p>
          </div>
          <div className="grid gap-3 lg:grid-cols-3">
            <BacklogAssistantList title="Groups" items={groups} />
            <BacklogAssistantList title="Recommendations" items={recommendations} />
            <BacklogAssistantList title="Risks" items={payload?.risks ?? []} />
          </div>
        </div>
      ) : (
        <p className="text-sm text-muted-foreground">No backlog triage suggestion.</p>
      )}
    </Panel>
  );
}

function BacklogAssistantList({ title, items }: { title: string; items: string[] }) {
  return (
    <div className="rounded-md border border-border/70 bg-background/50 p-3">
      <div className="text-xs font-medium uppercase tracking-wide text-muted-foreground">{title}</div>
      {items.length ? (
        <ul className="mt-2 space-y-1.5">
          {items.map((item, index) => (
            <li key={`${title}-${index}`} className="text-sm leading-relaxed text-muted-foreground">{item}</li>
          ))}
        </ul>
      ) : (
        <p className="mt-2 text-sm text-muted-foreground">None.</p>
      )}
    </div>
  );
}

function assistantStatusTone(status: string): "green" | "amber" | "red" | "blue" | "slate" | "teal" {
  if (status === "accepted" || status === "applied") return "green";
  if (status === "rejected") return "red";
  if (status === "superseded") return "slate";
  if (status === "pending") return "amber";
  return "blue";
}

export default function ProjectDetailPage() {
  const { projectId } = useParams();
  const nav = useNavigate();
  const { user } = useAuth();
  const state = useApi(() => api.getProject(projectId!), [projectId]);
  const runsState = useApi(() => api.getRuns(projectId), [projectId]);
  const artifactsState = useApi(() => api.getArtifacts(projectId), [projectId]);
  const canDeclareRepository = user?.role === "admin" || user?.role === "pm";
  const canUseAssistants = user?.role === "admin" || user?.role === "pm";

  return (
    <div className="space-y-5" data-testid="project-detail-page">
      <button onClick={() => nav("/projects")} className="inline-flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground">
        <ChevronLeft className="h-3.5 w-3.5" /> Projects
      </button>

      <DataState state={state}>
        {(p) => (
          <>
              <PageHeader
              title={<span className="flex min-w-0 flex-wrap items-center gap-2"><span className="max-w-full break-all font-mono text-sm text-muted-foreground" title={p.key}>{p.key}</span><span className="min-w-0">{p.name}</span></span>}
              subtitle={p.description}
              meta={<div className="flex flex-wrap items-center gap-1.5"><RiskBadge level={p.risk_level} /><Pill tone="slate">Owner: {p.owner}</Pill><span className="text-xs text-muted-foreground">Updated {relativeTime(p.updated_at)}</span></div>}
              actions={
                <>
                  <Button size="sm" variant="outline" asChild><Link to={`/projects/${p.id}/kanban`} data-testid="project-open-kanban"><KanbanSquare className="mr-1.5 h-3.5 w-3.5" />Kanban</Link></Button>
                  <Button size="sm" variant="outline" asChild><Link to={`/projects/${p.id}/runs`} data-testid="project-open-runs"><PlayCircle className="mr-1.5 h-3.5 w-3.5" />Runs</Link></Button>
                  <Button size="sm" variant="outline" asChild><Link to={`/projects/${p.id}/wiki`} data-testid="project-open-wiki"><BookText className="mr-1.5 h-3.5 w-3.5" />Wiki</Link></Button>
                  <Button size="sm" variant="outline" asChild><Link to={`/projects/${encodeURIComponent(p.id)}/graph`} data-testid="project-open-graph"><Network className="mr-1.5 h-3.5 w-3.5" />Graph</Link></Button>
                  <Button size="sm" variant="outline" asChild><Link to={`/projects/${p.id}/artifacts`} data-testid="project-open-artifacts"><Package className="mr-1.5 h-3.5 w-3.5" />Artifacts</Link></Button>
                </>
              }
            />

            <div className="grid grid-cols-2 gap-3 sm:grid-cols-5">
              <MetricCard label="Repositories" value={p.repository_count} />
              <MetricCard label="Open tasks" value={p.open_tasks} />
              <div className="rounded-md border border-border bg-card px-3.5 py-3"><p className="text-[11px] uppercase tracking-wide text-muted-foreground">Genesis analysis</p><div className="mt-1.5"><PipelineBadge status={p.operational_status?.genesis.status || "not_started"} /></div></div>
              <div className="rounded-md border border-border bg-card px-3.5 py-3"><p className="text-[11px] uppercase tracking-wide text-muted-foreground">Canonical graph</p><div className="mt-1.5"><Pill tone={p.operational_status?.graph.status === "ready" ? "green" : p.operational_status?.graph.status === "partial" ? "amber" : "slate"}>{titleCase(p.operational_status?.graph.status || "status unavailable")}</Pill></div></div>
              <div className="rounded-md border border-border bg-card px-3.5 py-3"><p className="text-[11px] uppercase tracking-wide text-muted-foreground">Wiki freshness</p><div className="mt-1.5"><PipelineBadge status={p.wiki_freshness} /></div></div>
            </div>

            <KickstartPanel project={p} />
            <BacklogTriagePanel project={p} canUseAssistants={canUseAssistants} />

            {canDeclareRepository && p.status === "active" && (
              <RepositoryDeclarationForm projectId={p.id} onSaved={state.reload} />
            )}

            {/* Repositories table */}
            <Panel title="Repositories" dense action={<span className="text-[11px] text-muted-foreground">local-only Git sources</span>}>
              <div className="overflow-x-auto">
                <table className="w-full min-w-[1120px] text-sm">
                  <thead>
                    <tr className="border-b border-border text-left text-[11px] uppercase tracking-wide text-muted-foreground">
                      <th className="px-4 py-2 font-medium">Repository</th>
                      <th className="px-3 py-2 font-medium">Branch</th>
                      <th className="px-3 py-2 font-medium">Workspace</th>
                      <th className="px-3 py-2 font-medium">Mode</th>
                      <th className="px-3 py-2 font-medium">Last snapshot</th>
                      <th className="px-3 py-2 font-medium">Genesis</th>
                      <th className="px-3 py-2 font-medium">Delta</th>
                      <th className="px-3 py-2 font-medium">Graph</th>
                      <th className="px-3 py-2 font-medium">Wiki</th>
                      <th className="px-3 py-2 font-medium">Risk</th>
                      <th className="px-3 py-2 font-medium">Latest run</th>
                    </tr>
                  </thead>
                  <tbody>
                    {p.repositories.length === 0 && (
                      <tr>
                        <td className="px-4 py-6 text-sm text-muted-foreground" colSpan={11}>No repositories.</td>
                      </tr>
                    )}
                    {p.repositories.map((r) => {
                      const workspace = workspaceForRepository(r);
                      const canonicalLinked = p.operational_status?.workspace.status === "linked";
                      return (
                        <tr key={r.id} className="border-b border-border/60 last:border-0 hover:bg-accent/40" data-testid={`repo-row-${r.id}`}>
                          <td className="px-4 py-2.5">
                            <div className="flex items-center gap-2 font-medium"><GitBranch className="h-3.5 w-3.5 text-muted-foreground" />{r.name}</div>
                            <div className="mt-0.5 font-mono text-[11px] text-muted-foreground">{r.key || r.id}</div>
                          </td>
                          <td className="px-3 py-2.5 font-mono text-xs">{r.default_branch}</td>
                          <td className="px-3 py-2.5"><WorkspaceSummary workspace={workspace} canonicalLinked={canonicalLinked} /></td>
                          <td className="px-3 py-2.5"><Pill tone="slate">{titleCase(r.git_mode)}</Pill></td>
                          <td className="px-3 py-2.5 text-xs text-muted-foreground">{relativeTime(r.last_local_snapshot)}</td>
                          <td className="px-3 py-2.5"><PipelineBadge status={r.genesis_status} /></td>
                          <td className="px-3 py-2.5"><PipelineBadge status={r.delta_status} /></td>
                          <td className="px-3 py-2.5"><PipelineBadge status={r.graph_status} /></td>
                          <td className="px-3 py-2.5"><PipelineBadge status={r.wiki_status} /></td>
                          <td className="px-3 py-2.5"><RiskBadge level={r.risk_level} /></td>
                          <td className="px-3 py-2.5">
                            {r.latest_run_id ? (
                              <Link to={`/runs/${r.latest_run_id}`} onClick={(e) => e.stopPropagation()}><RunStatusBadge status={r.latest_run_status} /></Link>
                            ) : <span className="text-xs text-muted-foreground">—</span>}
                          </td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            </Panel>

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
              {/* Recent runs */}
              <Panel title="Recent runs" className="lg:col-span-1">
                <DataState state={runsState} loadingRows={3}>
                  {(runs) => {
                    const list = runs.slice(0, 5);
                    if (!list.length) return <p className="text-sm text-muted-foreground">No runs yet.</p>;
                    return (
                      <ul className="space-y-2">
                        {list.map((r) => (
                          <li key={r.id}>
                            <Link to={`/runs/${r.id}`} className="flex items-center justify-between rounded-md border border-border px-3 py-2 hover:bg-accent/50">
                              <span className="min-w-0"><span className="block truncate text-sm font-medium">{r.repository_name}</span><span className="block text-[11px] text-muted-foreground">{titleCase(r.type)} · {relativeTime(r.started_at)}</span></span>
                              <RunStatusBadge status={r.status} />
                            </Link>
                          </li>
                        ))}
                      </ul>
                    );
                  }}
                </DataState>
              </Panel>

              {/* Latest artifacts */}
              <Panel title="Latest artifacts" className="lg:col-span-1">
                <DataState state={artifactsState} loadingRows={3}>
                  {(arts) => {
                    const list = arts.slice(0, 5);
                    if (!list.length) return <div className="space-y-1 text-sm text-muted-foreground">
                      <p>{p.operational_status?.artifacts.status === "available" ? "Canonical graph artifacts are available; no legacy artifact rows were returned." : "No artifacts are available yet."}</p>
                      {p.operational_status?.artifacts.reason && <p className="text-xs">{p.operational_status.artifacts.reason}</p>}
                    </div>;
                    return (
                      <ul className="space-y-2">
                        {list.map((a) => (
                          <li key={a.id} className="rounded-md border border-border px-3 py-2">
                            <div className="flex items-center justify-between gap-2">
                              <span className="truncate font-mono text-xs">{a.name}</span>
                              <Pill tone={a.state === "imported" ? "green" : a.state === "invalid" ? "red" : "blue"}>{titleCase(a.state)}</Pill>
                            </div>
                            <p className="mt-1 text-[11px] text-muted-foreground">{titleCase(a.kind)} · {formatBytes(a.size_bytes)}</p>
                          </li>
                        ))}
                      </ul>
                    );
                  }}
                </DataState>
              </Panel>

              {/* Policy summary */}
              <Panel title="Policy summary" className="lg:col-span-1">
                <div className="divide-y divide-border/60">
                  <PolicyRow label="Code-write allowed" value={p.policy.code_write_allowed ? "Yes" : "No"} good={p.policy.code_write_allowed} />
                  <PolicyRow label="Destructive scans" value={p.policy.destructive_scans_allowed ? "Allowed" : "Disabled"} good={!p.policy.destructive_scans_allowed} />
                  <PolicyRow label="Auto-import on snapshot" value={p.policy.auto_import_on_snapshot ? "Enabled" : "Manual"} />
                  <PolicyRow label="Review required above" value={titleCase(p.policy.require_review_above_risk)} />
                  <PolicyRow label="Retention" value={`${p.policy.retention_days} days`} />
                </div>
                <div className="mt-3 flex items-center gap-2 rounded-md border border-border bg-muted/30 px-3 py-2 text-[11px] text-muted-foreground">
                  {p.policy.destructive_scans_allowed ? <ShieldOff className="h-3.5 w-3.5 text-amber-500" /> : <ShieldCheck className="h-3.5 w-3.5 text-emerald-500" />}
                  Destructive operations require explicit human approval.
                </div>
              </Panel>
            </div>
          </>
        )}
      </DataState>
    </div>
  );
}
