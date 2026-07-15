import React, { useEffect, useMemo, useState } from "react";
import { Link, useParams } from "react-router-dom";
import { toast } from "sonner";
import { api } from "@/api/devboardApi";
import { useApi } from "@/hooks/useApi";
import { useAuth } from "@/context/AuthContext";
import { PageHeader, MetricCard, Panel } from "@/components/devboard/Layout";
import { DataState } from "@/components/devboard/DataState";
import { Pill } from "@/components/devboard/Badges";
import { Button } from "@/components/ui/button";
import {
  Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle,
} from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from "@/components/ui/select";
import { Textarea } from "@/components/ui/textarea";
import { relativeTime, titleCase } from "@/lib/format";
import {
  ArrowDownToLine, Boxes, Brain, CircleAlert, Eye, Loader2, MessageSquare, Pencil, Plus, Trash2, Workflow, XCircle,
} from "lucide-react";
import {
  KanbanBoard, MemoryCompleteness, MemoryCreateInput, MemoryUpdateInput,
  MemoryEntryKind, ProjectDetail, ProjectMemoryDomain, ProjectMemoryEntry, ProjectMemoryImportBatch,
  ProjectMemoryImportConflictPolicy, ProjectMemoryImportDedupeStrategy, ProjectMemoryImportInput,
  ProjectMemoryImportMode, ProjectMemoryResponse, ProjectWorkspaceBinding, Role,
} from "@/types/devboard";

const NONE = "__none__";

const SOURCE_TONE: Record<string, any> = {
  dashboard_user: "blue",
  user_inserted: "blue",
  server_agent: "violet",
  hades_agent: "violet",
  local_agent: "teal",
  system_event: "slate",
};

const SOURCE_LABEL: Record<string, string> = {
  dashboard_user: "Dashboard user",
  user_inserted: "Inserito dall'utente",
  server_agent: "Server agent",
  hades_agent: "Hades agent",
  local_agent: "Local agent",
  system_event: "System event",
};

const COMPLETENESS_TONE: Record<MemoryCompleteness, any> = {
  complete: "green",
  incomplete: "amber",
};

const MEMORY_KIND_OPTIONS: MemoryEntryKind[] = [
  "decision",
  "implementation",
  "clarification",
  "risk",
  "verification",
  "logbook",
  "handoff",
  "incident",
];

const COMPLETENESS_OPTIONS: MemoryCompleteness[] = ["complete", "incomplete"];
const IMPORT_MODES: ProjectMemoryImportMode[] = ["copy_as_proposals"];
const IMPORT_DEDUPE: ProjectMemoryImportDedupeStrategy = "summary_payload_hash";
const IMPORT_CONFLICT: ProjectMemoryImportConflictPolicy = "proposal";
const MEMORY_DOMAINS: Array<ProjectMemoryDomain | "all"> = ["all", "logbook", "wiki", "agent_notes"];

type MemoryFormState = {
  summary: string;
  kind: MemoryEntryKind;
  completeness: MemoryCompleteness;
  repositoryId: string;
  taskId: string;
  note: string;
};

const emptyMemoryForm: MemoryFormState = {
  summary: "",
  kind: "decision",
  completeness: "complete",
  repositoryId: NONE,
  taskId: NONE,
  note: "",
};

function canMutateRole(role: Role | null | undefined): boolean {
  return role === "admin" || role === "pm" || role === "developer";
}

function canCreateMemory(project: ProjectDetail | null | undefined, role: Role | null | undefined): boolean {
  return project?.status === "active" && canMutateRole(role);
}

function taskOptionsFromBoard(board: KanbanBoard | null | undefined, projectId: string) {
  return Object.values(board?.tasks || {})
    .filter((task) => task.project_id === projectId)
    .sort((a, b) => a.title.localeCompare(b.title));
}

function projectMatchesRoute(project: ProjectDetail | null | undefined, projectId: string | undefined): project is ProjectDetail {
  return Boolean(project && projectId && project.id === projectId);
}

function ProjectDetailStatus({ loading, error, stale }: { loading: boolean; error: string | null; stale: boolean }) {
  if (error) return <Pill tone="red">Project details unavailable</Pill>;
  if (loading) return <Pill tone="slate">Loading project</Pill>;
  if (stale) return <Pill tone="amber">Project details updating</Pill>;
  return null;
}

function memorySourceLabel(source: string): string {
  return SOURCE_LABEL[source] || titleCase(source);
}

