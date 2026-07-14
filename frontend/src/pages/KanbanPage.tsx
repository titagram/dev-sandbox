import React, { useMemo, useState } from "react";
import { Link, useNavigate, useParams } from "react-router-dom";
import { api } from "@/api/devboardApi";
import { useApi } from "@/hooks/useApi";
import { useAuth } from "@/context/AuthContext";
import { PageHeader } from "@/components/devboard/Layout";
import { DataState } from "@/components/devboard/DataState";
import { RiskBadge, RunStatusBadge, SourceStatusBadge, Pill } from "@/components/devboard/Badges";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Button } from "@/components/ui/button";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { KanbanBoard, ProjectDetail, TaskCard } from "@/types/devboard";
import type { IntakeNormalization } from "@/types/devboard";
import { relativeTime } from "@/lib/format";
import { TaskFormFields } from "./TaskFormFields";
import { canMutateTasksForProject, emptyTaskForm, intakeToTaskForm, normalizeTaskRepositorySelection, TaskFormState, taskFormToPayload } from "./taskFormModel";
import { toast } from "sonner";
import { Ban, GitBranch, Network, FilterX, BookText, Boxes, Paperclip, Image as ImageIcon, Loader2, Plus, TextCursorInput } from "lucide-react";

const ALL = "__all__";

export default function KanbanPage() {
  const { user } = useAuth();
  const nav = useNavigate();
  const { projectId } = useParams();
  const state = useApi(() => api.getKanban(projectId), [projectId]);
  const projectState = useApi<ProjectDetail | null>(() => projectId ? api.getProject(projectId) : Promise.resolve(null), [projectId]);
  const [f, setF] = useState({ owner: ALL, repo: ALL, risk: ALL, run: ALL, source: ALL });
  const [intakeDraft, setIntakeDraft] = useState<TaskFormState | null>(null);
  const [intakeOpen, setIntakeOpen] = useState(false);

  const reset = () => setF({ owner: ALL, repo: ALL, risk: ALL, run: ALL, source: ALL });
  const active = Object.values(f).some((v) => v !== ALL);
  const canCreateTask = canMutateTasksForProject(projectState.data, user?.role);

  return (
    <div className="space-y-4" data-testid="kanban-page">
      <PageHeader
        title="Kanban"
        subtitle={projectId ? "Project-specific task board. Tasks from other projects are excluded server-side." : "PM home — scan tasks, linked runs, and source/wiki status across repositories."}
        meta={projectId && <Link to={`/projects/${projectId}`} className="inline-flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground"><Boxes className="h-3.5 w-3.5" />Project {projectId}</Link>}
        actions={
          <>
            {canCreateTask && projectState.data && (
              <>
                <Button size="sm" type="button" variant="outline" onClick={() => setIntakeOpen(true)} data-testid="kanban-intake">
                  <TextCursorInput className="mr-1.5 h-3.5 w-3.5" />
                  Intake
                </Button>
                <CreateTaskDialog
                  project={projectState.data}
                  prefillForm={intakeDraft}
                  onCreated={(taskId) => {
                    setIntakeDraft(null);
                    state.reload();
                    nav(`/tasks/${taskId}`);
                  }}
                />
              </>
            )}
            {active && <Button variant="outline" size="sm" onClick={reset} data-testid="clear-filters"><FilterX className="mr-1.5 h-3.5 w-3.5" /> Clear</Button>}
          </>
        }
      />

      <DataState state={state}>
        {(board) => {
          const tasks = Object.values(board.tasks);
          const owners = Array.from(new Set(tasks.map((t) => t.owner)));
          const repos = Array.from(new Set(tasks.flatMap((t) => t.repositories)));
          const match = (t: TaskCard) =>
            (f.owner === ALL || t.owner === f.owner) &&
            (f.repo === ALL || t.repositories.includes(f.repo)) &&
            (f.risk === ALL || t.risk === f.risk) &&
            (f.run === ALL || (f.run === "none" ? !t.linked_run_status : t.linked_run_status === f.run)) &&
            (f.source === ALL || t.source_status === f.source);

          return (
            <>
              <div className="flex flex-wrap items-center gap-2 rounded-md border border-border bg-card/50 p-2.5">
                <FilterSelect label="Owner" value={f.owner} onChange={(v) => setF({ ...f, owner: v })} options={owners} testId="filter-owner" />
                <FilterSelect label="Repository" value={f.repo} onChange={(v) => setF({ ...f, repo: v })} options={repos} testId="filter-repo" />
                <FilterSelect label="Risk" value={f.risk} onChange={(v) => setF({ ...f, risk: v })} options={["low", "medium", "high", "critical"]} testId="filter-risk" />
                <FilterSelect label="Run status" value={f.run} onChange={(v) => setF({ ...f, run: v })} options={["running", "passed", "failed", "needs_review", "reviewed", "none"]} testId="filter-run" />
                <FilterSelect label="Source status" value={f.source} onChange={(v) => setF({ ...f, source: v })} options={["verified_from_code", "developer_provided", "ai_generated", "needs_verification", "stale", "conflict_with_code"]} testId="filter-source" />
              </div>

              <Board board={board} match={match} onOpen={(id) => nav(`/tasks/${id}`)} canMutate={user?.role !== "pm"} projectId={projectId} />
            </>
          );
        }}
      </DataState>
      {projectId && (
        <IntakePreviewDialog
          open={intakeOpen}
          projectId={projectId}
          onOpenChange={setIntakeOpen}
          onUseDraft={(normalization) => {
            setIntakeDraft(intakeToTaskForm(normalization));
            setIntakeOpen(false);
            toast.success("Intake draft ready. Click 'New task' to review and create.");
          }}
        />
      )}
    </div>
  );
}

