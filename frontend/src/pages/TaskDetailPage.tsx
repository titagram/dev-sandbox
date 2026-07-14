import React, { useRef, useState } from "react";
import { useParams, Link } from "react-router-dom";
import { API_BASE_URL, api } from "@/api/devboardApi";
import { useApi } from "@/hooks/useApi";
import { useAuth } from "@/context/AuthContext";
import { PageHeader, Panel } from "@/components/devboard/Layout";
import { DataState } from "@/components/devboard/DataState";
import { RiskBadge, RunStatusBadge, SourceStatusBadge, SourceMetaInline, Pill } from "@/components/devboard/Badges";
import { Button } from "@/components/ui/button";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import {
  Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from "@/components/ui/select";
import { formatBytes, relativeTime, titleCase } from "@/lib/format";
import { AssistantSuggestion, ProjectDetail, TaskAttachment, TaskClarificationPayload, TaskColumn, TaskDetail } from "@/types/devboard";
import { TaskFormFields } from "./TaskFormFields";
import { canMutateTasksForProject, normalizeTaskRepositorySelection, TASK_COLUMNS, taskDetailToTaskForm, TaskFormState, taskFormToMutationPayload } from "./taskFormModel";
import { toast } from "sonner";
import {
  ChevronLeft, GitBranch, Network, BookText, PlayCircle, Ban, CheckCircle2,
  Boxes, Paperclip, Upload, Image as ImageIcon, FileText, Download, Loader2,
  Trash2, Bot, XCircle, Pencil,
} from "lucide-react";

export default function TaskDetailPage() {
  const { taskId } = useParams();
  const { user } = useAuth();
  const state = useApi(() => api.getTask(taskId!), [taskId]);
  const canUseAssistants = user?.role === "admin" || user?.role === "pm";
  const canEditTasks = user?.role === "admin" || user?.role === "pm" || user?.role === "developer";

  return (
    <div className="space-y-5" data-testid="task-detail-page">
      <DataState state={state}>
        {(t) => (
          <>
            <Link to={t.project_id ? `/projects/${t.project_id}/kanban` : "/kanban"} className="inline-flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground">
              <ChevronLeft className="h-3.5 w-3.5" /> Kanban
            </Link>

            <PageHeader
              title={<span className="flex items-center gap-2"><span className="font-mono text-sm text-muted-foreground">{t.id}</span>{t.title}</span>}
              meta={
                <div className="flex flex-wrap items-center gap-1.5">
                  <Pill tone="slate">{titleCase(t.column)}</Pill>
                  <RiskBadge level={t.risk} />
                  <SourceStatusBadge status={t.source_status} />
                  {t.blocked && <Pill tone="red" icon={Ban}>Blocked</Pill>}
                  <span className="text-xs text-muted-foreground">Updated {relativeTime(t.updated_at)}</span>
                </div>
              }
              actions={
                <>
                  {canEditTasks && <TaskStatusQuickChange task={t} onSaved={state.reload} />}
                  {canEditTasks && <TaskEditDialog task={t} onSaved={state.reload} />}
                  {t.linked_run_id && <Button size="sm" variant="outline" asChild><Link to={`/projects/${encodeURIComponent(t.project_id)}/runs/${t.linked_run_id}`} data-testid="task-open-run"><PlayCircle className="mr-1.5 h-3.5 w-3.5" />Run</Link></Button>}
                  {t.wiki_page_id && <Button size="sm" variant="outline" asChild><Link to={`/projects/${encodeURIComponent(t.project_id)}/wiki/${t.wiki_page_id}`} data-testid="task-open-wiki"><BookText className="mr-1.5 h-3.5 w-3.5" />Wiki</Link></Button>}
                  <Button size="sm" variant="outline" asChild><Link to={`/projects/${encodeURIComponent(t.project_id)}`} data-testid="task-open-project"><Boxes className="mr-1.5 h-3.5 w-3.5" />Project</Link></Button>
                  <Button size="sm" variant="outline" asChild><Link to={`/projects/${encodeURIComponent(t.project_id)}/graph?run_id=${t.linked_run_id || ""}`} data-testid="task-open-graph"><Network className="mr-1.5 h-3.5 w-3.5" />Graph</Link></Button>
                </>
              }
            />

            {t.blocked && t.blocked_reason && (
              <div className="flex items-center gap-2 rounded-md border border-red-500/30 bg-red-500/5 px-3 py-2.5 text-sm text-red-600 dark:text-red-400" data-testid="blocked-banner">
                <Ban className="h-4 w-4 shrink-0" /> {t.blocked_reason}
              </div>
            )}

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
              <div className="space-y-4 lg:col-span-2">
                <Panel title="Description"><p className="text-sm leading-relaxed text-muted-foreground">{t.description}</p></Panel>
                <Panel title="Acceptance criteria">
                  <ul className="space-y-2">
                    {t.acceptance_criteria.map((c, i) => (
                      <li key={i} className="flex items-start gap-2 text-sm"><CheckCircle2 className="mt-0.5 h-4 w-4 shrink-0 text-emerald-500" />{c}</li>
                    ))}
                  </ul>
                </Panel>
                <TaskAssistantPanel task={t} canUseAssistants={canUseAssistants} onTaskChanged={state.reload} />
                <TaskAttachmentsPanel task={t} onUploaded={state.reload} />
                <Panel title="Linked graph nodes" dense>
                  {t.graph_node_ids.length === 0 ? <p className="p-4 text-sm text-muted-foreground">None.</p> :
                    <div className="flex flex-wrap gap-1.5 p-4">{t.graph_node_ids.map((n) => <Pill key={n} tone="teal" icon={Network}>{n}</Pill>)}</div>}
                </Panel>
              </div>

              <div className="space-y-4">
                <Panel title="Source">
                  <SourceMetaInline source={t.source} />
                  <p className="mt-2 text-[11px] text-muted-foreground">Derived from a local plugin snapshot — verify against repository state before any code-write action.</p>
                </Panel>
                <Panel title="Repositories" dense>
                  <ul className="divide-y divide-border/60">
                    {t.repositories.map((r) => (
                      <li key={r} className="flex items-center gap-2 px-4 py-2.5 text-sm font-medium"><GitBranch className="h-3.5 w-3.5 text-muted-foreground" />{r}</li>
                    ))}
                  </ul>
                </Panel>
                <Panel title="Owner & run">
                  <div className="flex items-center justify-between text-sm"><span className="text-muted-foreground">Owner</span><span className="flex items-center gap-1.5 font-medium"><span className="grid h-5 w-5 place-items-center rounded-full text-[9px] font-bold text-white" style={{ background: t.owner_color }}>{t.owner.split(" ").map((n) => n[0]).join("")}</span>{t.owner}</span></div>
                  <div className="mt-2 flex items-center justify-between text-sm"><span className="text-muted-foreground">Linked run</span><RunStatusBadge status={t.linked_run_status} /></div>
                </Panel>
              </div>
            </div>
          </>
        )}
      </DataState>
    </div>
  );
}

function TaskStatusQuickChange({ task, onSaved }: { task: TaskDetail; onSaved: () => void }) {
  const [saving, setSaving] = useState(false);

  const changeStatus = async (value: string) => {
    const column = value as TaskColumn;
    if (column === task.column || saving) return;

    setSaving(true);
    try {
      await api.updateTask(task.id, { column });
      toast.success("Task status updated");
      onSaved();
    } catch (err: any) {
      toast.error(err?.message || "Task status update failed.");
    } finally {
      setSaving(false);
    }
  };

  return (
    <Select value={task.column} onValueChange={changeStatus} disabled={saving}>
      <SelectTrigger className="h-8 w-[150px] text-xs" data-testid="task-status-quick-select">
        {saving ? (
          <span className="inline-flex items-center gap-1.5">
            <Loader2 className="h-3.5 w-3.5 animate-spin" />
            Saving
          </span>
        ) : (
          <SelectValue />
        )}
      </SelectTrigger>
      <SelectContent>
        {TASK_COLUMNS.map((column) => (
          <SelectItem key={column} value={column}>{titleCase(column)}</SelectItem>
        ))}
      </SelectContent>
    </Select>
  );
}

function TaskEditDialog({ task, onSaved }: { task: TaskDetail; onSaved: () => void }) {
  const [open, setOpen] = useState(false);
  const [form, setForm] = useState<TaskFormState>(() => taskDetailToTaskForm(task));
  const [project, setProject] = useState<ProjectDetail | null>(null);
  const [projectError, setProjectError] = useState<string | null>(null);
  const [loadingProject, setLoadingProject] = useState(false);
  const [saving, setSaving] = useState(false);
  const projectAllowsEdit = canMutateTasksForProject(project, "developer");
  const unresolvedRepositoryIds = project ? normalizeTaskRepositorySelection(form.repositoryIds, project.repositories).unresolved : [];
  const canSubmit = Boolean(project && !loadingProject && !projectError && projectAllowsEdit && unresolvedRepositoryIds.length === 0 && !saving);

  React.useEffect(() => {
    if (!open) setForm(taskDetailToTaskForm(task, project?.repositories ?? []));
  }, [open, project?.repositories, task]);

  React.useEffect(() => {
    if (!open || !task.project_id) return;

    let active = true;
    setLoadingProject(true);
    setProjectError(null);
    api.getProject(task.project_id)
      .then((nextProject) => {
        if (!active) return;
        setProject(nextProject);
        setForm(taskDetailToTaskForm(task, nextProject.repositories));
      })
      .catch((err: any) => {
        if (!active) return;
        const message = err?.message || "Project repositories failed to load.";
        setProjectError(message);
        toast.error(message);
      })
      .finally(() => {
        if (active) setLoadingProject(false);
      });

    return () => { active = false; };
  }, [open, task]);

  const setDialogOpen = (nextOpen: boolean) => {
    setOpen(nextOpen);
    if (nextOpen) {
      setForm(taskDetailToTaskForm(task, project?.repositories ?? []));
      setProjectError(null);
    } else if (!saving) {
      setForm(taskDetailToTaskForm(task, project?.repositories ?? []));
    }
  };

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();

    if (!project) {
      toast.error("Project repositories must load before saving this task.");
      return;
    }

    if (projectError) {
      toast.error("Resolve the project repository load error before saving.");
      return;
    }

    if (loadingProject) {
      toast.error("Wait for project repositories to finish loading.");
      return;
    }

    if (project.status !== "active") {
      toast.error("Tasks can only be edited for active projects.");
      return;
    }

    const repositorySelection = normalizeTaskRepositorySelection(form.repositoryIds, project.repositories);
    if (repositorySelection.unresolved.length > 0) {
      toast.error("Refresh project repositories before saving this task.");
      return;
    }

    const payload = taskFormToMutationPayload({ ...form, repositoryIds: repositorySelection.repositoryIds });

    if (!payload.title?.trim()) {
      toast.error("Task title is required.");
      return;
    }

    setSaving(true);
    try {
      const updated = await api.updateTask(task.id, payload);
      toast.success("Task updated");
      setOpen(false);
      setForm(taskDetailToTaskForm(updated, project?.repositories ?? []));
      onSaved();
    } catch (err: any) {
      toast.error(err?.message || "Task update failed.");
    } finally {
      setSaving(false);
    }
  };

  return (
    <Dialog open={open} onOpenChange={setDialogOpen}>
      <Button
        size="sm"
        type="button"
        variant="outline"
        onClick={() => setDialogOpen(true)}
        disabled={Boolean(project && project.status !== "active")}
        title={project && project.status !== "active" ? "Task editing is unavailable for inactive projects" : "Edit task"}
        data-testid="task-edit-open"
      >
        <Pencil className="mr-1.5 h-3.5 w-3.5" />
        Edit
      </Button>
      <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle>Edit task</DialogTitle>
          <DialogDescription>Update the task fields used for planning and implementation.</DialogDescription>
        </DialogHeader>
        <form onSubmit={submit} className="space-y-4">
          <TaskFormFields
            fieldIdPrefix="edit-task"
            form={form}
            onChange={setForm}
            repositories={project?.repositories ?? []}
            repositoriesLoading={loadingProject}
            repositoriesError={projectError}
            disabled={saving || loadingProject || project?.status !== "active"}
          />
          {project && project.status !== "active" && (
            <p className="text-sm text-muted-foreground">Task editing is unavailable for {project.status} projects.</p>
          )}
          {unresolvedRepositoryIds.length > 0 && (
            <p className="text-sm text-muted-foreground">
              Resolve stale repositories before saving: {unresolvedRepositoryIds.join(", ")}
            </p>
          )}
          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => setDialogOpen(false)} disabled={saving}>Cancel</Button>
            <Button type="submit" disabled={!canSubmit} data-testid="task-edit-submit">
              {saving ? <Loader2 className="mr-1.5 h-3.5 w-3.5 animate-spin" /> : <CheckCircle2 className="mr-1.5 h-3.5 w-3.5" />}
              Save task
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}

