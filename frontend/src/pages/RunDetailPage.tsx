import React, { useState } from "react";
import { useParams, useNavigate, Link } from "react-router-dom";
import { toast } from "sonner";
import { api } from "@/api/devboardApi";
import { useApi } from "@/hooks/useApi";
import { useAuth } from "@/context/AuthContext";
import { PageHeader, MetricCard, Panel } from "@/components/devboard/Layout";
import { DataState } from "@/components/devboard/DataState";
import { RiskBadge, RunStatusBadge, PipelineBadge, SourceMetaInline, Pill } from "@/components/devboard/Badges";
import { ConfirmDialog } from "@/components/devboard/ConfirmDialog";
import { Button } from "@/components/ui/button";
import { Tabs, TabsList, TabsTrigger, TabsContent } from "@/components/ui/tabs";
import { Artifact, RunDetail } from "@/types/devboard";
import { formatDateTime, relativeTime, titleCase, formatDuration, formatBytes } from "@/lib/format";
import {
  ChevronLeft, RotateCw, Download, FileCheck2, Network, BookText,
  CircleCheck, CircleX, CircleAlert, Lock, ClipboardList,
} from "lucide-react";

const TONE_ICON = { ok: CircleCheck, info: CircleAlert, warn: CircleAlert, error: CircleX } as const;
const SAFETY = { pass: { t: "green", I: CircleCheck }, fail: { t: "red", I: CircleX }, warning: { t: "amber", I: CircleAlert }, skipped: { t: "slate", I: CircleAlert } } as const;

