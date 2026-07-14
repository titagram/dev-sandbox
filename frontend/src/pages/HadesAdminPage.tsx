import React, { useEffect, useMemo, useState } from "react";
import { toast } from "sonner";
import { api } from "@/api/devboardApi";
import { useApi } from "@/hooks/useApi";
import { DataState, EmptyState } from "@/components/devboard/DataState";
import { PageHeader, Panel } from "@/components/devboard/Layout";
import { Pill } from "@/components/devboard/Badges";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { ConfirmDialog } from "@/components/devboard/ConfirmDialog";
import { HadesAdminSnapshot, HadesBootstrapToken, HadesCapability, HadesMemoryProposal } from "@/types/devboard";
import { relativeTime } from "@/lib/format";
import { Check, Clipboard, KeyRound, Play, RefreshCw, ShieldCheck, Trash2 } from "lucide-react";

function time(value?: string | null) {
  return value ? relativeTime(value) : "n/a";
}

function statusTone(status?: string | null) {
  if (!status) return "slate" as const;
  if (["active", "linked", "queued", "running", "pending"].includes(status)) return "blue" as const;
  if (["accepted", "completed", "complete"].includes(status)) return "green" as const;
  if (["failed", "revoked", "refused", "conflicted"].includes(status)) return "red" as const;
  return "slate" as const;
}