function TaskAssistantPanel({
  task,
  canUseAssistants,
  onTaskChanged,
}: {
  task: TaskDetail;
  canUseAssistants: boolean;
  onTaskChanged: () => void;
}) {
  const latestSuggestion = task.assistant?.latest_suggestion ?? null;
  const [suggestion, setSuggestion] = useState<AssistantSuggestion<TaskClarificationPayload> | null>(latestSuggestion);
  const [running, setRunning] = useState(false);
  const [resolving, setResolving] = useState<"accepted" | "rejected" | "applied" | null>(null);
  const [questionAnswers, setQuestionAnswers] = useState<Record<number, "yes" | "no">>({});

  React.useEffect(() => {
    setSuggestion(latestSuggestion);
  }, [task.id, latestSuggestion]);

  React.useEffect(() => {
    setQuestionAnswers({});
  }, [suggestion?.id]);

  const runClarifier = async () => {
    if (!canUseAssistants || running) return;

    setRunning(true);
    try {
      const response = await api.clarifyTask(task.id);
      setSuggestion(response.suggestion);
      toast.success("Platon clarity check ready");
    } catch (err: any) {
      toast.error(err?.message || "Platon clarity check failed");
    } finally {
      setRunning(false);
    }
  };

  const resolveSuggestion = async (status: "accepted" | "rejected") => {
    if (!suggestion?.id || resolving) return;

    setResolving(status);
    try {
      const response = await api.resolveAssistantSuggestion(suggestion.id, status);
      setSuggestion(response.suggestion);
      toast.success(status === "accepted" ? "Platon suggestion accepted" : "Platon suggestion rejected");
    } catch (err: any) {
      toast.error(err?.message || "Suggestion update failed");
    } finally {
      setResolving(null);
    }
  };

  const applySuggestion = async () => {
    if (!suggestion?.id || resolving) return;

    setResolving("applied");
    try {
      const response = await api.applyAssistantSuggestion(suggestion.id);
      setSuggestion(response.suggestion);
      toast.success("Platon suggestion applied");
      onTaskChanged();
    } catch (err: any) {
      toast.error(err?.message || "Suggestion apply failed");
    } finally {
      setResolving(null);
    }
  };

  return (
    <Panel
      title={<span className="inline-flex items-center gap-1.5"><Bot className="h-4 w-4 text-muted-foreground" />Platon task clarifier</span>}
      action={canUseAssistants && (
        <Button size="sm" type="button" onClick={runClarifier} disabled={running} data-testid="task-assistant-clarify">
          {running ? <Loader2 className="mr-1.5 h-3.5 w-3.5 animate-spin" /> : <Bot className="mr-1.5 h-3.5 w-3.5" />}
          Ask Platon
        </Button>
      )}
    >
      {suggestion ? (
        <div className="space-y-4" data-testid="task-assistant-suggestion">
          <div className="flex flex-wrap items-center gap-2">
            <Pill tone={assistantStatusTone(suggestion.status)}>{titleCase(suggestion.status)}</Pill>
            <Pill tone="slate">Confidence {Math.round(Number(suggestion.confidence ?? 0) * 100)}%</Pill>
            {suggestion.approval_required && <Pill tone="amber">Approval required</Pill>}
          </div>

          <div>
            <h3 className="text-sm font-medium">{platonCopy(suggestion.title)}</h3>
            {suggestion.body_markdown && <p className="mt-1 whitespace-pre-wrap text-sm leading-relaxed text-muted-foreground">{platonCopy(suggestion.body_markdown)}</p>}
          </div>

          <div className="grid gap-3 md:grid-cols-2">
            <PlatonQuestionList
              questions={suggestion.structured_payload?.questions ?? []}
              answers={questionAnswers}
              onAnswer={(index, answer) => setQuestionAnswers((prev) => ({ ...prev, [index]: answer }))}
            />
            <AssistantList title="Acceptance criteria" items={suggestion.structured_payload?.acceptance_criteria ?? []} />
            <AssistantList title="Risks" items={suggestion.structured_payload?.risks ?? []} />
            <AssistantList title="Missing context" items={suggestion.structured_payload?.missing_context ?? []} />
          </div>

          {canUseAssistants && suggestion.status === "pending" && (
            <div className="flex flex-wrap items-center gap-2">
              <Button size="sm" type="button" variant="outline" onClick={() => resolveSuggestion("accepted")} disabled={Boolean(resolving)} data-testid="task-assistant-accept">
                {resolving === "accepted" ? <Loader2 className="mr-1.5 h-3.5 w-3.5 animate-spin" /> : <CheckCircle2 className="mr-1.5 h-3.5 w-3.5" />}
                Accept
              </Button>
              <Button size="sm" type="button" variant="outline" onClick={() => resolveSuggestion("rejected")} disabled={Boolean(resolving)} data-testid="task-assistant-reject">
                {resolving === "rejected" ? <Loader2 className="mr-1.5 h-3.5 w-3.5 animate-spin" /> : <XCircle className="mr-1.5 h-3.5 w-3.5" />}
                Reject
              </Button>
            </div>
          )}

          {canUseAssistants && suggestion.status === "accepted" && (
            <Button size="sm" type="button" onClick={applySuggestion} disabled={Boolean(resolving)} data-testid="task-assistant-apply">
              {resolving === "applied" ? <Loader2 className="mr-1.5 h-3.5 w-3.5 animate-spin" /> : <CheckCircle2 className="mr-1.5 h-3.5 w-3.5" />}
              Apply
            </Button>
          )}
        </div>
      ) : (
        <p className="text-sm text-muted-foreground">No Platon suggestion.</p>
      )}
    </Panel>
  );
}