function CreateTaskDialog({ project, prefillForm, onCreated }: { project: ProjectDetail; prefillForm?: TaskFormState | null; onCreated: (taskId: string) => void }) {
  const [open, setOpen] = useState(false);
  const [form, setForm] = useState<TaskFormState>({ ...emptyTaskForm });
  const [saving, setSaving] = useState(false);

  const setDialogOpen = (nextOpen: boolean) => {
    setOpen(nextOpen);
    if (nextOpen) {
      setForm(prefillForm ? { ...prefillForm } : { ...emptyTaskForm });
    } else if (!saving) {
      setForm({ ...emptyTaskForm });
    }
  };

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    if (project.status !== "active") {
      toast.error("Tasks can only be created for active projects.");
      return;
    }

    const repositorySelection = normalizeTaskRepositorySelection(form.repositoryIds, project.repositories);
    if (repositorySelection.unresolved.length > 0) {
      toast.error("Refresh project repositories before creating this task.");
      return;
    }

    const payload = taskFormToPayload({ ...form, repositoryIds: repositorySelection.repositoryIds });

    if (!payload.title) {
      toast.error("Task title is required.");
      return;
    }

    setSaving(true);
    try {
      const created = await api.createTask(project.id, payload);
      toast.success("Task created");
      setOpen(false);
      setForm({ ...emptyTaskForm });
      onCreated(created.id);
    } catch (err: any) {
      toast.error(err?.message || "Task create failed.");
    } finally {
      setSaving(false);
    }
  };

  return (
    <Dialog open={open} onOpenChange={setDialogOpen}>
      <Button size="sm" type="button" onClick={() => setDialogOpen(true)} data-testid="kanban-new-task">
        <Plus className="mr-1.5 h-3.5 w-3.5" />
        New task
      </Button>
      <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle>New task</DialogTitle>
          <DialogDescription>Create a project-scoped task.</DialogDescription>
        </DialogHeader>
        <form onSubmit={submit} className="space-y-4">
          <TaskFormFields
            fieldIdPrefix="new-task"
            form={form}
            onChange={setForm}
            repositories={project.repositories}
            disabled={saving}
          />
          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => setDialogOpen(false)} disabled={saving}>Cancel</Button>
            <Button type="submit" disabled={saving || project.status !== "active"} data-testid="kanban-create-task-submit">
              {saving ? <Loader2 className="mr-1.5 h-3.5 w-3.5 animate-spin" /> : <Plus className="mr-1.5 h-3.5 w-3.5" />}
              Create task
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}