export default function HadesAdminPage() {
  const state = useApi(() => api.getHadesAdmin(), []);
  const [projectId, setProjectId] = useState("");
  const [name, setName] = useState("Local Hades bootstrap");
  const [expiresInDays, setExpiresInDays] = useState("90");
  const [capabilities, setCapabilities] = useState<HadesCapability[]>([]);
  const [capabilitiesDirty, setCapabilitiesDirty] = useState(false);
  const [created, setCreated] = useState<any | null>(null);
  const [revoke, setRevoke] = useState<HadesBootstrapToken | null>(null);
  const [workspaceId, setWorkspaceId] = useState("");
  const [jobPayload, setJobPayload] = useState('{"paths":["README.md"]}');
  const [busy, setBusy] = useState(false);
  const [copied, setCopied] = useState<string | null>(null);

  useEffect(() => {
    const first = state.data?.projects[0];
    if (!projectId && first) setProjectId(first.id);
  }, [projectId, state.data]);

  useEffect(() => {
    const catalog = state.data?.supported_capabilities;
    if (Array.isArray(catalog)) {
      setCapabilities((current) => capabilitiesDirty
        ? current.filter((capability) => catalog.includes(capability))
        : catalog);
    }
  }, [capabilitiesDirty, state.data]);

  useEffect(() => {
    const first = state.data?.workspaces.find((workspace) => workspace.project_id === projectId);
    setWorkspaceId(first?.id ?? "");
  }, [projectId, state.data]);

  const selectedProject = state.data?.projects.find((project) => project.id === projectId);
  const projectWorkspaces = useMemo(
    () => state.data?.workspaces.filter((workspace) => workspace.project_id === projectId) ?? [],
    [projectId, state.data],
  );

  const toggleCapability = (capability: HadesCapability) => {
    setCapabilitiesDirty(true);
    setCapabilities((current) => current.includes(capability)
      ? current.filter((item) => item !== capability)
      : [...current, capability]);
  };

  const copy = (value: string, key: string) => {
    navigator.clipboard?.writeText(value);
    setCopied(key);
    setTimeout(() => setCopied(null), 1500);
  };

  const createBootstrap = async (event: React.FormEvent) => {
    event.preventDefault();
    if (!projectId) {
      toast.error("Select a project first.");
      return;
    }

    setBusy(true);
    try {
      const payload = await api.createHadesBootstrapToken({
        project_id: projectId,
        name: name.trim() || "Local Hades bootstrap",
        expires_in_days: Number.parseInt(expiresInDays, 10) || 90,
        allowed_capabilities: capabilities,
        base_url: window.location.origin,
        project_name: selectedProject?.name,
      });
      setCreated(payload);
      state.reload();
      toast.success("Bootstrap token created");
    } catch (err: any) {
      toast.error(err?.message || "Bootstrap token creation failed");
    } finally {
      setBusy(false);
    }
  };

  const revokeToken = async () => {
    if (!revoke) return;
    await api.revokeHadesBootstrapToken(revoke.id);
    toast.success("Bootstrap token revoked");
    setRevoke(null);
    state.reload();
  };

  const createJob = async (event: React.FormEvent) => {
    event.preventDefault();
    if (!workspaceId) {
      toast.error("No linked workspace selected.");
      return;
    }

    let payload: Record<string, unknown>;
    try {
      payload = JSON.parse(jobPayload);
    } catch {
      toast.error("Job payload must be valid JSON.");
      return;
    }

    setBusy(true);
    try {
      await api.createHadesJob({
        project_id: projectId,
        workspace_binding_id: workspaceId,
        capability: "read_files",
        policy: "manual_review",
        requires_confirmation: true,
        payload,
      });
      state.reload();
      toast.success("Hades job queued");
    } catch (err: any) {
      toast.error(err?.message || "Job creation failed");
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="space-y-5" data-testid="hades-admin-page">
      <PageHeader
        title="Admin - Hades"
        subtitle="Provision local Hades agents and monitor project-scoped backend bindings."
        actions={<Button size="sm" variant="outline" onClick={state.reload}><RefreshCw className="mr-1.5 h-3.5 w-3.5" /> Refresh</Button>}
      />

      <DataState state={state} loadingRows={6}>
        {(snapshot) => (
          <HadesContent
            snapshot={snapshot}
            projectId={projectId}
            setProjectId={setProjectId}
            name={name}
            setName={setName}
            expiresInDays={expiresInDays}
            setExpiresInDays={setExpiresInDays}
            capabilities={capabilities}
            toggleCapability={toggleCapability}
            supportedCapabilities={Array.isArray(snapshot.supported_capabilities) ? snapshot.supported_capabilities : null}
            created={created}
            createBootstrap={createBootstrap}
            busy={busy}
            copied={copied}
            copy={copy}
            setRevoke={setRevoke}
            projectWorkspaces={projectWorkspaces}
            workspaceId={workspaceId}
            setWorkspaceId={setWorkspaceId}
            jobPayload={jobPayload}
            setJobPayload={setJobPayload}
            createJob={createJob}
          />
        )}
      </DataState>

      <ConfirmDialog
        open={!!revoke}
        onOpenChange={(open) => !open && setRevoke(null)}
        title="Revoke Hades bootstrap token"
        confirmLabel="Revoke token"
        destructive
        description={<>Revoking <span className="font-mono">{revoke?.name}</span> prevents new Hades agents from registering with this bootstrap token.</>}
        requireApproval="I understand this bootstrap token will stop working immediately."
        onConfirm={revokeToken}
        testId="hades-revoke-confirm"
      />
    </div>
  );
}

function HadesContent({
  snapshot, projectId, setProjectId, name, setName, expiresInDays, setExpiresInDays,
  capabilities, toggleCapability, supportedCapabilities, created, createBootstrap, busy, copied, copy,
  setRevoke, projectWorkspaces, workspaceId, setWorkspaceId, jobPayload, setJobPayload, createJob,
}: {
  snapshot: HadesAdminSnapshot;
  projectId: string;
  setProjectId: (value: string) => void;
  name: string;
  setName: (value: string) => void;
  expiresInDays: string;
  setExpiresInDays: (value: string) => void;
  capabilities: HadesCapability[];
  toggleCapability: (capability: HadesCapability) => void;
  supportedCapabilities: HadesCapability[] | null;
  created: any | null;
  createBootstrap: (event: React.FormEvent) => void;
  busy: boolean;
  copied: string | null;
  copy: (value: string, key: string) => void;
  setRevoke: (token: HadesBootstrapToken) => void;
  projectWorkspaces: HadesAdminSnapshot["workspaces"];
  workspaceId: string;
  setWorkspaceId: (value: string) => void;
  jobPayload: string;
  setJobPayload: (value: string) => void;
  createJob: (event: React.FormEvent) => void;
}) {
  if (snapshot.projects.length === 0) {
    return <EmptyState title="No active projects" hint="Create or restore a project before provisioning Hades." />;
  }

  return (
    <div className="space-y-5">
      <Panel title={<span className="inline-flex items-center gap-1.5"><KeyRound className="h-4 w-4 text-muted-foreground" />Bootstrap tokens</span>}>
        <form onSubmit={createBootstrap} className="grid gap-3 lg:grid-cols-[minmax(180px,1fr)_minmax(180px,1fr)_120px_auto]">
          <NativeSelect label="Project" value={projectId} onChange={setProjectId} options={snapshot.projects.map((project) => [project.id, project.name])} />
          <Field label="Token name" value={name} onChange={setName} />
          <Field label="Days" type="number" value={expiresInDays} onChange={setExpiresInDays} />
          <Button className="mt-5" disabled={busy || !projectId || supportedCapabilities === null} type="submit"><KeyRound className="mr-1.5 h-3.5 w-3.5" /> Create</Button>
        </form>
        <div className="mt-3 flex flex-wrap gap-2">
          {supportedCapabilities === null ? (
            <div role="status" className="text-sm text-amber-700 dark:text-amber-300">
              Backend upgrade required before Hades capability grants can be managed.
            </div>
          ) : supportedCapabilities.map((capability) => (
            <button
              key={capability}
              type="button"
              onClick={() => toggleCapability(capability)}
              className={`rounded-md border px-2.5 py-1 text-xs font-mono ${capabilities.includes(capability) ? "border-primary bg-primary/10 text-primary" : "border-border text-muted-foreground"}`}
            >
              {capability}
            </button>
          ))}
        </div>
        {created ? <OneTimeInstall payload={created} copied={copied} copy={copy} /> : null}
      </Panel>

      <Panel title={<span className="inline-flex items-center gap-1.5"><Play className="h-4 w-4 text-muted-foreground" />Manual job</span>}>
        <form onSubmit={createJob} className="grid gap-3 lg:grid-cols-[minmax(180px,1fr)_minmax(220px,2fr)_auto]">
          <NativeSelect label="Workspace" value={workspaceId} onChange={setWorkspaceId} options={projectWorkspaces.map((workspace) => [workspace.id, workspace.display_path])} empty="No linked workspace" />
          <label className="text-sm">
            <span className="mb-1 block text-xs text-muted-foreground">Payload JSON</span>
            <Textarea className="min-h-9 font-mono text-xs" value={jobPayload} onChange={(event) => setJobPayload(event.target.value)} />
          </label>
          <Button className="mt-5" disabled={busy || !workspaceId} type="submit"><Play className="mr-1.5 h-3.5 w-3.5" /> Queue</Button>
        </form>
      </Panel>

      <TokensTable tokens={snapshot.bootstrapTokens} setRevoke={setRevoke} />
      <SimpleTable title="Linked workspaces" rows={snapshot.workspaces} columns={["project_name", "display_path", "agent_label", "status", "declared_capabilities", "effective_capabilities", "updated_at"]} />
      <SimpleTable title="Jobs" rows={snapshot.jobs} columns={["id", "capability", "status", "policy", "created_at"]} />
      <MemoryProposalTable rows={snapshot.memoryProposals} />
    </div>
  );
}

function OneTimeInstall({ payload, copied, copy }: { payload: any; copied: string | null; copy: (value: string, key: string) => void }) {
  return (
    <div className="mt-4 rounded-md border border-amber-500/30 bg-amber-500/5 p-3 text-sm">
      <div className="flex items-center gap-2 font-medium text-amber-700 dark:text-amber-300"><ShieldCheck className="h-4 w-4" /> Copy these commands now</div>
      <CopyLine label="POSIX" value={payload.install.posix} copied={copied === "posix"} onCopy={() => copy(payload.install.posix, "posix")} />
      <CopyLine label="Windows" value={payload.install.windows} copied={copied === "windows"} onCopy={() => copy(payload.install.windows, "windows")} />
    </div>
  );
}

function CopyLine({ label, value, copied, onCopy }: { label: string; value: string; copied: boolean; onCopy: () => void }) {
  return (
    <div className="mt-2 flex min-w-0 items-center gap-2">
      <span className="w-16 shrink-0 text-xs text-muted-foreground">{label}</span>
      <code className="min-w-0 flex-1 overflow-x-auto rounded-md border border-border bg-background px-2 py-1.5 text-xs">{value}</code>
      <Button type="button" size="sm" variant="outline" onClick={onCopy}>{copied ? <Check className="h-3.5 w-3.5" /> : <Clipboard className="h-3.5 w-3.5" />}</Button>
    </div>
  );
}

function TokensTable({ tokens, setRevoke }: { tokens: HadesBootstrapToken[]; setRevoke: (token: HadesBootstrapToken) => void }) {
  return (
    <Panel title="Bootstrap tokens" dense>
      <div className="overflow-x-auto">
        <table className="w-full min-w-[920px] text-left text-sm">
          <thead>
            <tr className="border-b border-border text-[11px] uppercase tracking-wide text-muted-foreground">
              <th className="px-4 py-2 font-medium">Name</th>
              <th className="px-3 py-2 font-medium">Project</th>
              <th className="px-3 py-2 font-medium">Prefix</th>
              <th className="px-3 py-2 font-medium">Allowed capabilities</th>
              <th className="px-3 py-2 font-medium">Last used</th>
              <th className="px-3 py-2 font-medium">Status</th>
              <th className="px-3 py-2 text-right font-medium">Actions</th>
            </tr>
          </thead>
          <tbody>{tokens.map((token) => (
            <tr key={token.id} className="border-b border-border/60 last:border-0 hover:bg-accent/30">
              <td className="px-4 py-2.5 font-medium">{token.name}</td>
              <td className="px-3 py-2.5 text-xs text-muted-foreground">{token.project_name || token.project_id}</td>
              <td className="px-3 py-2.5 font-mono text-xs">{token.token_prefix}<span className="text-muted-foreground">...hidden</span></td>
              <td className="max-w-sm px-3 py-2.5 text-xs text-muted-foreground">{formatCapabilities(token.allowed_capabilities)}</td>
              <td className="px-3 py-2.5 text-xs text-muted-foreground">{time(token.last_used_at)}</td>
              <td className="px-3 py-2.5"><Pill tone={token.revoked_at ? "red" : "green"}>{token.revoked_at ? "Revoked" : "Active"}</Pill></td>
              <td className="px-3 py-2.5 text-right"><Button size="sm" variant="ghost" disabled={Boolean(token.revoked_at)} onClick={() => setRevoke(token)}><Trash2 className="h-3.5 w-3.5" /></Button></td>
            </tr>
          ))}</tbody>
        </table>
      </div>
      {tokens.length === 0 ? <div className="px-4 py-8 text-center text-sm text-muted-foreground">No bootstrap tokens yet.</div> : null}
    </Panel>
  );
}

function MemoryProposalTable({ rows }: { rows: HadesMemoryProposal[] }) {
  return (
    <Panel title="Memory proposals" dense>
      <div className="overflow-x-auto">
        <table className="w-full min-w-[860px] text-left text-sm">
          <thead><tr className="border-b border-border text-[11px] uppercase tracking-wide text-muted-foreground">
            <th className="px-4 py-2 font-medium">Intent</th><th className="px-3 py-2 font-medium">Action</th><th className="px-3 py-2 font-medium">Summary</th><th className="px-3 py-2 font-medium">Status</th><th className="px-3 py-2 font-medium">Created</th>
          </tr></thead>
          <tbody>{rows.map((row) => (
            <tr key={row.id} className="border-b border-border/60 last:border-0 hover:bg-accent/30">
              <td className="px-4 py-2.5 font-mono text-xs">{row.intent || "n/a"}</td>
              <td className="px-3 py-2.5 font-mono text-xs">{row.action}</td>
              <td className="max-w-md truncate px-3 py-2.5 text-xs text-muted-foreground">{row.summary || "n/a"}</td>
              <td className="px-3 py-2.5"><Pill tone={statusTone(row.status)}>{row.status}</Pill></td>
              <td className="px-3 py-2.5 text-xs text-muted-foreground">{time(row.created_at)}</td>
            </tr>
          ))}</tbody>
        </table>
      </div>
      {rows.length === 0 ? <div className="px-4 py-8 text-center text-sm text-muted-foreground">No memory proposals yet.</div> : null}
    </Panel>
  );
}

function SimpleTable({ title, rows, columns }: { title: string; rows: any[]; columns: string[] }) {
  return (
    <Panel title={title} dense>
      <div className="overflow-x-auto">
        <table className="w-full min-w-[860px] text-left text-sm">
          <thead><tr className="border-b border-border text-[11px] uppercase tracking-wide text-muted-foreground">{columns.map((column) => <th key={column} className="px-4 py-2 font-medium">{column}</th>)}</tr></thead>
          <tbody>{rows.map((row) => (
            <tr key={row.id} className="border-b border-border/60 last:border-0 hover:bg-accent/30">
              {columns.map((column) => (
                <td key={column} className="max-w-xs truncate px-4 py-2.5 font-mono text-xs">
                  {column === "status"
                    ? <Pill tone={statusTone(row[column])}>{String(row[column] ?? "n/a")}</Pill>
                    : column.endsWith("_at")
                      ? time(row[column])
                      : column === "declared_capabilities" || column === "effective_capabilities"
                        ? formatCapabilities(row[column])
                        : String(row[column] ?? "")}
                </td>
              ))}
            </tr>
          ))}</tbody>
        </table>
      </div>
      {rows.length === 0 ? <div className="px-4 py-8 text-center text-sm text-muted-foreground">No records yet.</div> : null}
    </Panel>
  );
}

function formatCapabilities(value: HadesCapability[] | null | undefined): string {
  if (value === undefined) return "Not reported";
  if (value === null) return "All supported (default)";
  if (!Array.isArray(value) || value.length === 0) return "None";
  return value.join(" · ");
}

function Field({ label, value, onChange, type = "text" }: { label: string; value: string; onChange: (value: string) => void; type?: string }) {
  return (
    <label className="text-sm">
      <span className="mb-1 block text-xs text-muted-foreground">{label}</span>
      <Input type={type} value={value} autoComplete="off" onChange={(event) => onChange(event.target.value)} />
    </label>
  );
}

function NativeSelect({ label, value, onChange, options, empty }: { label: string; value: string; onChange: (value: string) => void; options: string[][]; empty?: string }) {
  return (
    <label className="text-sm">
      <span className="mb-1 block text-xs text-muted-foreground">{label}</span>
      <select className="h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm" value={value} onChange={(event) => onChange(event.target.value)}>
        {options.length === 0 && <option value="">{empty || "None"}</option>}
        {options.map(([optionValue, optionLabel]) => <option key={optionValue} value={optionValue}>{optionLabel}</option>)}
      </select>
    </label>
  );
}