function PlatonQuestionList({
  questions,
  answers,
  onAnswer,
}: {
  questions: string[];
  answers: Record<number, "yes" | "no">;
  onAnswer: (index: number, answer: "yes" | "no") => void;
}) {
  return (
    <div className="min-w-0">
      <div className="flex flex-wrap items-center gap-2">
        <span className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Platon PM questions</span>
        <span className="rounded-sm bg-muted px-1.5 py-0.5 text-[11px] font-medium text-muted-foreground">Local scratch only</span>
      </div>
      {questions.length ? (
        <ul className="mt-2 divide-y divide-border/60">
          {questions.map((question, index) => (
            <li key={`platon-question-${index}`} className="grid gap-2 py-2 first:pt-0 last:pb-0">
              <p className="text-sm leading-relaxed text-muted-foreground">{question}</p>
              <div className="flex flex-wrap items-center gap-2">
                <span className="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">Scratch decision</span>
                <div className="inline-flex overflow-hidden rounded-md border border-input" role="group" aria-label={`Local scratch decision for question ${index + 1}`}>
                  <Button
                    type="button"
                    size="sm"
                    variant={answers[index] === "yes" ? "default" : "ghost"}
                    className="h-7 min-w-14 rounded-none border-0 px-2 text-xs"
                    aria-pressed={answers[index] === "yes"}
                    onClick={() => onAnswer(index, "yes")}
                  >
                    Yes
                  </Button>
                  <Button
                    type="button"
                    size="sm"
                    variant={answers[index] === "no" ? "default" : "ghost"}
                    className="h-7 min-w-14 rounded-none border-0 border-l border-input px-2 text-xs"
                    aria-pressed={answers[index] === "no"}
                    onClick={() => onAnswer(index, "no")}
                  >
                    No
                  </Button>
                </div>
              </div>
            </li>
          ))}
        </ul>
      ) : (
        <p className="mt-2 text-sm text-muted-foreground">None.</p>
      )}
    </div>
  );
}

