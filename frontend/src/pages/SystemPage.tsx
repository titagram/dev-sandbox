import React, { useEffect, useState } from "react";
import { toast } from "sonner";
import { API_BASE_URL, api } from "@/api/devboardApi";
import { useApi } from "@/hooks/useApi";
import { PageHeader, MetricCard, Panel } from "@/components/devboard/Layout";
import { DataState } from "@/components/devboard/DataState";
import { PipelineBadge, Pill } from "@/components/devboard/Badges";
import { ConfirmDialog } from "@/components/devboard/ConfirmDialog";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Switch } from "@/components/ui/switch";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { BackupDryRunReport, BackupExport, BackupReadiness, SystemStatus } from "@/types/devboard";
import { formatDateTime } from "@/lib/format";
import { Activity, CircleCheck, CircleX, Clock, Download, FileDown, FileUp, Save, ShieldCheck, Trash2 } from "lucide-react";

export default function SystemPage() {
  const state = useApi(() => api.getSystem(), []);
  const backupState = useApi(() => api.getBackupReadiness(), []);
  const [sys, setSys] = useState<SystemStatus | null>(null);
  const [backupReadiness, setBackupReadiness] = useState<BackupReadiness | null>(null);
  const [backupExport, setBackupExport] = useState<BackupExport | null>(null);
  const [backupFile, setBackupFile] = useState<File | null>(null);
  const [backupReport, setBackupReport] = useState<BackupDryRunReport | null>(null);
  const [days, setDays] = useState(30);
  const [autoPurge, setAutoPurge] = useState(true);
  const [range, setRange] = useState("30");
  const [confirm, setConfirm] = useState<null | "retention" | "export" | "backup-export">(null);

  useEffect(() => {
    if (state.data) { setSys(state.data); setDays(state.data.retention.artifact_retention_days); setAutoPurge(state.data.retention.auto_purge_enabled); }
  }, [state.data]);
  useEffect(() => {
    if (backupState.data) setBackupReadiness(backupState.data);
  }, [backupState.data]);

  const applyRetention = async () => { const s = await api.setArtifactRetention(days, autoPurge); setSys(s); toast.success("Retention policy updated"); };
  const runExport = async () => { const s = await api.runAuditExport(Number(range)); setSys(s); toast.success("Audit export generated"); };
  const runBackupExport = async () => {
    const result = await api.exportBackup();
    setBackupExport(result);
    setBackupReadiness(await api.getBackupReadiness());
    toast.success("Backup export generated");
  };
  const runBackupValidation = async () => {
    if (!backupFile) return;
    const report = await api.validateBackupBundle(backupFile);
    setBackupReport(report);
    if (report.can_restore) toast.success("Backup dry-run passed");
    else toast.error("Backup dry-run found blockers");
  };
  const backupDownloadHref = backupExport?.download_url ? `${API_BASE_URL}${backupExport.download_url}` : null;

  return (
    <div className="space-y-5" data-testid="system-page">
      <PageHeader title="System Operations" subtitle="Runtime, queues, retention, and audit exports. Live purge/export operations require explicit confirmation." />

      <DataState state={state}>
        {() => sys && (
          <>
            <Panel title="Runtime metrics">
              <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                {sys.runtime.map((m) => <MetricCard key={m.label} label={m.label} value={m.value} tone={m.tone} />)}
              </div>
            </Panel>

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
              <Panel title="Queues" dense className="lg:col-span-2">
                <table className="w-full text-sm">
                  <thead><tr className="border-b border-border text-left text-[11px] uppercase tracking-wide text-muted-foreground"><th className="px-4 py-2 font-medium">Queue</th><th className="px-3 py-2 font-medium">Pending</th><th className="px-3 py-2 font-medium">Processing</th><th className="px-3 py-2 font-medium">Failed</th></tr></thead>
                  <tbody>{sys.queue.map((q) => (
                    <tr key={q.name} className="border-b border-border/60 last:border-0"><td className="px-4 py-2.5 font-mono text-xs">{q.name}</td><td className="px-3 py-2.5 tabular-nums">{q.pending}</td><td className="px-3 py-2.5 tabular-nums">{q.processing}</td><td className={`px-3 py-2.5 tabular-nums ${q.failed ? "font-semibold text-red-500" : ""}`}>{q.failed}</td></tr>
                  ))}</tbody>
                </table>
              </Panel>

              <Panel title="Pipeline status">
                <div className="space-y-2.5">
                  <div className="flex items-center justify-between text-sm"><span className="flex items-center gap-1.5 text-muted-foreground"><Activity className="h-3.5 w-3.5" /> Graph</span><PipelineBadge status={sys.graph_status} /></div>
                  <div className="flex items-center justify-between text-sm"><span className="flex items-center gap-1.5 text-muted-foreground"><Activity className="h-3.5 w-3.5" /> Import</span><PipelineBadge status={sys.import_status} /></div>
                </div>
                <div className="mt-3 border-t border-border pt-3">
                  <p className="text-[11px] uppercase tracking-wide text-muted-foreground">Last operation</p>
                  {sys.last_operation ? (
                    <div className="mt-1.5 flex items-center gap-2 text-sm" data-testid="last-operation">
                      {sys.last_operation.status === "ok" ? <CircleCheck className="h-4 w-4 text-emerald-500" /> : sys.last_operation.status === "error" ? <CircleX className="h-4 w-4 text-red-500" /> : <Clock className="h-4 w-4 text-amber-500" />}
                      <span>{sys.last_operation.label}</span><span className="ml-auto text-[11px] text-muted-foreground">{formatDateTime(sys.last_operation.at)}</span>
                    </div>
                  ) : <p className="mt-1 text-sm text-muted-foreground">No recent operations.</p>}
                </div>
              </Panel>
            </div>

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
              <Panel title="Artifact retention">
                <div className="space-y-3">
                  <div className="flex items-end gap-3">
                    <div className="flex-1 space-y-1.5"><Label className="text-xs">Retention (days)</Label><Input type="number" min={1} value={days} onChange={(e) => setDays(Number(e.target.value))} data-testid="retention-days-input" /></div>
                    <div className="flex items-center gap-2 pb-2"><Switch checked={autoPurge} onCheckedChange={setAutoPurge} data-testid="auto-purge-switch" id="ap" /><Label htmlFor="ap" className="text-xs">Auto-purge</Label></div>
                  </div>
                  <div className="flex items-center gap-2 rounded-md border border-amber-500/30 bg-amber-500/5 px-3 py-2 text-[11px] text-amber-700 dark:text-amber-300">
                    <Trash2 className="h-3.5 w-3.5" /> Auto-purge permanently removes artifacts older than the retention window.
                  </div>
                  <Button size="sm" onClick={() => setConfirm("retention")} data-testid="apply-retention-btn"><Save className="mr-1.5 h-3.5 w-3.5" /> Apply retention policy</Button>
                </div>
              </Panel>

              <Panel title="Audit export">
                <div className="space-y-3">
                  <div className="space-y-1.5"><Label className="text-xs">Export range</Label>
                    <Select value={range} onValueChange={setRange}><SelectTrigger className="w-full" data-testid="export-range-select"><SelectValue /></SelectTrigger>
                      <SelectContent><SelectItem value="7">Last 7 days</SelectItem><SelectItem value="30">Last 30 days</SelectItem><SelectItem value="90">Last 90 days</SelectItem></SelectContent>
                    </Select>
                  </div>
                  <p className="text-[11px] text-muted-foreground">Generates a signed, machine-readable audit export. This is a live operation and requires confirmation.</p>
                  <Button size="sm" variant="outline" disabled={!sys.audit_export_available} onClick={() => setConfirm("export")} data-testid="run-export-btn"><FileDown className="mr-1.5 h-3.5 w-3.5" /> Generate audit export</Button>
                </div>
              </Panel>
            </div>

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
              <Panel title="Backup export">
                <div className="space-y-3">
                  <div className="flex items-center justify-between gap-3">
                    <div>
                      <p className="text-sm font-medium">{backupReadiness?.format ?? "devboard-backup-v1"}</p>
                      <p className="text-xs text-muted-foreground">
                        {backupReadiness?.components.filter((c) => c.included).length ?? 0} components included · plaintext secrets excluded
                      </p>
                    </div>
                    <PipelineBadge status={backupReadiness?.can_export ? "complete" : "failed"} />
                  </div>
                  <div className="grid gap-2 sm:grid-cols-3">
                    {(backupReadiness?.components ?? []).map((component) => (
                      <div key={component.key} className="rounded-md border border-border px-3 py-2">
                        <div className="flex items-center gap-1.5 text-xs font-medium">
                          <ShieldCheck className={component.included ? "h-3.5 w-3.5 text-emerald-500" : "h-3.5 w-3.5 text-muted-foreground"} />
                          {component.label}
                        </div>
                        <p className="mt-1 line-clamp-2 text-[11px] text-muted-foreground">{component.detail}</p>
                      </div>
                    ))}
                  </div>
                  {backupExport && (
                    <div className="rounded-md border border-emerald-500/30 bg-emerald-500/5 px-3 py-2 text-xs">
                      <div className="flex items-center justify-between gap-3">
                        <span className="truncate font-mono">{backupExport.filename}</span>
                        <span className="text-muted-foreground">{Math.round(backupExport.size_bytes / 1024)} KB</span>
                      </div>
                      <p className="mt-1 truncate text-[11px] text-muted-foreground">{backupExport.sha256}</p>
                    </div>
                  )}
                  <div className="flex flex-wrap gap-2">
                    <Button size="sm" onClick={() => setConfirm("backup-export")} disabled={!backupReadiness?.can_export} data-testid="backup-export-btn"><FileDown className="mr-1.5 h-3.5 w-3.5" /> Export backup</Button>
                    {backupDownloadHref && (
                      <Button size="sm" variant="outline" asChild data-testid="backup-download-link">
                        <a href={backupDownloadHref} download={backupExport?.filename}><Download className="mr-1.5 h-3.5 w-3.5" /> Download</a>
                      </Button>
                    )}
                  </div>
                </div>
              </Panel>

              <Panel title="Restore dry-run">
                <div className="space-y-3">
                  <div className="space-y-1.5">
                    <Label className="text-xs">Backup bundle</Label>
                    <Input type="file" accept="application/json,.json" onChange={(e) => setBackupFile(e.target.files?.[0] ?? null)} data-testid="backup-file-input" />
                  </div>
                  <Button size="sm" variant="outline" disabled={!backupFile} onClick={runBackupValidation} data-testid="backup-validate-btn"><FileUp className="mr-1.5 h-3.5 w-3.5" /> Validate dry-run</Button>
                  {backupReport && (
                    <div className="rounded-md border border-border px-3 py-2 text-sm" data-testid="backup-dry-run-report">
                      <div className="flex items-center justify-between gap-3">
                        <span className="font-medium">{backupReport.can_restore ? "Dry-run passed" : "Dry-run blocked"}</span>
                        <PipelineBadge status={backupReport.can_restore ? "complete" : "failed"} />
                      </div>
                      <div className="mt-2 grid grid-cols-2 gap-2 text-xs sm:grid-cols-4">
                        <span>Tables: <span className="font-mono">{backupReport.summary.tables}</span></span>
                        <span>Rows: <span className="font-mono">{backupReport.summary.rows}</span></span>
                        <span>Files: <span className="font-mono">{backupReport.summary.storage_files}</span></span>
                        <span>Secrets: <span className="font-mono">{backupReport.summary.required_secrets}</span></span>
                      </div>
                      {backupReport.blockers.length > 0 && (
                        <div className="mt-2 space-y-1 text-xs text-red-500">
                          {backupReport.blockers.map((b) => <p key={`${b.code}-${b.message}`}>{b.message}</p>)}
                        </div>
                      )}
                    </div>
                  )}
                </div>
              </Panel>
            </div>

            <ConfirmDialog open={confirm === "retention"} onOpenChange={(v) => !v && setConfirm(null)} title="Apply retention policy" confirmLabel="Apply policy" destructive={autoPurge}
              description={<>Set artifact retention to <span className="font-mono">{days} days</span> with auto-purge <span className="font-medium">{autoPurge ? "enabled" : "disabled"}</span>.{autoPurge && " Artifacts older than the window will be permanently removed."}</>}
              requireApproval={autoPurge ? "I understand enabling auto-purge will permanently delete artifacts beyond the retention window." : undefined}
              onConfirm={applyRetention} testId="retention-confirm" />

            <ConfirmDialog open={confirm === "export"} onOpenChange={(v) => !v && setConfirm(null)} title="Generate audit export" confirmLabel="Generate export"
              description={<>Generate a signed audit export for the last <span className="font-mono">{range} days</span>. This is a live operation recorded in the audit log.</>}
              requireApproval="I confirm I am authorized to export audit data for this range."
              onConfirm={runExport} testId="export-confirm" />

            <ConfirmDialog open={confirm === "backup-export"} onOpenChange={(v) => !v && setConfirm(null)} title="Export Hades Agent backup" confirmLabel="Export backup"
              description={<>Create a portable <span className="font-mono">devboard-backup-v1</span> bundle. Plaintext secrets and target source repositories are excluded.</>}
              requireApproval="I confirm I am authorized to export Hades Agent control-plane state and Hades Agent-held evidence."
              onConfirm={runBackupExport} testId="backup-export-confirm" />
          </>
        )}
      </DataState>
    </div>
  );
}