function AddMemoryDialog({
  projectId,
  project,
  board,
  onCreated,
}: {
  projectId: string;
  project: ProjectDetail;
  board: KanbanBoard | null;
  onCreated: (entry: ProjectMemoryEntry) => void;
}) {
  const [open, setOpen] = useState(false);
  const [form, setForm] = useState<MemoryFormState>(emptyMemoryForm);
  const [saving, setSaving] = useState(false);
  const taskOptions = useMemo(() => taskOptionsFromBoard(board, projectId), [board, projectId]);

  const setDialogOpen = (nextOpen: boolean) => {
    if (saving) return;
    setOpen(nextOpen);
    if (!nextOpen) setForm(emptyMemoryForm);
  };

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    const summary = form.summary.trim();

    if (project.id !== projectId || project.status !== "active") {
      toast.error("Memory can only be added for active projects.");
      return;
    }

    if (summary.length < 8 || summary.length > 240) {
      toast.error("Summary must be 8 to 240 characters.");
      return;
    }

    if (form.repositoryId !== NONE && !project.repositories.some((repository) => repository.id === form.repositoryId)) {
      toast.error("Refresh project repositories before adding this memory.");
      return;
    }

    if (form.taskId !== NONE && !taskOptions.some((task) => task.id === form.taskId)) {
      toast.error("Refresh project tasks before adding this memory.");
      return;
    }

    const input: MemoryCreateInput = {
      kind: form.kind,
      completeness: form.completeness,
      summary,
      payload: {
        note: form.note.trim(),
        created_from: "dashboard_memory_form",
      },
    };

    if (form.repositoryId !== NONE) input.repository_id = form.repositoryId;
    if (form.taskId !== NONE) input.task_id = form.taskId;

    setSaving(true);
    try {
      const created = await api.createProjectMemory(projectId, input);
      toast.success(`Memory added: ${created.id}`);
      setOpen(false);
      setForm(emptyMemoryForm);
      onCreated(created);
    } catch (err: any) {
      toast.error(err?.message || "Memory create failed.");
    } finally {
      setSaving(false);
    }
  };

  return (
    <Dialog open={open} onOpenChange={setDialogOpen}>
      <Button size="sm" type="button" onClick={() => setDialogOpen(true)} data-testid="add-memory-btn">
        <Plus className="mr-1.5 h-3.5 w-3.5" />
        Add memory
      </Button>
      <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl" data-testid="add-memory-dialog">
        <form onSubmit={submit} className="space-y-4">
          <DialogHeader>
            <DialogTitle>Add memory</DialogTitle>
            <DialogDescription>Record a project decision, handoff, risk, or verification note. Manual entries are marked as Inserito dall'utente.</DialogDescription>
          </DialogHeader>

          <div className="space-y-3">
            <div className="space-y-1.5">
              <Label htmlFor="memory-summary" className="text-xs">Summary</Label>
              <Input
                id="memory-summary"
                value={form.summary}
                onChange={(event) => setForm((prev) => ({ ...prev, summary: event.target.value }))}
                maxLength={240}
                disabled={saving}
                data-testid="memory-summary-input"
                autoFocus
              />
            </div>

            <div className="grid gap-3 md:grid-cols-2">
              <div className="space-y-1.5">
                <Label className="text-xs">Kind</Label>
                <Select
                  value={form.kind}
                  onValueChange={(value) => setForm((prev) => ({ ...prev, kind: value as MemoryEntryKind }))}
                  disabled={saving}
                >
                  <SelectTrigger data-testid="memory-kind-select"><SelectValue /></SelectTrigger>
                  <SelectContent>
                    {MEMORY_KIND_OPTIONS.map((kind) => (
                      <SelectItem key={kind} value={kind}>{titleCase(kind)}</SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
              <div className="space-y-1.5">
                <Label className="text-xs">Completeness</Label>
                <Select
                  value={form.completeness}
                  onValueChange={(value) => setForm((prev) => ({ ...prev, completeness: value as MemoryCompleteness }))}
                  disabled={saving}
                >
                  <SelectTrigger data-testid="memory-completeness-select"><SelectValue /></SelectTrigger>
                  <SelectContent>
                    {COMPLETENESS_OPTIONS.map((completeness) => (
                      <SelectItem key={completeness} value={completeness}>{titleCase(completeness)}</SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
            </div>

            <div className="grid gap-3 md:grid-cols-2">
              <div className="space-y-1.5">
                <Label className="text-xs">Repository</Label>
                <Select
                  value={form.repositoryId}
                  onValueChange={(value) => setForm((prev) => ({ ...prev, repositoryId: value }))}
                  disabled={saving}
                >
                  <SelectTrigger data-testid="memory-repository-select"><SelectValue /></SelectTrigger>
                  <SelectContent>
                    <SelectItem value={NONE}>None</SelectItem>
                    {project.repositories.map((repository) => (
                      <SelectItem key={repository.id} value={repository.id}>{repository.name}</SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
              <div className="space-y-1.5">
                <Label className="text-xs">Task</Label>
                <Select
                  value={form.taskId}
                  onValueChange={(value) => setForm((prev) => ({ ...prev, taskId: value }))}
                  disabled={saving}
                >
                  <SelectTrigger data-testid="memory-task-select"><SelectValue /></SelectTrigger>
                  <SelectContent>
                    <SelectItem value={NONE}>None</SelectItem>
                    {taskOptions.map((task) => (
                      <SelectItem key={task.id} value={task.id}>{task.id} · {task.title}</SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
            </div>

            <div className="space-y-1.5">
              <Label htmlFor="memory-note" className="text-xs">Note</Label>
              <Textarea
                id="memory-note"
                value={form.note}
                onChange={(event) => setForm((prev) => ({ ...prev, note: event.target.value }))}
                className="min-h-28"
                disabled={saving}
                data-testid="memory-note-input"
              />
            </div>
          </div>

          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => setDialogOpen(false)} disabled={saving}>Cancel</Button>
            <Button type="submit" disabled={saving || project.status !== "active"} data-testid="memory-create-submit">
              {saving ? <Loader2 className="mr-1.5 h-3.5 w-3.5 animate-spin" /> : <Plus className="mr-1.5 h-3.5 w-3.5" />}
              Add memory
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}

function memoryKindOptionsFor(entry: ProjectMemoryEntry): MemoryEntryKind[] {
  return entry.kind === "agent_note" ? [...MEMORY_KIND_OPTIONS, "agent_note"] : MEMORY_KIND_OPTIONS;
}

function noteFromPayload(payload: Record<string, unknown>): string {
  return typeof payload.note === "string" ? payload.note : "";
}

function formFromMemoryEntry(entry: ProjectMemoryEntry): MemoryFormState {
  return {
    summary: entry.summary,
    kind: entry.kind,
    completeness: entry.completeness,
    repositoryId: entry.repository_id ?? NONE,
    taskId: entry.task_id ?? NONE,
    note: noteFromPayload(entry.payload),
  };
}

function EditMemoryDialog({
  projectId,
  project,
  board,
  entry,
  onSaved,
}: {
  projectId: string;
  project: ProjectDetail;
  board: KanbanBoard | null;
  entry: ProjectMemoryEntry;
  onSaved: (entry: ProjectMemoryEntry) => void;
}) {
  const [open, setOpen] = useState(false);
  const [form, setForm] = useState<MemoryFormState>(() => formFromMemoryEntry(entry));
  const [saving, setSaving] = useState(false);
  const taskOptions = useMemo(() => taskOptionsFromBoard(board, projectId), [board, projectId]);
  const kindOptions = useMemo(() => memoryKindOptionsFor(entry), [entry]);

  useEffect(() => {
    if (!open) return;
    setForm(formFromMemoryEntry(entry));
  }, [entry, open]);

  const setDialogOpen = (nextOpen: boolean) => {
    if (saving) return;
    setOpen(nextOpen);
  };

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    const summary = form.summary.trim();

    if (project.id !== projectId || project.status !== "active") {
      toast.error("Memory can only be edited for active projects.");
      return;
    }

    if (summary.length < 8 || summary.length > 240) {
      toast.error("Summary must be 8 to 240 characters.");
      return;
    }

    if (form.repositoryId !== NONE && !project.repositories.some((repository) => repository.id === form.repositoryId)) {
      toast.error("Refresh project repositories before editing this memory.");
      return;
    }

    if (form.taskId !== NONE && !taskOptions.some((task) => task.id === form.taskId)) {
      toast.error("Refresh project tasks before editing this memory.");
      return;
    }

    const input: MemoryUpdateInput = {
      repository_id: form.repositoryId === NONE ? null : form.repositoryId,
      task_id: form.taskId === NONE ? null : form.taskId,
      run_id: entry.run_id,
      kind: form.kind,
      completeness: form.completeness,
      summary,
      payload: {
        ...entry.payload,
        note: form.note.trim(),
        updated_from: "dashboard_memory_form",
      },
    };

    setSaving(true);
    try {
      const updated = await api.updateProjectMemory(projectId, entry.id, input);
      toast.success("Memory updated");
      setDialogOpen(false);
      onSaved(updated);
    } catch (err: any) {
      toast.error(err?.message || "Memory update failed.");
    } finally {
      setSaving(false);
    }
  };

  return (
    <Dialog open={open} onOpenChange={setDialogOpen}>
      <Button size="icon" variant="ghost" type="button" aria-label={`Edit memory ${entry.id}`} onClick={() => setDialogOpen(true)} data-testid={`edit-memory-${entry.id}`}>
        <Pencil className="h-4 w-4" />
      </Button>
      <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl" data-testid="edit-memory-dialog">
        <form onSubmit={submit} className="space-y-4">
          <DialogHeader>
            <DialogTitle>Edit memory</DialogTitle>
            <DialogDescription>Update this project memory entry. The original source remains unchanged.</DialogDescription>
          </DialogHeader>

          <div className="space-y-3">
            <div className="space-y-1.5">
              <Label htmlFor={`memory-edit-summary-${entry.id}`} className="text-xs">Summary</Label>
              <Input
                id={`memory-edit-summary-${entry.id}`}
                value={form.summary}
                onChange={(event) => setForm((prev) => ({ ...prev, summary: event.target.value }))}
                maxLength={240}
                disabled={saving}
                data-testid="memory-edit-summary-input"
                autoFocus
              />
            </div>

            <div className="grid gap-3 md:grid-cols-2">
              <div className="space-y-1.5">
                <Label className="text-xs">Kind</Label>
                <Select value={form.kind} onValueChange={(value) => setForm((prev) => ({ ...prev, kind: value as MemoryEntryKind }))} disabled={saving}>
                  <SelectTrigger data-testid="memory-edit-kind-select"><SelectValue /></SelectTrigger>
                  <SelectContent>
                    {kindOptions.map((kind) => (
                      <SelectItem key={kind} value={kind}>{titleCase(kind)}</SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
              <div className="space-y-1.5">
                <Label className="text-xs">Completeness</Label>
                <Select value={form.completeness} onValueChange={(value) => setForm((prev) => ({ ...prev, completeness: value as MemoryCompleteness }))} disabled={saving}>
                  <SelectTrigger data-testid="memory-edit-completeness-select"><SelectValue /></SelectTrigger>
                  <SelectContent>
                    {COMPLETENESS_OPTIONS.map((completeness) => (
                      <SelectItem key={completeness} value={completeness}>{titleCase(completeness)}</SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
            </div>

            <div className="grid gap-3 md:grid-cols-2">
              <div className="space-y-1.5">
                <Label className="text-xs">Repository</Label>
                <Select value={form.repositoryId} onValueChange={(value) => setForm((prev) => ({ ...prev, repositoryId: value }))} disabled={saving}>
                  <SelectTrigger data-testid="memory-edit-repository-select"><SelectValue /></SelectTrigger>
                  <SelectContent>
                    <SelectItem value={NONE}>None</SelectItem>
                    {project.repositories.map((repository) => (
                      <SelectItem key={repository.id} value={repository.id}>{repository.name}</SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
              <div className="space-y-1.5">
                <Label className="text-xs">Task</Label>
                <Select value={form.taskId} onValueChange={(value) => setForm((prev) => ({ ...prev, taskId: value }))} disabled={saving}>
                  <SelectTrigger data-testid="memory-edit-task-select"><SelectValue /></SelectTrigger>
                  <SelectContent>
                    <SelectItem value={NONE}>None</SelectItem>
                    {taskOptions.map((task) => (
                      <SelectItem key={task.id} value={task.id}>{task.id} · {task.title}</SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
            </div>

            <div className="space-y-1.5">
              <Label htmlFor={`memory-edit-note-${entry.id}`} className="text-xs">Note</Label>
              <Textarea
                id={`memory-edit-note-${entry.id}`}
                value={form.note}
                onChange={(event) => setForm((prev) => ({ ...prev, note: event.target.value }))}
                className="min-h-28"
                disabled={saving}
                data-testid="memory-edit-note-input"
              />
            </div>
          </div>

          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => setDialogOpen(false)} disabled={saving}>Cancel</Button>
            <Button type="submit" disabled={saving || project.status !== "active"} data-testid="memory-update-submit">
              {saving ? <Loader2 className="mr-1.5 h-3.5 w-3.5 animate-spin" /> : <Pencil className="mr-1.5 h-3.5 w-3.5" />}
              Save
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}

type ImportFormState = {
  sourceWorkspaceId: string;
  targetWorkspaceId: string;
  mode: ProjectMemoryImportMode;
  reason: string;
};

const emptyImportForm: ImportFormState = {
  sourceWorkspaceId: NONE,
  targetWorkspaceId: NONE,
  mode: "copy_as_proposals",
  reason: "",
};

function workspaceLabel(bindings: ProjectWorkspaceBinding[], bindingId: string | null | undefined): string {
  return bindings.find((binding) => binding.id === bindingId)?.display_path || bindingId || "workspace";
}

function importStatusTone(batch: ProjectMemoryImportBatch): "green" | "amber" | "red" | "blue" | "slate" {
  if (batch.status === "failed" || batch.status === "cancelled" || batch.review_status === "cancelled") return "red";
  if (batch.review_status === "review_pending") return "amber";
  if (batch.status === "running") return "blue";
  if (batch.status === "completed" || batch.review_status === "applied" || batch.review_status === "no_action_required") return "green";
  return "slate";
}

function importStatusLabel(batch: ProjectMemoryImportBatch): string {
  if (batch.status === "cancelled" || batch.review_status === "cancelled") return "Cancelled";
  if (batch.review_status === "review_pending") return "Review pending";
  if (batch.review_status === "applied") return "Applied";
  if (batch.review_status === "no_action_required") return "No action";
  return titleCase(batch.status);
}

function canCancelImportBatch(batch: ProjectMemoryImportBatch): boolean {
  const cancelled = batch.counts.cancelled ?? 0;
  return batch.status !== "cancelled" && batch.review_status !== "cancelled" && batch.counts.proposals_created > cancelled;
}

function importItemTone(status: string): "green" | "amber" | "red" | "blue" | "slate" {
  if (status === "proposal_created") return "amber";
  if (status === "proposal_cancelled" || status === "cancelled") return "red";
  if (status === "accepted_created" || status === "proposal_accepted") return "green";
  if (status === "duplicate_skipped") return "slate";
  return "blue";
}

function MemoryImportDetailDialog({
  projectId,
  batch,
  canCancel,
  onOpenChange,
  onChanged,
}: {
  projectId: string;
  batch: ProjectMemoryImportBatch | null;
  canCancel: boolean;
  onOpenChange: (open: boolean) => void;
  onChanged: () => void;
}) {
  const detailState = useApi(
    () => batch ? api.getProjectMemoryImport(projectId, batch.id) : Promise.resolve(null),
    [projectId, batch?.id],
  );
  const detail = detailState.data || batch;
  const [canceling, setCanceling] = useState(false);
  const items = detail?.items || [];
  const cancelable = Boolean(detail && canCancel && canCancelImportBatch(detail));

  const cancelBatch = async () => {
    if (!detail || canceling) return;
    if (!window.confirm(`Cancel pending proposals for import ${detail.id}?`)) return;

    setCanceling(true);
    try {
      await api.cancelProjectMemoryImport(projectId, detail.id, "Cancelled from memory import details.");
      toast.success("Memory import cancelled");
      onChanged();
      onOpenChange(false);
    } catch (err: any) {
      toast.error(err?.message || "Memory import cancel failed.");
    } finally {
      setCanceling(false);
    }
  };

  return (
    <Dialog open={!!batch} onOpenChange={(open) => { if (!canceling) onOpenChange(open); }}>
      <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-4xl" data-testid="memory-import-detail-dialog">
        <DialogHeader>
          <DialogTitle>{detail?.id || "Memory import"}</DialogTitle>
          <DialogDescription>{detail?.reason || "Workspace memory import details."}</DialogDescription>
        </DialogHeader>

        {detailState.loading && !detail && (
          <div className="flex items-center gap-2 rounded-md border border-border bg-muted/20 px-3 py-6 text-sm text-muted-foreground">
            <Loader2 className="h-4 w-4 animate-spin" />
            Loading import
          </div>
        )}
        {detailState.error && <div className="rounded-md border border-red-500/30 bg-red-500/5 px-3 py-2 text-sm text-red-600 dark:text-red-400">{detailState.error}</div>}
        {detail && (
          <div className="space-y-4">
            <div className="grid gap-3 md:grid-cols-2 lg:grid-cols-4">
              <div className="rounded-md border border-border bg-muted/20 px-3 py-2">
                <p className="text-[11px] uppercase tracking-wide text-muted-foreground">Status</p>
                <div className="mt-1"><Pill tone={importStatusTone(detail)}>{importStatusLabel(detail)}</Pill></div>
              </div>
              <div className="rounded-md border border-border bg-muted/20 px-3 py-2">
                <p className="text-[11px] uppercase tracking-wide text-muted-foreground">Found</p>
                <p className="mt-1 text-sm font-medium">{detail.counts.entries_found}</p>
              </div>
              <div className="rounded-md border border-border bg-muted/20 px-3 py-2">
                <p className="text-[11px] uppercase tracking-wide text-muted-foreground">Proposals</p>
                <p className="mt-1 text-sm font-medium">{detail.counts.proposals_created}</p>
              </div>
              <div className="rounded-md border border-border bg-muted/20 px-3 py-2">
                <p className="text-[11px] uppercase tracking-wide text-muted-foreground">Skipped</p>
                <p className="mt-1 text-sm font-medium">{detail.counts.skipped_duplicates}</p>
              </div>
            </div>

            <div className="max-w-full overflow-x-auto rounded-md border border-border/70">
              <table className="w-max min-w-[820px] text-sm">
                <thead>
                  <tr className="border-b border-border text-left text-[11px] uppercase tracking-wide text-muted-foreground">
                    <th className="px-3 py-2 font-medium">Item</th>
                    <th className="px-3 py-2 font-medium">Status</th>
                    <th className="px-3 py-2 font-medium">Proposal</th>
                    <th className="px-3 py-2 font-medium">Target memory</th>
                    <th className="px-3 py-2 font-medium">Reason</th>
                  </tr>
                </thead>
                <tbody>
                  {items.length === 0 && (
                    <tr><td colSpan={5} className="px-3 py-8 text-center text-sm text-muted-foreground">No import items returned.</td></tr>
                  )}
                  {items.map((item) => (
                    <tr key={item.id} className="border-b border-border/60 last:border-0">
                      <td className="px-3 py-2.5">
                        <div className="font-mono text-xs">{item.source_local_id || item.source_hash}</div>
                        <div className="mt-1 font-mono text-[11px] text-muted-foreground">{item.id}</div>
                      </td>
                      <td className="px-3 py-2.5"><Pill tone={importItemTone(item.status)}>{titleCase(item.status)}</Pill></td>
                      <td className="px-3 py-2.5 font-mono text-xs text-muted-foreground">{item.proposal_id || "—"}</td>
                      <td className="px-3 py-2.5 font-mono text-xs text-muted-foreground">{item.target_memory_entry_id || "—"}</td>
                      <td className="px-3 py-2.5 text-xs text-muted-foreground">{item.conflict_reason || "—"}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        )}

        <DialogFooter>
          <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={canceling}>Close</Button>
          {cancelable && (
            <Button type="button" variant="destructive" onClick={cancelBatch} disabled={canceling} data-testid="memory-import-cancel-submit">
              {canceling ? <Loader2 className="mr-1.5 h-3.5 w-3.5 animate-spin" /> : <XCircle className="mr-1.5 h-3.5 w-3.5" />}
              Cancel proposals
            </Button>
          )}
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

function MemoryImportPanel({ projectId, canImport }: { projectId: string; canImport: boolean }) {
  const workspaceState = useApi(() => api.getProjectWorkspaceBindings(projectId), [projectId]);
  const importState = useApi(() => api.getProjectMemoryImports(projectId), [projectId]);
  const [form, setForm] = useState<ImportFormState>(emptyImportForm);
  const [saving, setSaving] = useState(false);
  const [selectedBatch, setSelectedBatch] = useState<ProjectMemoryImportBatch | null>(null);
  const [cancelingBatchId, setCancelingBatchId] = useState<string | null>(null);
  const bindings = useMemo(() => workspaceState.data || [], [workspaceState.data]);
  const batches = importState.data || [];

  useEffect(() => {
    if (bindings.length === 0) return;
    setForm((current) => {
      const source = current.sourceWorkspaceId === NONE ? bindings[0].id : current.sourceWorkspaceId;
      const target = current.targetWorkspaceId === NONE ? (bindings[1]?.id || bindings[0].id) : current.targetWorkspaceId;
      return { ...current, sourceWorkspaceId: source, targetWorkspaceId: target };
    });
  }, [bindings]);

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    if (!canImport) return;
    if (form.sourceWorkspaceId === NONE || form.targetWorkspaceId === NONE) {
      toast.error("Select source and target workspaces.");
      return;
    }

    const input: ProjectMemoryImportInput = {
      source_workspace_binding_id: form.sourceWorkspaceId,
      target_workspace_binding_id: form.targetWorkspaceId,
      mode: form.mode,
      filters: { limit: 50 },
      dedupe_strategy: IMPORT_DEDUPE,
      conflict_policy: IMPORT_CONFLICT,
      reason: form.reason.trim() || "Import workspace memory into this project.",
    };

    setSaving(true);
    try {
      const batch = await api.createProjectMemoryImport(projectId, input);
      toast.success(`Memory import proposals ready: ${batch.id}`);
      setForm((current) => ({ ...current, reason: "" }));
      importState.reload();
      workspaceState.reload();
    } catch (err: any) {
      toast.error(err?.message || "Memory import failed.");
    } finally {
      setSaving(false);
    }
  };

  const reloadImports = () => {
    importState.reload();
    workspaceState.reload();
  };

  const cancelBatch = async (batch: ProjectMemoryImportBatch) => {
    if (!canImport || cancelingBatchId || !canCancelImportBatch(batch)) return;
    if (!window.confirm(`Cancel pending proposals for import ${batch.id}?`)) return;

    setCancelingBatchId(batch.id);
    try {
      await api.cancelProjectMemoryImport(projectId, batch.id, "Cancelled from memory import queue.");
      toast.success("Memory import cancelled");
      reloadImports();
    } catch (err: any) {
      toast.error(err?.message || "Memory import cancel failed.");
    } finally {
      setCancelingBatchId(null);
    }
  };

  return (
    <Panel
      dense
      title={<span className="inline-flex items-center gap-1.5"><ArrowDownToLine className="h-4 w-4 text-muted-foreground" />Import from workspace</span>}
      action={<Pill tone="slate">{batches.length} imports</Pill>}
    >
      <div className="grid gap-3 p-3 xl:grid-cols-[minmax(0,0.95fr)_minmax(0,1.05fr)]">
        <form onSubmit={submit} className="grid gap-3 md:grid-cols-2 xl:grid-cols-1">
          <div className="space-y-1.5">
            <Label className="text-xs">Source workspace</Label>
            <Select
              value={form.sourceWorkspaceId}
              onValueChange={(value) => setForm((current) => ({ ...current, sourceWorkspaceId: value }))}
              disabled={!canImport || saving || workspaceState.loading || bindings.length === 0}
            >
              <SelectTrigger data-testid="memory-import-source"><SelectValue /></SelectTrigger>
              <SelectContent>
                {bindings.length === 0 && <SelectItem value={NONE}>No workspace</SelectItem>}
                {bindings.map((binding) => (
                  <SelectItem key={binding.id} value={binding.id}>{binding.display_path}</SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>

          <div className="space-y-1.5">
            <Label className="text-xs">Target workspace</Label>
            <Select
              value={form.targetWorkspaceId}
              onValueChange={(value) => setForm((current) => ({ ...current, targetWorkspaceId: value }))}
              disabled={!canImport || saving || workspaceState.loading || bindings.length === 0}
            >
              <SelectTrigger data-testid="memory-import-target"><SelectValue /></SelectTrigger>
              <SelectContent>
                {bindings.length === 0 && <SelectItem value={NONE}>No workspace</SelectItem>}
                {bindings.map((binding) => (
                  <SelectItem key={binding.id} value={binding.id}>{binding.display_path}</SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>

          <div className="space-y-1.5">
            <Label className="text-xs">Mode</Label>
            <Select
              value={form.mode}
              onValueChange={(value) => setForm((current) => ({ ...current, mode: value as ProjectMemoryImportMode }))}
              disabled={!canImport || saving}
            >
              <SelectTrigger data-testid="memory-import-mode"><SelectValue /></SelectTrigger>
              <SelectContent>
                {IMPORT_MODES.map((mode) => (
                  <SelectItem key={mode} value={mode}>{titleCase(mode)}</SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>

          <div className="space-y-1.5">
            <Label className="text-xs">Reason</Label>
            <Input
              value={form.reason}
              onChange={(event) => setForm((current) => ({ ...current, reason: event.target.value }))}
              placeholder="Why import this workspace memory"
              maxLength={180}
              disabled={!canImport || saving}
              data-testid="memory-import-reason"
            />
          </div>

          <div className="flex flex-wrap items-center gap-2 md:col-span-2 xl:col-span-1">
            <Button size="sm" type="submit" disabled={!canImport || saving || form.sourceWorkspaceId === NONE || form.targetWorkspaceId === NONE} data-testid="memory-import-submit">
              {saving ? <Loader2 className="mr-1.5 h-3.5 w-3.5 animate-spin" /> : <ArrowDownToLine className="mr-1.5 h-3.5 w-3.5" />}
              Import
            </Button>
            {!canImport && <Pill tone="slate">Read only</Pill>}
            {(workspaceState.error || importState.error) && (
              <span className="text-xs text-red-600 dark:text-red-400">{workspaceState.error || importState.error}</span>
            )}
          </div>
        </form>

        <div className="max-w-full overflow-x-auto rounded-md border border-border/70">
          <table className="w-max min-w-[780px] text-sm">
            <thead>
              <tr className="border-b border-border text-left text-[11px] uppercase tracking-wide text-muted-foreground">
                <th className="px-3 py-2 font-medium">Batch</th>
                <th className="px-3 py-2 font-medium">Source</th>
                <th className="px-3 py-2 font-medium">Mode</th>
                <th className="px-3 py-2 font-medium">Status</th>
                <th className="px-3 py-2 font-medium">Counts</th>
                <th className="px-3 py-2 text-right font-medium">Actions</th>
              </tr>
            </thead>
            <tbody>
              {batches.length === 0 && (
                <tr><td colSpan={6} className="px-3 py-8 text-center text-sm text-muted-foreground">No imports yet.</td></tr>
              )}
              {batches.map((batch) => {
                const cancelable = canImport && canCancelImportBatch(batch);
                return (
                  <tr
                    key={batch.id}
                    className="cursor-pointer border-b border-border/60 align-top transition-colors last:border-0 hover:bg-accent/30"
                    onClick={() => setSelectedBatch(batch)}
                    data-testid={`memory-import-${batch.id}`}
                  >
                    <td className="px-3 py-2.5">
                      <div className="font-mono text-xs">{batch.id}</div>
                      <div className="mt-1 text-[11px] text-muted-foreground">{relativeTime(batch.created_at)}</div>
                    </td>
                    <td className="px-3 py-2.5 text-xs">{workspaceLabel(bindings, batch.source_workspace_binding_id)}</td>
                    <td className="px-3 py-2.5"><Pill tone="slate">{titleCase(batch.mode)}</Pill></td>
                    <td className="px-3 py-2.5"><Pill tone={importStatusTone(batch)}>{importStatusLabel(batch)}</Pill></td>
                    <td className="px-3 py-2.5 text-xs text-muted-foreground">
                      {batch.counts.proposals_created} proposals · {batch.counts.skipped_duplicates} skipped
                      {(batch.counts.cancelled ?? 0) > 0 ? ` · ${batch.counts.cancelled} cancelled` : ""}
                    </td>
                    <td className="px-3 py-2.5 text-right">
                      <div className="flex justify-end gap-1.5">
                        <Button size="sm" variant="outline" type="button" onClick={(event) => { event.stopPropagation(); setSelectedBatch(batch); }} data-testid={`memory-import-detail-${batch.id}`}>
                          <Eye className="mr-1.5 h-3.5 w-3.5" />
                          Details
                        </Button>
                        {cancelable && (
                          <Button size="sm" variant="outline" type="button" onClick={(event) => { event.stopPropagation(); void cancelBatch(batch); }} disabled={cancelingBatchId === batch.id} data-testid={`memory-import-cancel-${batch.id}`}>
                            {cancelingBatchId === batch.id ? <Loader2 className="mr-1.5 h-3.5 w-3.5 animate-spin" /> : <XCircle className="mr-1.5 h-3.5 w-3.5" />}
                            Cancel
                          </Button>
                        )}
                      </div>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      </div>
      <MemoryImportDetailDialog
        projectId={projectId}
        batch={selectedBatch}
        canCancel={canImport}
        onOpenChange={(open) => { if (!open) setSelectedBatch(null); }}
        onChanged={reloadImports}
      />
    </Panel>
  );
}

export default function ProjectMemoryPage() {
  const { user } = useAuth();
  const { projectId } = useParams();
  const [recentCreatedEntry, setRecentCreatedEntry] = useState<ProjectMemoryEntry | null>(null);
  const [domain, setDomain] = useState<ProjectMemoryDomain | "all">("all");
  const [query, setQuery] = useState("");
  const [deletingEntryId, setDeletingEntryId] = useState<string | null>(null);
  const state = useApi<ProjectMemoryResponse>(
    () => projectId ? api.getProjectMemory(projectId, { domain, query }) : Promise.resolve({ domain: "all", entries: [] }),
    [projectId, domain, query],
  );
  const projectState = useApi<ProjectDetail | null>(
    () => projectId ? api.getProject(projectId) : Promise.resolve(null),
    [projectId],
  );
  const matchedProject = projectMatchesRoute(projectState.data, projectId) ? projectState.data : null;
  const projectStale = Boolean(projectState.data && projectId && projectState.data.id !== projectId);
  const canLoadOptions = Boolean(projectId && matchedProject && canMutateRole(user?.role));
  const boardState = useApi<KanbanBoard | null>(
    () => canLoadOptions && projectId ? api.getKanban(projectId) : Promise.resolve(null),
    [projectId, canLoadOptions],
  );
  const canAdd = canCreateMemory(matchedProject, user?.role);

  useEffect(() => {
    setRecentCreatedEntry(null);
    setDomain("all");
    setQuery("");
  }, [projectId]);

  const deleteEntry = async (entry: ProjectMemoryEntry) => {
    if (!projectId || deletingEntryId || entry.domain === "wiki") return;
    if (!window.confirm(`Delete memory entry ${entry.id}?`)) return;

    setDeletingEntryId(entry.id);
    try {
      await api.deleteProjectMemory(projectId, entry.id);
      toast.success("Memory deleted");
      state.reload();
      if (recentCreatedEntry?.id === entry.id) setRecentCreatedEntry(null);
    } catch (err: any) {
      toast.error(err?.message || "Memory delete failed.");
    } finally {
      setDeletingEntryId(null);
    }
  };

  if (!projectId) {
    return (
      <div className="space-y-5" data-testid="project-memory-page">
        <PageHeader
          title="Memory"
          subtitle="Shared memory is reviewed inside a project workspace."
          actions={<Button size="sm" asChild><Link to="/projects"><Boxes className="mr-1.5 h-3.5 w-3.5" /> Projects</Link></Button>}
        />
        <Panel title="Project scope required">
          <p className="text-sm text-muted-foreground">Select a project to inspect decisions, handoffs, verification notes, and agent-produced memory.</p>
        </Panel>
      </div>
    );
  }

  return (
    <div className="space-y-5" data-testid="project-memory-page">
      <PageHeader
        title="Memory"
        subtitle="Project decisions, handoffs, verification notes, and agent findings."
        meta={<Link to={`/projects/${encodeURIComponent(projectId)}`} title={`Project ${projectId}`} className="inline-flex min-w-0 max-w-full items-center gap-1 text-xs text-muted-foreground hover:text-foreground"><Boxes className="h-3.5 w-3.5 shrink-0" /><span className="break-all">Project {projectId}</span></Link>}
        actions={
          <>
            <ProjectDetailStatus loading={projectState.loading && !matchedProject} error={projectState.error} stale={projectStale} />
            {canAdd && matchedProject && projectId && (
              <AddMemoryDialog
                projectId={projectId}
                project={matchedProject}
                board={boardState.loading ? null : boardState.data}
                onCreated={(entry) => {
                  setRecentCreatedEntry(entry);
                  state.reload();
                }}
              />
            )}
            {matchedProject && matchedProject.status !== "active" && <Pill tone="slate">Inactive project</Pill>}
            <Button size="sm" variant="outline" asChild><Link to={`/projects/${projectId}/ask`}><MessageSquare className="mr-1.5 h-3.5 w-3.5" /> Ask</Link></Button>
            <Button size="sm" variant="outline" asChild><Link to={`/projects/${projectId}/kanban`}><Workflow className="mr-1.5 h-3.5 w-3.5" /> Work</Link></Button>
          </>
        }
      />

      <DataState state={state}>
        {(data) => {
          const entries = data.entries;
          const incomplete = entries.filter((entry) => entry.completeness === "incomplete").length;
          const kinds = entries.reduce<Record<MemoryEntryKind, number>>((acc, entry) => {
            acc[entry.kind] = (acc[entry.kind] || 0) + 1;
            return acc;
          }, {} as Record<MemoryEntryKind, number>);

          return (
            <>
              <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <MetricCard label="Entries" value={entries.length} />
                <MetricCard label="Incomplete" value={incomplete} tone={incomplete ? "warn" : "good"} />
                <MetricCard label="Kinds" value={Object.keys(kinds).length} />
                <MetricCard label="Latest" value={relativeTime(entries[0]?.occurred_at ?? null)} />
              </div>

              <div className="flex flex-wrap items-center gap-2">
                <Select value={domain} onValueChange={(value) => setDomain(value as ProjectMemoryDomain | "all")}>
                  <SelectTrigger className="h-8 w-56 text-xs" data-testid="memory-domain-filter">
                    <span className="text-muted-foreground">Domain:</span><SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {MEMORY_DOMAINS.map((value) => (
                      <SelectItem key={value} value={value}>
                        {value === "all" ? "All" : `${titleCase(value)}${data.domains ? ` (${data.domains[value] ?? 0})` : ""}`}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
                <Input
                  className="h-8 w-72 text-xs"
                  value={query}
                  onChange={(event) => setQuery(event.target.value)}
                  placeholder="Search memory..."
                  data-testid="memory-query-filter"
                />
              </div>

              {recentCreatedEntry && (
                <div className="flex flex-wrap items-center gap-3 rounded-md border border-border bg-muted/20 px-3 py-2.5" data-testid="recent-memory-entry">
                  <Pill tone="green">Added</Pill>
                  <Pill tone={SOURCE_TONE[recentCreatedEntry.source] || "slate"}>{memorySourceLabel(recentCreatedEntry.source)}</Pill>
                  <span className="min-w-0 flex-1 truncate text-sm">{recentCreatedEntry.summary}</span>
                  <span className="max-w-full break-all font-mono text-xs text-muted-foreground" title={recentCreatedEntry.id}>{recentCreatedEntry.id}</span>
                </div>
              )}

              <Panel title={<span className="inline-flex items-center gap-1.5"><Brain className="h-4 w-4 text-muted-foreground" />Memory stream</span>} className="min-w-0" dense>
                <div className="max-w-full overflow-x-auto">
                  <table className="w-max min-w-[1040px] text-sm">
                    <thead>
                      <tr className="border-b border-border text-left text-[11px] uppercase tracking-wide text-muted-foreground">
                        <th className="px-4 py-2 font-medium">Entry</th>
                        <th className="px-3 py-2 font-medium">Kind</th>
                        <th className="px-3 py-2 font-medium">State</th>
                        <th className="px-3 py-2 font-medium">Source</th>
                        <th className="px-3 py-2 font-medium">Linked object</th>
                        <th className="px-3 py-2 font-medium">Occurred</th>
                        <th className="sticky right-0 bg-card px-4 py-2 text-right font-medium">Actions</th>
                      </tr>
                    </thead>
                    <tbody>
                      {entries.length === 0 && (
                        <tr>
                          <td colSpan={7} className="px-4 py-10 text-center text-sm text-muted-foreground">No memory entries yet.</td>
                        </tr>
                      )}
                      {entries.map((entry) => {
                        const linked = entry.task_id || entry.run_id || entry.repository_id || "project";
                        const canEdit = canAdd && matchedProject && entry.domain !== "wiki";
                        const canDelete = canAdd && entry.domain !== "wiki";
                        return (
                          <tr key={entry.id} className="border-b border-border/60 last:border-0" data-testid={`memory-row-${entry.id}`}>
                            <td className="px-4 py-2.5">
                              <p className="font-medium">{entry.summary}</p>
                              <p className="mt-0.5 font-mono text-[11px] text-muted-foreground">{entry.id}</p>
                            </td>
                            <td className="px-3 py-2.5"><Pill tone="slate">{titleCase(entry.kind)}</Pill></td>
                            <td className="px-3 py-2.5"><Pill tone={COMPLETENESS_TONE[entry.completeness]} icon={entry.completeness === "incomplete" ? CircleAlert : undefined}>{titleCase(entry.completeness)}</Pill></td>
                            <td className="px-3 py-2.5"><Pill tone={SOURCE_TONE[entry.source] || "slate"}>{memorySourceLabel(entry.source)}</Pill></td>
                            <td className="px-3 py-2.5 font-mono text-xs text-muted-foreground">{linked}</td>
                            <td className="px-3 py-2.5 text-xs text-muted-foreground">{relativeTime(entry.occurred_at)}</td>
                            <td className="sticky right-0 bg-card px-4 py-2.5 text-right">
                              {canEdit || canDelete ? (
                                <div className="flex justify-end gap-1">
                                  {canEdit && (
                                    <EditMemoryDialog
                                      projectId={projectId}
                                      project={matchedProject}
                                      board={boardState.loading ? null : boardState.data}
                                      entry={entry}
                                      onSaved={(updated) => {
                                        setRecentCreatedEntry((current) => current?.id === updated.id ? updated : current);
                                        state.reload();
                                      }}
                                    />
                                  )}
                                  {canDelete && (
                                    <Button
                                      size="icon"
                                      variant="ghost"
                                      type="button"
                                      aria-label={`Delete memory ${entry.id}`}
                                      disabled={deletingEntryId === entry.id}
                                      onClick={() => void deleteEntry(entry)}
                                      data-testid={`delete-memory-${entry.id}`}
                                    >
                                      {deletingEntryId === entry.id ? <Loader2 className="h-4 w-4 animate-spin" /> : <Trash2 className="h-4 w-4" />}
                                    </Button>
                                  )}
                                </div>
                              ) : (
                                <span className="text-xs text-muted-foreground">—</span>
                              )}
                            </td>
                          </tr>
                        );
                      })}
                    </tbody>
                  </table>
                </div>
              </Panel>

              <details className="rounded-md border border-border bg-card" data-testid="advanced-memory-import">
                <summary className="cursor-pointer list-inside px-4 py-3 text-sm font-medium text-foreground">Advanced import <span className="ml-2 text-xs font-normal text-muted-foreground">Creates reviewable proposals; it never silently merges memory.</span></summary>
                <div className="border-t border-border">
                  <MemoryImportPanel projectId={projectId} canImport={canAdd} />
                </div>
              </details>
            </>
          );
        }}
      </DataState>
    </div>
  );
}
