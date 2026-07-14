import React, { useEffect, useState } from "react";
import { Link, useParams } from "react-router-dom";
import { toast } from "sonner";
import { api } from "@/api/devboardApi";
import { useApi } from "@/hooks/useApi";
import { useAuth } from "@/context/AuthContext";
import { PageHeader, Panel } from "@/components/devboard/Layout";
import { DataState } from "@/components/devboard/DataState";
import { SourceMetaInline, Pill } from "@/components/devboard/Badges";
import { ConfirmDialog } from "@/components/devboard/ConfirmDialog";
import { Button } from "@/components/ui/button";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Artifact, ArtifactState } from "@/types/devboard";
import { formatBytes, relativeTime, titleCase } from "@/lib/format";
import { Boxes, Download, Upload, CheckCircle2, ArrowDownToLine, Trash2, Lock } from "lucide-react";
import { canMutate } from "@/lib/nav";

const ALL = "__all__";
const STATE_TONE: Record<ArtifactState, any> = { uploaded: "blue", validated: "teal", imported: "green", invalid: "red", purged: "slate" };

export default function ArtifactsPage() {
  const { user } = useAuth();
  const { projectId } = useParams();
  const state = useApi(() => api.getArtifacts(projectId), [projectId]);
  const [items, setItems] = useState<Artifact[]>([]);
  const [kind, setKind] = useState(ALL);
  const [st, setSt] = useState(ALL);
  const [purge, setPurge] = useState<Artifact | null>(null);

  useEffect(() => { if (state.data) setItems(state.data); }, [state.data]);
  const mutate = !projectId && canMutate(user!.role);

  const filtered = items.filter((a) => (kind === ALL || a.kind === kind) && (st === ALL || a.state === st));

  const setState = (id: string, next: ArtifactState, extra: Partial<Artifact> = {}) =>
    setItems((prev) => prev.map((a) => (a.id === id ? { ...a, state: next, ...extra } : a)));

  const validate = (a: Artifact) => { setState(a.id, "validated", { validated: true, downloadable: true }); toast.success(`Validated ${a.name}`); };
  const importA = (a: Artifact) => { setState(a.id, "imported"); toast.success(`Imported ${a.name}`); };
  const download = async (a: Artifact) => {
    if (!a.downloadable) { toast.error("Not validated — download blocked."); return; }
    try { const { url, name } = await api.downloadArtifact(a.run_id || "", a.id); const l = document.createElement("a"); l.href = url; l.download = name; l.click(); toast.success(`Downloading ${name}`); }
    catch (e: any) { toast.error(e?.message || "Download failed"); }
  };
  const doPurge = () => { if (!purge) return; setState(purge.id, "purged", { downloadable: false, validated: false }); toast.success(`Purged ${purge.name}`); };
  const upload = () => {
    const a: Artifact = {
      id: `art-${Date.now()}`, name: `upload_${Date.now()}.json`, kind: "analysis", state: "uploaded",
      project_id: "proj-core", repository_id: "repo-api", run_id: null, size_bytes: 12044,
      checksum: "sha256:pending", created_at: new Date().toISOString(), validated: false, downloadable: false,
      source: { type: "user_manual", status: "needs_verification", origin: `manual upload / ${user!.name}`, generated_at: new Date().toISOString() },
    };
    setItems((p) => [a, ...p]); toast.success("Artifact uploaded — validate before import.");
  };

  return (
    <div className="space-y-4" data-testid="artifacts-page">
      <PageHeader
        title="Artifacts"
        subtitle={projectId ? "Project-specific Genesis Import, Delta Sync, analysis, and report artifacts." : "Genesis Import / Delta Sync outputs. Upload, validate, import, download, or purge."}
        meta={projectId && <Link to={`/projects/${projectId}`} className="inline-flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground"><Boxes className="h-3.5 w-3.5" />Project {projectId}</Link>}
        actions={mutate
          ? <Button size="sm" onClick={upload} data-testid="upload-artifact-btn"><Upload className="mr-1.5 h-3.5 w-3.5" /> Upload</Button>
          : !projectId && <Button size="sm" disabled title="Requires mutate permission"><Lock className="mr-1.5 h-3.5 w-3.5" /> Upload</Button>}
      />

      <DataState state={state} isEmpty={() => false}>
        {() => (
          <>
            <div className="flex flex-wrap items-center gap-2">
              <Select value={kind} onValueChange={setKind}>
                <SelectTrigger className="h-8 w-44 text-xs" data-testid="filter-kind"><span className="text-muted-foreground">Kind:</span><SelectValue /></SelectTrigger>
                <SelectContent><SelectItem value={ALL}>All</SelectItem>{["genesis_import", "delta_sync", "analysis", "report"].map((k) => <SelectItem key={k} value={k}>{titleCase(k)}</SelectItem>)}</SelectContent>
              </Select>
              <Select value={st} onValueChange={setSt}>
                <SelectTrigger className="h-8 w-44 text-xs" data-testid="filter-state"><span className="text-muted-foreground">State:</span><SelectValue /></SelectTrigger>
                <SelectContent><SelectItem value={ALL}>All</SelectItem>{["uploaded", "validated", "imported", "invalid", "purged"].map((k) => <SelectItem key={k} value={k}>{titleCase(k)}</SelectItem>)}</SelectContent>
              </Select>
              <span className="text-xs text-muted-foreground">{filtered.length} artifacts</span>
            </div>

            <Panel dense>
              <div className="overflow-x-auto">
                <table className="w-full min-w-[1000px] text-sm">
                  <thead><tr className="border-b border-border text-left text-[11px] uppercase tracking-wide text-muted-foreground">
                    <th className="px-4 py-2 font-medium">Artifact</th><th className="px-3 py-2 font-medium">Kind</th><th className="px-3 py-2 font-medium">State</th>
                    <th className="px-3 py-2 font-medium">Size</th><th className="px-3 py-2 font-medium">Run</th><th className="px-3 py-2 font-medium">Source</th><th className="px-3 py-2 text-right font-medium">Actions</th>
                  </tr></thead>
                  <tbody>
                    {filtered.map((a) => (
                      <tr key={a.id} className="border-b border-border/60 last:border-0 hover:bg-accent/30" data-testid={`artifact-row-${a.id}`}>
                        <td className="px-4 py-2.5"><p className="font-mono text-xs">{a.name}</p><p className="text-[11px] text-muted-foreground">{a.checksum}</p></td>
                        <td className="px-3 py-2.5"><Pill tone="slate">{titleCase(a.kind)}</Pill></td>
                        <td className="px-3 py-2.5"><Pill tone={STATE_TONE[a.state]}>{titleCase(a.state)}</Pill></td>
                        <td className="px-3 py-2.5 text-xs tabular-nums">{formatBytes(a.size_bytes)}</td>
                        <td className="px-3 py-2.5">{a.run_id ? <Link to={`/runs/${a.run_id}`} className="font-mono text-xs text-primary hover:underline">{a.run_id}</Link> : <span className="text-xs text-muted-foreground">—</span>}</td>
                        <td className="px-3 py-2.5"><SourceMetaInline source={a.source} className="max-w-[240px]" /></td>
                        <td className="px-3 py-2.5">
                          <div className="flex items-center justify-end gap-1">
                            {a.state === "uploaded" && mutate && <Button size="sm" variant="ghost" onClick={() => validate(a)} data-testid={`validate-${a.id}`} title="Validate"><CheckCircle2 className="h-3.5 w-3.5" /></Button>}
                            {a.state === "validated" && mutate && <Button size="sm" variant="ghost" onClick={() => importA(a)} data-testid={`import-${a.id}`} title="Import"><ArrowDownToLine className="h-3.5 w-3.5" /></Button>}
                            <Button size="sm" variant="ghost" disabled={!a.downloadable} onClick={() => download(a)} data-testid={`download-${a.id}`} title={a.downloadable ? "Download" : "Not validated"}>{a.downloadable ? <Download className="h-3.5 w-3.5" /> : <Lock className="h-3.5 w-3.5" />}</Button>
                            {mutate && a.state !== "purged" && <Button size="sm" variant="ghost" className="text-red-500 hover:text-red-600" onClick={() => setPurge(a)} data-testid={`purge-${a.id}`} title="Purge"><Trash2 className="h-3.5 w-3.5" /></Button>}
                          </div>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </Panel>
          </>
        )}
      </DataState>

      <ConfirmDialog
        open={!!purge} onOpenChange={(v) => !v && setPurge(null)}
        title="Purge artifact" destructive confirmLabel="Purge permanently"
        requireText={purge?.name}
        requireApproval="I understand this destructive operation permanently removes the artifact and its validated payload. This requires explicit human approval."
        description={<>Purging <span className="font-mono">{purge?.name}</span> cannot be undone. The artifact will no longer be importable or downloadable.</>}
        onConfirm={doPurge} testId="purge-confirm"
      />
    </div>
  );
}