export default function RunDetailPage() {
  const { projectId, runId } = useParams();
  const nav = useNavigate();
  const { user } = useAuth();
  const state = useApi(() => api.getRun(runId!), [runId]);
  const artsState = useApi(() => api.getArtifacts(projectId), [projectId]);
  const [confirm, setConfirm] = useState<null | "retry" | "review">(null);

  const role = user?.role;
  const canRetry = role === "admin" || role === "developer";
  const canReview = role === "admin" || role === "pm" || role === "developer";

  const doRetry = async () => { await api.retryImport(runId!); toast.success("Import retry queued"); state.reload(); };
  const doReview = async () => { await api.reviewRun(runId!); toast.success("Run marked reviewed"); state.reload(); };

  const download = async (a: Artifact) => {
    if (!a.downloadable) { toast.error("Artifact is not validated — download blocked."); return; }
    try {
      const { url, name } = await api.downloadArtifact(runId!, a.id);
      const link = document.createElement("a"); link.href = url; link.download = name; link.click();
      toast.success(`Downloading ${name}`);
    } catch (e: any) { toast.error(e?.message || "Download failed"); }
  };

  return (
    <div className="space-y-5" data-testid="run-detail-page">
      <button onClick={() => nav(projectId ? `/projects/${encodeURIComponent(projectId)}/runs` : "/runs")} className="inline-flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground">
        <ChevronLeft className="h-3.5 w-3.5" /> Runs
      </button>

      <DataState state={state}>
        {(run: RunDetail) => (
          <>
            <PageHeader
              title={<span className="flex items-center gap-2 font-mono">{run.id}</span>}
              subtitle={<span>{titleCase(run.type)} · {run.repository_name}</span>}
              meta={
                <div className="flex flex-wrap items-center gap-1.5">
                  <RunStatusBadge status={run.status} />
                  <RiskBadge level={run.risk_level} />
                  <span className="text-xs text-muted-foreground">Started {formatDateTime(run.started_at)} · {formatDuration(run.duration_ms)}</span>
                  {run.reviewed_by && <Pill tone="teal" icon={FileCheck2}>Reviewed by {run.reviewed_by}</Pill>}
                </div>
              }
              actions={
                <>
                  {run.status === "failed" && (canRetry
                    ? <Button size="sm" variant="outline" onClick={() => setConfirm("retry")} data-testid="retry-import-btn"><RotateCw className="mr-1.5 h-3.5 w-3.5" /> Retry import</Button>
                    : <Button size="sm" variant="outline" disabled title="Admin/Developer only"><Lock className="mr-1.5 h-3.5 w-3.5" /> Retry import</Button>)}
                  {run.status === "needs_review" && canReview && (
                    <Button size="sm" onClick={() => setConfirm("review")} data-testid="review-run-btn"><FileCheck2 className="mr-1.5 h-3.5 w-3.5" /> Mark reviewed</Button>
                  )}
                  {run.task_id && <Button size="sm" variant="outline" asChild><Link to={`/tasks/${run.task_id}`} data-testid="open-task-btn"><ClipboardList className="mr-1.5 h-3.5 w-3.5" />Task</Link></Button>}
                  {run.wiki_page_id && <Button size="sm" variant="outline" asChild><Link to={projectId ? `/projects/${encodeURIComponent(projectId)}/wiki/${run.wiki_page_id}` : `/wiki/${run.wiki_page_id}`} data-testid="open-wiki-btn"><BookText className="mr-1.5 h-3.5 w-3.5" />Wiki</Link></Button>}
                  <Button size="sm" variant="outline" asChild><Link to={projectId ? `/projects/${encodeURIComponent(projectId)}/graph?run_id=${run.id}` : `/graph?run_id=${run.id}`} data-testid="open-graph-btn"><Network className="mr-1.5 h-3.5 w-3.5" />Graph</Link></Button>
                </>
              }
            />

            {/* Local source labels */}
            <Panel title="Local source labels">
              <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                {run.local_source_labels.map((l, i) => (
                  <div key={i} className="rounded-md border border-border bg-muted/20 p-3">
                    <p className="text-[11px] uppercase tracking-wide text-muted-foreground">{l.label}</p>
                    <p className="mt-0.5 text-sm font-medium">{l.value}</p>
                    <div className="mt-1.5"><SourceMetaInline source={l.source} /></div>
                  </div>
                ))}
              </div>
              <p className="mt-2 text-[11px] text-muted-foreground">Local plugin snapshots are not remote Git truth.</p>
            </Panel>

            {/* Summary metrics */}
            {run.metrics.length > 0 && (
              <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                {run.metrics.map((m) => <MetricCard key={m.label} label={m.label} value={m.value} sub={m.delta} tone={m.tone} />)}
              </div>
            )}

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
              <div className="lg:col-span-2">
                <Tabs defaultValue="timeline">
                  <TabsList data-testid="run-tabs">
                    <TabsTrigger value="timeline">Timeline</TabsTrigger>
                    <TabsTrigger value="safety">Safety & Risk</TabsTrigger>
                    <TabsTrigger value="tests">Test output</TabsTrigger>
                    <TabsTrigger value="audit">Audit</TabsTrigger>
                  </TabsList>

                  <TabsContent value="timeline" className="mt-3">
                    <Panel dense><ol className="relative ml-3 border-l border-border">
                      {run.timeline.map((e) => {
                        const Icon = TONE_ICON[e.status];
                        const color = e.status === "ok" ? "text-emerald-500" : e.status === "error" ? "text-red-500" : e.status === "warn" ? "text-amber-500" : "text-sky-500";
                        return (
                          <li key={e.id} className="mb-4 ml-4 last:mb-3">
                            <span className={`absolute -left-[7px] grid h-3.5 w-3.5 place-items-center rounded-full bg-background ${color}`}><Icon className="h-3.5 w-3.5" /></span>
                            <div className="flex items-center justify-between"><p className="text-sm font-medium">{e.label}</p><span className="text-[11px] text-muted-foreground">{relativeTime(e.ts)}</span></div>
                            {e.detail && <p className="text-xs text-muted-foreground">{e.detail}</p>}
                          </li>
                        );
                      })}
                    </ol></Panel>
                  </TabsContent>

                  <TabsContent value="safety" className="mt-3 space-y-4">
                    <Panel title="Risk triggers" dense>
                      {run.risk_triggers.length === 0 ? <p className="p-4 text-sm text-muted-foreground">No risk triggers.</p> :
                        <ul className="divide-y divide-border/60">{run.risk_triggers.map((r) => (
                          <li key={r.id} className="flex items-start justify-between gap-3 px-4 py-2.5"><div><p className="text-sm font-medium">{r.label}</p><p className="text-xs text-muted-foreground">{r.reason}</p></div><RiskBadge level={r.level} /></li>
                        ))}</ul>}
                    </Panel>
                    <Panel title="Safety results" dense>
                      <ul className="divide-y divide-border/60">{run.safety_results.map((s) => {
                        const cfg = SAFETY[s.status]; const I = cfg.I;
                        return <li key={s.id} className="flex items-start justify-between gap-3 px-4 py-2.5"><div className="flex items-start gap-2"><I className={`mt-0.5 h-4 w-4 ${cfg.t === "green" ? "text-emerald-500" : cfg.t === "red" ? "text-red-500" : cfg.t === "amber" ? "text-amber-500" : "text-slate-400"}`} /><div><p className="text-sm font-medium">{s.name}</p><p className="text-xs text-muted-foreground">{s.detail}</p></div></div><Pill tone={cfg.t as any}>{titleCase(s.status)}</Pill></li>;
                      })}</ul>
                    </Panel>
                  </TabsContent>

                  <TabsContent value="tests" className="mt-3">
                    <Panel title="Test output" dense>
                      <pre className="max-h-96 overflow-auto p-4 font-mono text-xs leading-relaxed text-muted-foreground">{run.test_output}</pre>
                    </Panel>
                  </TabsContent>

                  <TabsContent value="audit" className="mt-3">
                    <Panel dense><table className="w-full text-sm">
                      <thead><tr className="border-b border-border text-left text-[11px] uppercase tracking-wide text-muted-foreground"><th className="px-4 py-2 font-medium">Time</th><th className="px-3 py-2 font-medium">Actor</th><th className="px-3 py-2 font-medium">Action</th><th className="px-3 py-2 font-medium">Result</th></tr></thead>
                      <tbody>{run.audit_events.map((a) => (
                        <tr key={a.id} className="border-b border-border/60 last:border-0"><td className="px-4 py-2 text-xs text-muted-foreground">{formatDateTime(a.ts)}</td><td className="px-3 py-2 font-mono text-xs">{a.actor}</td><td className="px-3 py-2 font-mono text-xs">{a.action}</td><td className="px-3 py-2"><Pill tone={a.result === "ok" ? "green" : a.result === "denied" ? "amber" : "red"}>{a.result}</Pill></td></tr>
                      ))}</tbody>
                    </table></Panel>
                  </TabsContent>
                </Tabs>
              </div>

              {/* Right column */}
              <div className="space-y-4">
                <Panel title="Pipeline status">
                  <div className="space-y-2">
                    <div className="flex items-center justify-between text-sm"><span className="flex items-center gap-1.5 text-muted-foreground"><Network className="h-3.5 w-3.5" /> Graph</span><PipelineBadge status={run.graph_status} /></div>
                    <div className="flex items-center justify-between text-sm"><span className="flex items-center gap-1.5 text-muted-foreground"><BookText className="h-3.5 w-3.5" /> Wiki</span><PipelineBadge status={run.wiki_status} /></div>
                  </div>
                </Panel>

                <Panel title="Artifacts" dense>
                  <DataState state={artsState} loadingRows={2}>
                    {(arts) => {
                      const list = arts.filter((a) => run.artifact_ids.includes(a.id));
                      if (!list.length) return <p className="p-4 text-sm text-muted-foreground">No artifacts produced.</p>;
                      return <ul className="divide-y divide-border/60">{list.map((a) => (
                        <li key={a.id} className="flex items-center justify-between gap-2 px-4 py-2.5">
                          <div className="min-w-0"><p className="truncate font-mono text-xs">{a.name}</p><p className="text-[11px] text-muted-foreground">{formatBytes(a.size_bytes)} · {a.validated ? "validated" : "unvalidated"}</p></div>
                          <Button size="sm" variant="ghost" disabled={!a.downloadable} onClick={() => download(a)} data-testid={`download-artifact-${a.id}`} title={a.downloadable ? "Download" : "Not validated"}>
                            {a.downloadable ? <Download className="h-3.5 w-3.5" /> : <Lock className="h-3.5 w-3.5" />}
                          </Button>
                        </li>
                      ))}</ul>;
                    }}
                  </DataState>
                </Panel>
              </div>
            </div>

            <ConfirmDialog
              open={confirm === "retry"} onOpenChange={(v) => !v && setConfirm(null)}
              title="Retry failed import" confirmLabel="Retry import"
              description={<>This re-queues the import for <span className="font-mono">{run.repository_name}</span> from the local source. The previous artifact will be superseded.</>}
              onConfirm={doRetry} testId="retry-confirm"
            />
            <ConfirmDialog
              open={confirm === "review"} onOpenChange={(v) => !v && setConfirm(null)}
              title="Mark run reviewed" confirmLabel="Mark reviewed"
              description={<>Confirm you have reviewed the safety results and risk triggers for <span className="font-mono">{run.id}</span>. This is recorded in the audit log.</>}
              onConfirm={doReview} testId="review-confirm"
            />
          </>
        )}
      </DataState>
    </div>
  );
}