const INTAKE_TYPE_TONE: Record<string, "green" | "amber" | "red" | "blue" | "slate"> = {
  bug: "red",
  task: "blue",
  feature: "green",
  question: "amber",
};

function IntakePreviewDialog({
  open,
  projectId,
  onOpenChange,
  onUseDraft,
}: {
  open: boolean;
  projectId: string;
  onOpenChange: (open: boolean) => void;
  onUseDraft: (normalization: IntakeNormalization) => void;
}) {
  const [rawText, setRawText] = useState("");
  const [normalizing, setNormalizing] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [result, setResult] = useState<IntakeNormalization | null>(null);

  const normalize = async () => {
    const trimmed = rawText.trim();
    if (!trimmed) {
      setError("Please enter some text to normalize.");
      return;
    }
    setNormalizing(true);
    setError(null);
    setResult(null);
    try {
      const response = await api.normalizeIntake(projectId, trimmed);
      setResult(response.normalization);
    } catch (err: any) {
      setError(err?.message || "Normalization request failed.");
    } finally {
      setNormalizing(false);
    }
  };

  const resetDialog = () => {
    setRawText("");
    setNormalizing(false);
    setError(null);
    setResult(null);
  };

  const setOpen = (next: boolean) => {
    onOpenChange(next);
    if (!next) resetDialog();
  };

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle>Intake preview</DialogTitle>
          <DialogDescription>Paste raw text to get a structured task preview.</DialogDescription>
        </DialogHeader>
        <div className="space-y-4">
          <div className="space-y-1.5">
            <label htmlFor="intake-text" className="text-xs font-medium text-muted-foreground">
              Raw text
            </label>
            <textarea
              id="intake-text"
              className="flex min-h-24 w-full rounded-md border border-border bg-background px-3 py-2 text-sm placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50"
              placeholder="Paste a bug report, feature request, or any free-text description..."
              value={rawText}
              onChange={(e) => setRawText(e.target.value)}
              disabled={normalizing}
              data-testid="intake-textarea"
              rows={4}
            />
          </div>

          <div className="flex items-center gap-2">
            <Button size="sm" type="button" onClick={normalize} disabled={normalizing || !rawText.trim()} data-testid="intake-normalize">
              {normalizing ? <Loader2 className="mr-1.5 h-3.5 w-3.5 animate-spin" /> : <TextCursorInput className="mr-1.5 h-3.5 w-3.5" />}
              Normalize
            </Button>
            {result && (
              <Button size="sm" variant="default" type="button" onClick={() => onUseDraft(result)} data-testid="intake-use-draft">
                <Plus className="mr-1.5 h-3.5 w-3.5" />
                Use as task draft
              </Button>
            )}
          </div>

          {error && (
            <div className="rounded-md border border-destructive/50 bg-destructive/10 px-3 py-2 text-sm text-destructive" data-testid="intake-error">
              {error}
            </div>
          )}

          {result && (
            <div className="space-y-3 rounded-md border border-border bg-muted/20 p-4" data-testid="intake-preview">
              <div className="flex flex-wrap items-center gap-2">
                <Pill tone={INTAKE_TYPE_TONE[result.task_type] || "slate"}>{result.task_type}</Pill>
                {result.requires_root_cause && <Pill tone="red">Requires root cause</Pill>}
                <Pill tone="slate">Confidence {Math.round(result.confidence * 100)}%</Pill>
              </div>

              <div>
                <h4 className="text-sm font-semibold text-foreground">{result.suggested_title || "—"}</h4>
                {result.suggested_description && (
                  <p className="mt-1 whitespace-pre-wrap text-sm leading-relaxed text-muted-foreground">{result.suggested_description}</p>
                )}
              </div>

              {result.clarifying_questions.length > 0 && (
                <div>
                  <p className="mb-1 text-xs font-medium uppercase tracking-wide text-muted-foreground">Clarifying questions</p>
                  <ul className="space-y-1">
                    {result.clarifying_questions.map((q, i) => (
                      <li key={i} className="flex items-start gap-2 text-sm text-muted-foreground">
                        <span className="mt-0.5 block h-1.5 w-1.5 shrink-0 rounded-full bg-muted-foreground/40" />
                        {q}
                      </li>
                    ))}
                  </ul>
                </div>
              )}

              <div className="text-[11px] text-muted-foreground">
                Mode: <code className="rounded bg-muted px-1 py-0.5 font-mono text-[11px]">{result.execution_mode}</code>
              </div>
            </div>
          )}

          {!result && !error && !normalizing && (
            <p className="text-sm text-muted-foreground">Type or paste free-text above and click Normalize to get a structured preview.</p>
          )}
        </div>
      </DialogContent>
    </Dialog>
  );
}