function AssistantList({ title, items }: { title: string; items: string[] }) {
  return (
    <div className="min-w-0">
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

function platonCopy(value: string): string {
  return value.replace(/\bTask Clarifier\b/g, "Platon");
}

function assistantStatusTone(status: string): "green" | "amber" | "red" | "blue" | "slate" | "teal" {
  if (status === "accepted" || status === "applied") return "green";
  if (status === "rejected") return "red";
  if (status === "superseded") return "slate";
  if (status === "pending") return "amber";
  return "blue";
}

function TaskAttachmentsPanel({ task, onUploaded }: { task: TaskDetail; onUploaded: () => void }) {
  const { user } = useAuth();
  const inputRef = useRef<HTMLInputElement | null>(null);
  const [uploading, setUploading] = useState(false);
  const canUpload = user?.role === "admin" || user?.role === "pm" || user?.role === "developer";
  const attachments = (task.attachments || []).filter((attachment) => attachment.status !== "deleted");

  const chooseFile = () => inputRef.current?.click();
  const upload = async (event: React.ChangeEvent<HTMLInputElement>) => {
    const file = event.target.files?.[0];
    if (!file) return;

    setUploading(true);
    try {
      await api.uploadTaskAttachment(task.id, file);
      toast.success("Attachment uploaded");
      onUploaded();
    } catch (err: any) {
      toast.error(err?.message || "Attachment upload failed");
    } finally {
      setUploading(false);
      if (inputRef.current) inputRef.current.value = "";
    }
  };

  return (
    <Panel
      title={<span className="inline-flex items-center gap-1.5"><Paperclip className="h-4 w-4 text-muted-foreground" />Attachments</span>}
      action={canUpload && (
        <>
          <input
            ref={inputRef}
            type="file"
            className="hidden"
            accept="image/png,image/jpeg,image/gif,image/webp,application/pdf,text/plain,text/markdown,text/csv,application/json,.txt,.md,.markdown,.csv,.json,.pdf,.png,.jpg,.jpeg,.gif,.webp"
            onChange={upload}
            data-testid="task-attachment-file"
          />
          <button
            type="button"
            onClick={chooseFile}
            disabled={uploading}
            className="inline-flex h-8 items-center justify-center gap-1.5 rounded-md border border-input bg-background px-3 text-xs font-medium transition-colors hover:bg-accent hover:text-accent-foreground disabled:pointer-events-none disabled:opacity-50"
            data-testid="task-attachment-upload"
          >
            {uploading ? <Loader2 className="mr-1.5 h-3.5 w-3.5 animate-spin" /> : <Upload className="mr-1.5 h-3.5 w-3.5" />}
            Upload
          </button>
        </>
      )}
    >
      {attachments.length === 0 ? (
        <p className="text-sm text-muted-foreground">No attachments.</p>
      ) : (
        <div className="grid gap-2">
          {attachments.map((attachment) => (
            <AttachmentRow
              key={attachment.id}
              taskId={task.id}
              attachment={attachment}
              canDelete={canUpload}
              onDeleted={onUploaded}
            />
          ))}
        </div>
      )}
    </Panel>
  );
}

function AttachmentRow({
  taskId,
  attachment,
  canDelete,
  onDeleted,
}: {
  taskId: string;
  attachment: TaskAttachment;
  canDelete: boolean;
  onDeleted: () => void;
}) {
  const href = attachmentHref(attachment.download_url);
  const preview = attachment.preview_url ? attachmentHref(attachment.preview_url) : null;
  const [deleting, setDeleting] = useState(false);

  const deleteAttachment = async () => {
    if (deleting) return;

    setDeleting(true);
    try {
      await api.deleteTaskAttachment(taskId, attachment.id);
      toast.success("Attachment removed");
      onDeleted();
    } catch (err: any) {
      toast.error(err?.message || "Attachment delete failed");
    } finally {
      setDeleting(false);
    }
  };

  return (
    <div className="flex items-center gap-3 rounded-md border border-border/70 bg-background/50 p-2.5" data-testid={`task-attachment-${attachment.id}`}>
      {attachment.kind === "image" && preview ? (
        <a href={preview} target="_blank" rel="noreferrer" className="h-14 w-14 overflow-hidden rounded border border-border bg-muted" title={attachment.name}>
          <img src={preview} alt="" className="h-full w-full object-cover" />
        </a>
      ) : (
        <div className="grid h-14 w-14 place-items-center rounded border border-border bg-muted">
          {attachment.kind === "image" ? <ImageIcon className="h-5 w-5 text-muted-foreground" /> : <FileText className="h-5 w-5 text-muted-foreground" />}
        </div>
      )}

      <div className="min-w-0 flex-1">
        <div className="flex min-w-0 flex-wrap items-center gap-1.5">
          <span className="truncate text-sm font-medium">{attachment.name}</span>
          <Pill tone={attachment.kind === "image" ? "teal" : "slate"}>{attachment.kind}</Pill>
          <Pill tone="slate">{attachment.scan_status.replace(/_/g, " ")}</Pill>
        </div>
        <p className="mt-1 text-xs text-muted-foreground">
          {formatBytes(attachment.size_bytes)} · {attachment.mime_type} · {attachment.uploaded_by} · {relativeTime(attachment.uploaded_at)}
        </p>
      </div>

      <div className="flex shrink-0 items-center gap-1.5">
        <a
          href={href}
          download={attachment.name}
          className="inline-flex h-9 w-9 items-center justify-center rounded-md border border-input bg-background text-sm transition-colors hover:bg-accent hover:text-accent-foreground"
          title="Download"
          data-testid={`task-attachment-download-${attachment.id}`}
        >
          <Download className="h-4 w-4" />
        </a>
        {canDelete && (
          <button
            type="button"
            onClick={deleteAttachment}
            disabled={deleting}
            className="inline-flex h-9 w-9 items-center justify-center rounded-md border border-input bg-background text-sm text-muted-foreground transition-colors hover:bg-destructive/10 hover:text-destructive disabled:pointer-events-none disabled:opacity-50"
            title="Delete attachment"
            data-testid={`task-attachment-delete-${attachment.id}`}
          >
            {deleting ? <Loader2 className="h-4 w-4 animate-spin" /> : <Trash2 className="h-4 w-4" />}
          </button>
        )}
      </div>
    </div>
  );
}

function attachmentHref(url: string): string {
  return url.startsWith("/") ? `${API_BASE_URL}${url}` : url;
}
