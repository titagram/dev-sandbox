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
  AgentKey, AgentWorkCreateInput, AgentWorkDetailItem, AgentWorkItem, AgentWorkPriority,
  AgentWorkStatus, KanbanBoard, ProjectDetail, Role,
} from "@/types/devboard";
import {
  Archive, Ban, Boxes, Brain, Clock3, KanbanSquare, Loader2, MessageSquare, Plus, Workflow,
} from "lucide-react";

const NONE = "__none__";

type QueueAgentKey = "socrates" | "platon" | "aristoteles" | "local_agent";

const STATUS_TONE: Record<AgentWorkStatus, any> = {
  draft: "slate",
  queued: "amber",
  claimed: "blue",
  running: "blue",
  completed: "green",
  completed_with_incomplete_memory: "amber",
  failed: "red",
  canceled: "slate",
};

const PRIORITY_TONE: Record<AgentWorkPriority, any> = {
  low: "slate",
  normal: "blue",
  high: "amber",
  urgent: "red",
};

const AGENT_OPTIONS: { value: QueueAgentKey; label: string }[] = [
  { value: "socrates", label: "Socrates" },
  { value: "platon", label: "Platon" },
  { value: "aristoteles", label: "Aristoteles" },
  { value: "local_agent", label: "Local Agent" },
];

const AGENT_LABELS: Partial<Record<AgentKey, string>> = {
  socrates: "Socrates",
  platon: "Platon",
  aristoteles: "Aristoteles",
  local_agent: "Local Agent",
};

const PRIORITY_OPTIONS: AgentWorkPriority[] = ["low", "normal", "high", "urgent"];

type WorkFormState = {
  assignedAgentKey: QueueAgentKey;
  priority: AgentWorkPriority;
  title: string;
  prompt: string;
  repositoryId: string;
  taskId: string;
  requiresMemoryEntry: boolean;
};

const emptyWorkForm: WorkFormState = {
  assignedAgentKey: "socrates",
  priority: "normal",
  title: "",
  prompt: "",
  repositoryId: NONE,
  taskId: NONE,
  requiresMemoryEntry: true,
};

function canMutateRole(role: Role | null | undefined): boolean {
  return role === "admin" || role === "pm" || role === "developer";
}

function canQueueWork(project: ProjectDetail | null | undefined, role: Role | null | undefined): boolean {
  return project?.status === "active" && canMutateRole(role);
}

function canCancelWorkItem(item: AgentWorkItem): boolean {
  return item.status === "queued" && !item.claimed_by_device_id && !item.claimed_at && !item.heartbeat_at;
}