function FilterSelect({ label, value, onChange, options, testId }: { label: string; value: string; onChange: (v: string) => void; options: string[]; testId?: string }) {
  return (
    <Select value={value} onValueChange={onChange}>
      <SelectTrigger className="h-8 w-auto min-w-[150px] gap-1.5 text-xs" data-testid={testId}>
        <span className="text-muted-foreground">{label}:</span><SelectValue />
      </SelectTrigger>
      <SelectContent>
        <SelectItem value={ALL}>All</SelectItem>
        {options.map((o) => <SelectItem key={o} value={o}>{o.replace(/_/g, " ")}</SelectItem>)}
      </SelectContent>
    </Select>
  );
}

const RISK_STRIPE: Record<string, string> = {
  low: "border-l-emerald-500/60",
  medium: "border-l-amber-500/70",
  high: "border-l-orange-500/80",
  critical: "border-l-red-500",
};

const COLUMN_HINT: Record<string, string> = {
  backlog: "Nothing in backlog — add a task or run intake.",
  ready: "No tasks ready — promote from backlog when ready to start.",
  in_progress: "No work in progress.",
  in_review: "Nothing to review right now.",
  blocked: "No blocked tasks — nice.",
  done: "No completed tasks yet.",
};

function Board({ board, match, onOpen, canMutate, projectId }: { board: KanbanBoard; match: (t: TaskCard) => boolean; onOpen: (id: string) => void; canMutate: boolean; projectId?: string }) {
  const allTasks = useMemo(() => Object.values(board.tasks), [board]);
  const shown = useMemo(() => allTasks.filter(match), [allTasks, match]);
  const hidden = allTasks.length - shown.length;
  const [density, setDensity] = useState<"comfortable" | "compact">(() => {
    if (typeof window === "undefined") return "compact";
    return (window.localStorage.getItem("devboard.kanbanDensity") as any) || "compact";
  });
  const setDensityPersist = (d: "comfortable" | "compact") => {
    setDensity(d);
    try { window.localStorage.setItem("devboard.kanbanDensity", d); } catch {}
  };
  const compact = density === "compact";

  return (
    <>
      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className="text-xs text-muted-foreground" data-testid="kanban-count">
          {shown.length} task{shown.length === 1 ? "" : "s"} shown
          {hidden > 0 && <span className="ml-1 text-muted-foreground/70">· {hidden} hidden by filters</span>}
        </p>
        <div className="inline-flex items-center rounded-md border border-border bg-card/50 p-0.5 text-[11px]">
          <button type="button" onClick={() => setDensityPersist("comfortable")} className={`rounded px-2 py-0.5 font-mono uppercase tracking-wide ${!compact ? "bg-background text-foreground shadow-sm" : "text-muted-foreground hover:text-foreground"}`} data-testid="density-comfortable">Comfortable</button>
          <button type="button" onClick={() => setDensityPersist("compact")} className={`rounded px-2 py-0.5 font-mono uppercase tracking-wide ${compact ? "bg-background text-foreground shadow-sm" : "text-muted-foreground hover:text-foreground"}`} data-testid="density-compact">Compact</button>
        </div>
      </div>

      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-6">
        {board.columns.map((col) => {
          const cards = col.task_ids.map((id) => board.tasks[id]).filter(match);
          const emptyHint = COLUMN_HINT[col.id] || "No tasks in this column.";
          return (
            <div key={col.id} className="flex min-w-0 flex-col rounded-md border border-border bg-card/40" data-testid={`column-${col.id}`}>
              <div className="flex items-center justify-between border-b border-border px-3 py-2">
                <span className="text-xs font-semibold uppercase tracking-wide">{col.title}</span>
                <Pill tone="slate">{cards.length}</Pill>
              </div>
              <div className={`flex flex-col ${compact ? "gap-1.5 p-1.5" : "gap-2 p-2"}`}>
                {cards.length === 0 && (
                  <div className="rounded border border-dashed border-border/60 px-2 py-4 text-center text-[11px] leading-relaxed text-muted-foreground/80">
                    {emptyHint}
                  </div>
                )}
                {cards.map((t) => {
                  const stripe = RISK_STRIPE[t.risk] || "border-l-border";
                  return (
                    <div
                      key={t.id}
                      role="button"
                      onClick={() => onOpen(t.id)}
                      data-testid={`task-card-${t.id}`}
                      className={`cursor-pointer rounded-md border border-border border-l-2 ${stripe} bg-card ${compact ? "p-2" : "p-2.5"} text-left transition-colors hover:border-primary/50 hover:border-l-primary`}
                    >
                      <div className="flex items-start justify-between gap-2">
                        <span className="font-mono text-[10px] text-muted-foreground">{t.id}</span>
                        {t.blocked && <Pill tone="red" icon={Ban}>Blocked</Pill>}
                      </div>
                      <p className={`mt-1 font-medium leading-snug ${compact ? "text-[13px]" : "text-sm"}`}>{t.title}</p>
                      {!compact && (
                        <div className="mt-2 flex flex-wrap items-center gap-1">
                          <RiskBadge level={t.risk} />
                          <SourceStatusBadge status={t.source_status} />
                        </div>
                      )}
                      {!compact && t.repositories.length > 0 && (
                        <div className="mt-2 flex flex-wrap items-center gap-1">
                          {t.repositories.map((r) => <Pill key={r} tone="slate" icon={GitBranch}>{r}</Pill>)}
                        </div>
                      )}
                      <div className={`${compact ? "mt-1.5 pt-1.5" : "mt-2 pt-2"} flex items-center justify-between border-t border-border/60`}>
                        <span className="flex items-center gap-1.5 text-[11px] text-muted-foreground">
                          <span className="grid h-4 w-4 place-items-center rounded-full text-[8px] font-bold text-white" style={{ background: t.owner_color }}>{t.owner.split(" ").map((n) => n[0]).join("")}</span>
                          {relativeTime(t.updated_at)}
                        </span>
                        <div className="flex items-center gap-1.5" onClick={(e) => e.stopPropagation()}>
                          {t.attachment_count > 0 && <Pill tone="teal" icon={Paperclip}>{t.attachment_count}</Pill>}
                          {t.image_attachment_count > 0 && <Pill tone="slate" icon={ImageIcon}>{t.image_attachment_count}</Pill>}
                          {t.linked_run_id && <Link to={projectId ? `/projects/${encodeURIComponent(projectId)}/runs/${t.linked_run_id}` : `/runs/${t.linked_run_id}`} title="Linked run"><RunStatusBadge status={t.linked_run_status} /></Link>}
                          {t.wiki_page_id && <Link to={projectId ? `/projects/${encodeURIComponent(projectId)}/wiki/${t.wiki_page_id}` : `/wiki/${t.wiki_page_id}`} title="Affected wiki"><BookText className="h-3.5 w-3.5 text-muted-foreground hover:text-foreground" /></Link>}
                          <Link to={projectId ? `/projects/${encodeURIComponent(projectId)}/graph?run_id=${t.linked_run_id || ""}` : `/graph?run_id=${t.linked_run_id || ""}`} title="Graph view"><Network className="h-3.5 w-3.5 text-muted-foreground hover:text-foreground" /></Link>
                        </div>
                      </div>
                    </div>
                  );
                })}
              </div>
            </div>
          );
        })}
      </div>
    </>
  );
}