function canArchiveWorkItem(item: AgentWorkItem): boolean {
  return !item.archived_at && item.status !== "claimed" && item.status !== "running" && !item.claimed_by_device_id && !item.claimed_at && !item.heartbeat_at;
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

function QueueWorkDialog({
  projectId,
  project,
  board,
  onCreated,
}: {
  projectId: string;
  project: ProjectDetail;
  board: KanbanBoard | null;
  onCreated: (item: AgentWorkItem) => void;
}) {
  const [open, setOpen] = useState(false);
  const [form, setForm] = useState<WorkFormState>(emptyWorkForm);
  const [saving, setSaving] = useState(false);
  const taskOptions = useMemo(() => taskOptionsFromBoard(board, projectId), [board, projectId]);

  const setDialogOpen = (nextOpen: boolean) => {
    if (saving) return;
    setOpen(nextOpen);
    if (!nextOpen) setForm(emptyWorkForm);
  };

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    const title = form.title.trim();
    const prompt = form.prompt.trim();

    if (project.id !== projectId || project.status !== "active") {
      toast.error("Agent work can only be queued for active projects.");
      return;
    }

    if (title.length < 4 || title.length > 180) {
      toast.error("Title must be 4 to 180 characters.");
      return;
    }

    if (prompt.length < 8 || prompt.length > 8000) {
      toast.error("Prompt must be 8 to 8000 characters.");
      return;
    }

    if (form.repositoryId !== NONE && !project.repositories.some((repository) => repository.id === form.repositoryId)) {
      toast.error("Refresh project repositories before queuing this work.");
      return;
    }

    if (form.taskId !== NONE && !taskOptions.some((task) => task.id === form.taskId)) {
      toast.error("Refresh project tasks before queuing this work.");
      return;
    }

    const input: AgentWorkCreateInput = {
      assigned_agent_key: form.assignedAgentKey,
      priority: form.priority,
      title,
      prompt,
      payload: { source: "agent_work_page" },
      requires_memory_entry: form.requiresMemoryEntry,
    };

    if (form.repositoryId !== NONE) input.repository_id = form.repositoryId;
    if (form.taskId !== NONE) input.task_id = form.taskId;

    setSaving(true);
    try {
      const created = await api.createAgentWork(projectId, input);
      toast.success(`Work queued: ${created.id}`);
      setOpen(false);
      setForm(emptyWorkForm);
      onCreated(created);
    } catch (err: any) {
      toast.error(err?.message || "Agent work create failed.");
    } finally {
      setSaving(false);
    }
  };

  return (
    <Dialog open={open} onOpenChange={setDialogOpen}>
      <Button size="sm" type="button" onClick={() => setDialogOpen(true)} data-testid="queue-work-btn">
        <Plus className="mr-1.5 h-3.5 w-3.5" />
        Queue work
      </Button>
      <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl" data-testid="queue-work-dialog">
        <form onSubmit={submit} className="space-y-4">
          <DialogHeader>
            <DialogTitle>Queue work</DialogTitle>
            <DialogDescription>Create a project-scoped work item for an agent.</DialogDescription>
          </DialogHeader>

          <div className="space-y-3">
            <div className="grid gap-3 md:grid-cols-2">
              <div className="space-y-1.5">
                <Label className="text-xs">Agent</Label>
                <Select
                  value={form.assignedAgentKey}
                  onValueChange={(value) => setForm((prev) => ({ ...prev, assignedAgentKey: value as QueueAgentKey }))}
                  disabled={saving}
                >
                  <SelectTrigger data-testid="work-agent-select"><SelectValue /></SelectTrigger>
                  <SelectContent>
                    {AGENT_OPTIONS.map((agent) => (
                      <SelectItem key={agent.value} value={agent.value}>{agent.label}</SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
              <div className="space-y-1.5">
                <Label className="text-xs">Priority</Label>
                <Select
                  value={form.priority}
                  onValueChange={(value) => setForm((prev) => ({ ...prev, priority: value as AgentWorkPriority }))}
                  disabled={saving}
                >
                  <SelectTrigger data-testid="work-priority-select"><SelectValue /></SelectTrigger>
                  <SelectContent>
                    {PRIORITY_OPTIONS.map((priority) => (
                      <SelectItem key={priority} value={priority}>{titleCase(priority)}</SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
            </div>

            <div className="space-y-1.5">
              <Label htmlFor="work-title" className="text-xs">Title</Label>
              <Input
                id="work-title"
                value={form.title}
                onChange={(event) => setForm((prev) => ({ ...prev, title: event.target.value }))}
                maxLength={180}
                disabled={saving}
                data-testid="work-title-input"
                autoFocus
              />
            </div>

            <div className="space-y-1.5">
              <Label htmlFor="work-prompt" className="text-xs">Prompt</Label>
              <Textarea
                id="work-prompt"
                value={form.prompt}
                onChange={(event) => setForm((prev) => ({ ...prev, prompt: event.target.value }))}
                className="min-h-36"
                maxLength={8000}
                disabled={saving}
                data-testid="work-prompt-input"
              />
            </div>

            <div className="grid gap-3 md:grid-cols-2">
              <div className="space-y-1.5">
                <Label className="text-xs">Repository</Label>
                <Select
                  value={form.repositoryId}
                  onValueChange={(value) => setForm((prev) => ({ ...prev, repositoryId: value }))}
                  disabled={saving}
                >
                  <SelectTrigger data-testid="work-repository-select"><SelectValue /></SelectTrigger>
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
                  <SelectTrigger data-testid="work-task-select"><SelectValue /></SelectTrigger>
                  <SelectContent>
                    <SelectItem value={NONE}>None</SelectItem>
                    {taskOptions.map((task) => (
                      <SelectItem key={task.id} value={task.id}>{task.id} · {task.title}</SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
            </div>

            <label className="flex items-start gap-2 rounded-md border border-border bg-muted/20 p-3 text-xs">
              <input
                type="checkbox"
                checked={form.requiresMemoryEntry}
                onChange={(event) => setForm((prev) => ({ ...prev, requiresMemoryEntry: event.target.checked }))}
                className="mt-0.5"
                disabled={saving}
                data-testid="work-requires-memory-checkbox"
              />
              <span>
                <span className="block font-medium text-foreground">Requires memory entry</span>
                <span className="text-muted-foreground">Completion should produce or link a memory entry.</span>
              </span>
            </label>
          </div>

          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => setDialogOpen(false)} disabled={saving}>Cancel</Button>
            <Button type="submit" disabled={saving || project.status !== "active"} data-testid="work-create-submit">
              {saving ? <Loader2 className="mr-1.5 h-3.5 w-3.5 animate-spin" /> : <Plus className="mr-1.5 h-3.5 w-3.5" />}
              Queue work
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}

function CancelWorkDialog({
  item,
  onOpenChange,
  onCanceled,
}: {
  item: AgentWorkItem | null;
  onOpenChange: (open: boolean) => void;
  onCanceled: () => void;
}) {
  const [message, setMessage] = useState("");
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    setMessage("");
  }, [item?.id]);

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    if (!item) return;

    setSaving(true);
    try {
      await api.cancelAgentWork(item.id, message.trim() || undefined);
      toast.success("Work canceled");
      onCanceled();
      onOpenChange(false);
    } catch (err: any) {
      toast.error(err?.message || "Cancel failed.");
    } finally {
      setSaving(false);
    }
  };

  return (
    <Dialog open={!!item} onOpenChange={(open) => { if (!saving) onOpenChange(open); }}>
      <DialogContent data-testid="cancel-work-dialog">
        <form onSubmit={submit} className="space-y-4">
          <DialogHeader>
            <DialogTitle>Cancel queued work</DialogTitle>
            <DialogDescription>{item?.title || "Queued work item"}</DialogDescription>
          </DialogHeader>

          <div className="space-y-1.5">
            <Label htmlFor="cancel-work-message" className="text-xs">Message</Label>
            <Textarea
              id="cancel-work-message"
              value={message}
              onChange={(event) => setMessage(event.target.value)}
              className="min-h-20"
              disabled={saving}
              placeholder="Optional reason"
              data-testid="cancel-work-message"
            />
          </div>

          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={saving}>Keep queued</Button>
            <Button type="submit" variant="destructive" disabled={saving || !item} data-testid="confirm-cancel-work">
              {saving ? <Loader2 className="mr-1.5 h-3.5 w-3.5 animate-spin" /> : <Ban className="mr-1.5 h-3.5 w-3.5" />}
              Cancel work
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}

function ArchiveWorkDialog({
  item,
  onOpenChange,
  onArchived,
}: {
  item: AgentWorkItem | null;
  onOpenChange: (open: boolean) => void;
  onArchived: (item: AgentWorkItem) => void;
}) {
  const [message, setMessage] = useState("");
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    setMessage("");
  }, [item?.id]);

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    if (!item) return;

    setSaving(true);
    try {
      const archived = await api.archiveAgentWork(item.id, message.trim() || undefined);
      toast.success(item.status === "queued" ? "Work canceled and removed" : "Work removed");
      onArchived(archived);
      onOpenChange(false);
    } catch (err: any) {
      toast.error(err?.message || "Remove failed.");
    } finally {
      setSaving(false);
    }
  };

  return (
    <Dialog open={!!item} onOpenChange={(open) => { if (!saving) onOpenChange(open); }}>
      <DialogContent data-testid="archive-work-dialog">
        <form onSubmit={submit} className="space-y-4">
          <DialogHeader>
            <DialogTitle>Remove work item</DialogTitle>
            <DialogDescription>{item?.title || "Agent work item"}</DialogDescription>
          </DialogHeader>

          <div className="space-y-1.5">
            <Label htmlFor="archive-work-message" className="text-xs">Message</Label>
            <Textarea
              id="archive-work-message"
              value={message}
              onChange={(event) => setMessage(event.target.value)}
              className="min-h-20"
              disabled={saving}
              placeholder="Optional reason"
              data-testid="archive-work-message"
            />
          </div>

          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={saving}>Keep item</Button>
            <Button type="submit" variant="destructive" disabled={saving || !item} data-testid="confirm-archive-work">
              {saving ? <Loader2 className="mr-1.5 h-3.5 w-3.5 animate-spin" /> : <Archive className="mr-1.5 h-3.5 w-3.5" />}
              Remove
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}

function AgentWorkDetailDialog({
  projectId,
  item,
  onOpenChange,
}: {
  projectId: string;
  item: AgentWorkItem | null;
  onOpenChange: (open: boolean) => void;
}) {
  const detailState = useApi<AgentWorkDetailItem | null>(
    () => item ? api.getAgentWorkDetail(projectId, item.id).then((response) => response.item) : Promise.resolve(null),
    [projectId, item?.id],
  );
  const detail = detailState.data;

  return (
    <Dialog open={!!item} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-4xl" data-testid="agent-work-detail-dialog">
        <DialogHeader>
          <DialogTitle>{item?.title || "Agent work detail"}</DialogTitle>
          <DialogDescription>{item ? `${AGENT_LABELS[item.assigned_agent_key] || titleCase(item.assigned_agent_key)} · ${titleCase(item.status)}` : ""}</DialogDescription>
        </DialogHeader>

        {detailState.loading && (
          <div className="flex items-center gap-2 rounded-md border border-border bg-muted/20 px-3 py-6 text-sm text-muted-foreground">
            <Loader2 className="h-4 w-4 animate-spin" />
            Loading detail
          </div>
        )}
        {detailState.error && <div className="rounded-md border border-red-500/30 bg-red-500/5 px-3 py-2 text-sm text-red-600 dark:text-red-400">{detailState.error}</div>}
        {detail && (
          <div className="grid gap-4 lg:grid-cols-[minmax(0,1.25fr)_minmax(280px,0.75fr)]">
            <div className="space-y-4">
              <Panel title="Prompt">
                <p className="whitespace-pre-wrap text-sm text-muted-foreground">{detail.prompt}</p>
              </Panel>

              <Panel title="Persisted chat" dense>
                {detail.chat.messages.length === 0 ? (
                  <div className="p-4 text-sm text-muted-foreground">No persisted messages for this work item.</div>
                ) : (
                  <div className="divide-y divide-border/60">
                    {detail.chat.messages.map((message) => (
                      <div key={message.id} className="px-4 py-3">
                        <div className="mb-1 flex flex-wrap items-center gap-2">
                          <Pill tone={message.role === "assistant" ? "blue" : "slate"}>{message.role}</Pill>
                          <span className="text-[11px] text-muted-foreground">{relativeTime(message.created_at)}</span>
                        </div>
                        <p className="whitespace-pre-wrap text-sm text-muted-foreground">{message.content}</p>
                      </div>
                    ))}
                  </div>
                )}
              </Panel>

              <Panel title="Events" dense>
                {detail.events.length === 0 ? (
                  <div className="p-4 text-sm text-muted-foreground">No events recorded.</div>
                ) : (
                  <ol className="divide-y divide-border/60">
                    {detail.events.map((event) => (
                      <li key={event.id} className="px-4 py-3">
                        <div className="flex flex-wrap items-center gap-2">
                          <Clock3 className="h-3.5 w-3.5 text-muted-foreground" />
                          <span className="text-sm font-medium">{titleCase(event.event_type)}</span>
                          <span className="text-[11px] text-muted-foreground">{relativeTime(event.created_at)}</span>
                        </div>
                        {event.message && <p className="mt-1 text-xs text-muted-foreground">{event.message}</p>}
                      </li>
                    ))}
                  </ol>
                )}
              </Panel>
            </div>

            <div className="space-y-4">
              <Panel title="Status" dense>
                <dl className="divide-y divide-border/60 text-sm">
                  {[
                    ["Agent", AGENT_LABELS[detail.assigned_agent_key] || titleCase(detail.assigned_agent_key)],
                    ["Priority", titleCase(detail.priority)],
                    ["Status", titleCase(detail.status)],
                    ["Created", relativeTime(detail.created_at)],
                    ["Updated", relativeTime(detail.updated_at)],
                  ].map(([label, value]) => (
                    <div key={label} className="flex items-center justify-between gap-3 px-4 py-2.5">
                      <dt className="text-muted-foreground">{label}</dt>
                      <dd className="text-right font-medium">{value}</dd>
                    </div>
                  ))}
                </dl>
              </Panel>

              <Panel title="Result memory" dense>
                {detail.result_memory_entry ? (
                  <div className="space-y-2 p-4">
                    <Pill tone="green">{titleCase(detail.result_memory_entry.kind)}</Pill>
                    <p className="text-sm font-medium">{detail.result_memory_entry.summary}</p>
                    <p className="font-mono text-[11px] text-muted-foreground">{detail.result_memory_entry.id}</p>
                  </div>
                ) : (
                  <div className="p-4 text-sm text-muted-foreground">No result memory linked yet.</div>
                )}
              </Panel>

              <Button size="sm" variant="outline" asChild className="w-full">
                <Link to={`/projects/${projectId}/agent-chat?agent=${encodeURIComponent(detail.assigned_agent_key)}&q=${encodeURIComponent(detail.prompt)}`}>
                  <MessageSquare className="mr-1.5 h-3.5 w-3.5" /> Continue in Chat
                </Link>
              </Button>
            </div>
          </div>
        )}
      </DialogContent>
    </Dialog>
  );
}

export default function AgentWorkPage() {
  const { user } = useAuth();
  const { projectId } = useParams();
  const [cancelItem, setCancelItem] = useState<AgentWorkItem | null>(null);
  const [archiveItem, setArchiveItem] = useState<AgentWorkItem | null>(null);
  const [detailItem, setDetailItem] = useState<AgentWorkItem | null>(null);
  const [recentQueuedItem, setRecentQueuedItem] = useState<AgentWorkItem | null>(null);
  const state = useApi(
    () => projectId ? api.getAgentWork(projectId) : Promise.resolve({ items: [] }),
    [projectId],
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
  const canMutateQueue = canQueueWork(matchedProject, user?.role);

  useEffect(() => {
    setCancelItem(null);
    setArchiveItem(null);
    setDetailItem(null);
    setRecentQueuedItem(null);
  }, [projectId]);

  if (!projectId) {
    return (
      <div className="space-y-5" data-testid="agent-work-page">
        <PageHeader
          title="Agent Work"
          subtitle="Agent work queues are managed inside a project workspace."
          actions={<Button size="sm" asChild><Link to="/projects"><Boxes className="mr-1.5 h-3.5 w-3.5" /> Projects</Link></Button>}
        />
        <Panel title="Project scope required">
          <p className="text-sm text-muted-foreground">Select a project to review queued, claimed, running, and completed agent work.</p>
        </Panel>
      </div>
    );
  }

  return (
    <div className="space-y-5" data-testid="agent-work-page">
      <PageHeader
        title="Agent Work"
        subtitle="Project agent work queue and memory handoff status."
        meta={<Link to={`/projects/${projectId}`} className="inline-flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground"><Boxes className="h-3.5 w-3.5" />Project {projectId}</Link>}
        actions={
          <>
            <ProjectDetailStatus loading={projectState.loading && !matchedProject} error={projectState.error} stale={projectStale} />
            {canMutateQueue && matchedProject && projectId && (
              <QueueWorkDialog
                projectId={projectId}
                project={matchedProject}
                board={boardState.loading ? null : boardState.data}
                onCreated={(item) => {
                  setRecentQueuedItem(item);
                  state.reload();
                }}
              />
            )}
            {matchedProject && matchedProject.status !== "active" && <Pill tone="slate">Inactive project</Pill>}
            <Button size="sm" variant="outline" asChild><Link to={`/projects/${projectId}/kanban`}><KanbanSquare className="mr-1.5 h-3.5 w-3.5" /> Kanban</Link></Button>
            <Button size="sm" variant="outline" asChild><Link to={`/projects/${projectId}/memory`}><Brain className="mr-1.5 h-3.5 w-3.5" /> Memory</Link></Button>
            <Button size="sm" variant="outline" asChild><Link to={`/projects/${projectId}/agent-chat`}><MessageSquare className="mr-1.5 h-3.5 w-3.5" /> Chat</Link></Button>
          </>
        }
      />

      <DataState state={state}>
        {(data) => {
          const items = data.items;
          const queued = items.filter((item) => item.status === "queued").length;
          const active = items.filter((item) => item.status === "claimed" || item.status === "running").length;
          const blocked = items.filter((item) => item.status === "failed" || item.status === "completed_with_incomplete_memory").length;

          return (
            <>
              <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <MetricCard label="Work items" value={items.length} />
                <MetricCard label="Queued" value={queued} tone={queued ? "warn" : "neutral"} />
                <MetricCard label="Active" value={active} tone={active ? "good" : "neutral"} />
                <MetricCard label="Needs review" value={blocked} tone={blocked ? "bad" : "good"} />
              </div>

              {recentQueuedItem && (
                <div className="flex flex-wrap items-center gap-3 rounded-md border border-border bg-muted/20 px-3 py-2.5" data-testid="recent-agent-work">
                  <Pill tone="green">Queued</Pill>
                  <span className="min-w-0 flex-1 truncate text-sm">{recentQueuedItem.title}</span>
                  <span className="font-mono text-xs text-muted-foreground">{recentQueuedItem.id}</span>
                </div>
              )}

              <Panel title={<span className="inline-flex items-center gap-1.5"><Workflow className="h-4 w-4 text-muted-foreground" />Agent queue</span>} dense>
                <div className="overflow-x-auto">
                  <table className="w-full min-w-[980px] text-sm">
                    <thead>
                      <tr className="border-b border-border text-left text-[11px] uppercase tracking-wide text-muted-foreground">
                        <th className="px-4 py-2 font-medium">Work item</th>
                        <th className="px-3 py-2 font-medium">Agent</th>
                        <th className="px-3 py-2 font-medium">Priority</th>
                        <th className="px-3 py-2 font-medium">Status</th>
                        <th className="px-3 py-2 font-medium">Memory</th>
                        <th className="px-3 py-2 font-medium">Updated</th>
                        <th className="px-4 py-2 text-right font-medium">Actions</th>
                      </tr>
                    </thead>
                    <tbody>
                      {items.length === 0 && (
                        <tr>
                          <td colSpan={7} className="px-4 py-10 text-center text-sm text-muted-foreground">No agent work queued.</td>
                        </tr>
                      )}
                      {items.map((item) => {
                        const cancelable = canMutateQueue && canCancelWorkItem(item);
                        const archivable = canMutateQueue && canArchiveWorkItem(item);
                        return (
                          <tr
                            key={item.id}
                            className="cursor-pointer border-b border-border/60 transition-colors last:border-0 hover:bg-accent/30"
                            onClick={() => setDetailItem(item)}
                            data-testid={`agent-work-row-${item.id}`}
                          >
                            <td className="px-4 py-2.5">
                              <p className="font-medium">{item.title}</p>
                              <p className="mt-0.5 line-clamp-1 text-xs text-muted-foreground">{item.prompt}</p>
                            </td>
                            <td className="px-3 py-2.5 font-mono text-xs">{AGENT_LABELS[item.assigned_agent_key] || titleCase(item.assigned_agent_key)}</td>
                            <td className="px-3 py-2.5"><Pill tone={PRIORITY_TONE[item.priority]}>{titleCase(item.priority)}</Pill></td>
                            <td className="px-3 py-2.5"><Pill tone={STATUS_TONE[item.status]}>{titleCase(item.status)}</Pill></td>
                            <td className="px-3 py-2.5">
                              {item.result_memory_entry_id
                                ? <Pill tone="green">{item.result_memory_entry_id}</Pill>
                                : item.requires_memory_entry ? <Pill tone="amber">Required</Pill> : <Pill tone="slate">Optional</Pill>}
                            </td>
                            <td className="px-3 py-2.5 text-xs text-muted-foreground">{relativeTime(item.updated_at)}</td>
                            <td className="px-4 py-2.5 text-right">
                              {cancelable || archivable ? (
                                <div className="flex justify-end gap-1.5">
                                  {cancelable && (
                                    <Button size="sm" variant="outline" type="button" onClick={(event) => { event.stopPropagation(); setCancelItem(item); }} data-testid={`cancel-work-${item.id}`}>
                                      <Ban className="mr-1.5 h-3.5 w-3.5" />
                                      Cancel
                                    </Button>
                                  )}
                                  {archivable && (
                                    <Button size="sm" variant="outline" type="button" onClick={(event) => { event.stopPropagation(); setArchiveItem(item); }} data-testid={`archive-work-${item.id}`}>
                                      <Archive className="mr-1.5 h-3.5 w-3.5" />
                                      Remove
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
            </>
          );
        }}
      </DataState>

      <CancelWorkDialog
        item={cancelItem}
        onOpenChange={(open) => { if (!open) setCancelItem(null); }}
        onCanceled={state.reload}
      />
      <ArchiveWorkDialog
        item={archiveItem}
        onOpenChange={(open) => { if (!open) setArchiveItem(null); }}
        onArchived={(archived) => {
          if (recentQueuedItem?.id === archived.id) setRecentQueuedItem(null);
          if (detailItem?.id === archived.id) setDetailItem(null);
          state.reload();
        }}
      />
      {projectId && (
        <AgentWorkDetailDialog
          projectId={projectId}
          item={detailItem}
          onOpenChange={(open) => { if (!open) setDetailItem(null); }}
        />
      )}
    </div>
  );
}
