import React, { useEffect, useMemo, useState } from "react";
import { Link, useNavigate, useParams } from "react-router-dom";
import { toast } from "sonner";
import { api } from "@/api/devboardApi";
import { useApi } from "@/hooks/useApi";
import { useAuth } from "@/context/AuthContext";
import { PageHeader, Panel } from "@/components/devboard/Layout";
import { Pill } from "@/components/devboard/Badges";
import { Button } from "@/components/ui/button";
import { Label } from "@/components/ui/label";
import {
  Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from "@/components/ui/select";
import { Textarea } from "@/components/ui/textarea";
import {
  AgentChatCreateInput, AgentChatThread, AgentWorkPriority, KanbanBoard,
  ProjectDetail, Role,
} from "@/types/devboard";
import {
  Boxes, Brain, BookText, KanbanSquare, Loader2, MessageSquare,
  PlayCircle, Send, Workflow,
} from "lucide-react";

const NONE = "__none__";

type AskAgentKey = "socrates" | "platon" | "aristoteles";

const ASK_AGENT_OPTIONS: { value: AskAgentKey; label: string }[] = [
  { value: "socrates", label: "Socrates" },
  { value: "platon", label: "Platon" },
  { value: "aristoteles", label: "Aristoteles" },
];

const AGENT_LABELS: Record<AskAgentKey, string> = {
  socrates: "Socrates",
  platon: "Platon",
  aristoteles: "Aristoteles",
};

const PRIORITY_OPTIONS: AgentWorkPriority[] = ["low", "normal", "high", "urgent"];

type AskFormState = {
  agentKey: AskAgentKey;
  priority: AgentWorkPriority;
  repositoryId: string;
  taskId: string;
  question: string;
};

const emptyAskForm: AskFormState = {
  agentKey: "socrates",
  priority: "normal",
  repositoryId: NONE,
  taskId: NONE,
  question: "",
};

function canMutateRole(role: Role | null | undefined): boolean {
  return role === "admin" || role === "pm" || role === "developer";
}

function canAskProject(project: ProjectDetail | null | undefined, role: Role | null | undefined): boolean {
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

function askStatusLabel({
  canSubmit,
  loading,
  error,
  project,
  stale,
}: {
  canSubmit: boolean;
  loading: boolean;
  error: string | null;
  project: ProjectDetail | null;
  stale: boolean;
}) {
  if (canSubmit) return { tone: "blue" as const, label: "Tracked queue" };
  if (error) return { tone: "red" as const, label: "Project details unavailable" };
  if (loading || stale) return { tone: "slate" as const, label: "Loading project" };
  if (project && project.status !== "active") return { tone: "slate" as const, label: "Inactive project" };
  return { tone: "slate" as const, label: "Queue unavailable" };
}

export default function AskAgentsPage() {
  const { user } = useAuth();
  const { projectId } = useParams();
  const nav = useNavigate();
  const [form, setForm] = useState<AskFormState>(emptyAskForm);
  const [saving, setSaving] = useState(false);
  const [chatThread, setChatThread] = useState<AgentChatThread | null>(null);
  const scopedPath = (path: string) => projectId ? `/projects/${projectId}${path}` : path;
  const projectState = useApi<ProjectDetail | null>(
    () => projectId ? api.getProject(projectId) : Promise.resolve(null),
    [projectId],
  );
  const matchedProject = projectMatchesRoute(projectState.data, projectId) ? projectState.data : null;
  const projectStale = Boolean(projectState.data && projectId && projectState.data.id !== projectId);
  const canLoadOptions = Boolean(projectId && matchedProject);
  const boardState = useApi<KanbanBoard | null>(
    () => canLoadOptions && projectId ? api.getKanban(projectId) : Promise.resolve(null),
    [projectId, canLoadOptions],
  );
  const taskOptions = useMemo(
    () => projectId && !boardState.loading ? taskOptionsFromBoard(boardState.data, projectId) : [],
    [boardState.data, boardState.loading, projectId],
  );
  const canSubmit = canAskProject(matchedProject, user?.role);
  const status = askStatusLabel({
    canSubmit,
    loading: projectState.loading && !matchedProject,
    error: projectState.error,
    project: matchedProject,
    stale: projectStale,
  });
  const formDisabled = saving || !canSubmit;

  useEffect(() => {
    setForm(emptyAskForm);
    setChatThread(null);
  }, [projectId]);

  const submitQuestion = async (event: React.FormEvent) => {
    event.preventDefault();
    const question = form.question.trim();

    if (!projectId || !matchedProject || matchedProject.id !== projectId) {
      toast.error("Project details must load before queuing a question.");
      return;
    }

    if (!canSubmit) {
      toast.error("Questions can only be queued for active projects.");
      return;
    }

    if (form.repositoryId !== NONE && !matchedProject.repositories.some((repository) => repository.id === form.repositoryId)) {
      toast.error("Refresh project repositories before queuing this question.");
      return;
    }

    if (form.taskId !== NONE && !taskOptions.some((task) => task.id === form.taskId)) {
      toast.error("Refresh project tasks before queuing this question.");
      return;
    }

    if (question.length < 8 || question.length > 8000) {
      toast.error("Question must be 8 to 8000 characters.");
      return;
    }

    const normalizedQuestion = question.replace(/\s+/g, " ");
    const shortQuestion = normalizedQuestion.length > 80
      ? `${normalizedQuestion.slice(0, 77)}...`
      : normalizedQuestion;
    const input: AgentChatCreateInput = {
      agent_key: form.agentKey,
      title: `Ask ${AGENT_LABELS[form.agentKey]}: ${shortQuestion}`,
      initial_message: question,
      metadata: {
        source: "ask_page",
        question,
        target_agent: form.agentKey,
        priority: form.priority,
      },
    };

    if (form.repositoryId !== NONE) input.repository_id = form.repositoryId;
    if (form.taskId !== NONE) input.task_id = form.taskId;

    setSaving(true);
    try {
      const created = await api.createAgentChat(projectId, input);
      toast.success("Chat started");
      setChatThread(created.thread);
      setForm((prev) => ({ ...prev, question: "" }));
      nav(`/projects/${projectId}/agent-chat?thread=${encodeURIComponent(created.thread.id)}`);
    } catch (err: any) {
      toast.error(err?.message || "Chat start failed.");
    } finally {
      setSaving(false);
    }
  };

  if (!projectId) {
    return (
      <div className="space-y-5" data-testid="ask-agents-page">
        <PageHeader
          title="Ask"
          subtitle="Agent questions are scoped to a project workspace."
          actions={<Button size="sm" asChild><Link to="/projects"><Boxes className="mr-1.5 h-3.5 w-3.5" /> Projects</Link></Button>}
        />
        <Panel title="Project scope required">
          <p className="text-sm text-muted-foreground">Select a project before asking about project memory, wiki evidence, runs, artifacts, or task work.</p>
        </Panel>
      </div>
    );
  }

  return (
    <div className="space-y-5" data-testid="ask-agents-page">
      <PageHeader
          title="Ask"
          subtitle="Start a project-scoped agent conversation."
        meta={<Link to={`/projects/${projectId}`} className="inline-flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground"><Boxes className="h-3.5 w-3.5" />Project {projectId}</Link>}
        actions={
          <>
            {projectState.error && <Pill tone="red">Project details unavailable</Pill>}
            {matchedProject && matchedProject.status !== "active" && <Pill tone="slate">Inactive project</Pill>}
            <Button size="sm" variant="outline" asChild><Link to={`/projects/${projectId}/agent-work`}><Workflow className="mr-1.5 h-3.5 w-3.5" /> Agent Work</Link></Button>
          </>
        }
      />

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
        <Panel title={<span className="inline-flex items-center gap-1.5"><MessageSquare className="h-4 w-4 text-muted-foreground" />Question intake</span>} className="lg:col-span-2">
          <form onSubmit={submitQuestion} className="space-y-4">
            <div className="grid gap-3 md:grid-cols-2">
              <div className="space-y-1.5">
                <Label className="text-xs">Agent</Label>
                <Select
                  value={form.agentKey}
                  onValueChange={(value) => setForm((prev) => ({ ...prev, agentKey: value as AskAgentKey }))}
                  disabled={formDisabled}
                >
                  <SelectTrigger data-testid="ask-agent-select"><SelectValue /></SelectTrigger>
                  <SelectContent>
                    {ASK_AGENT_OPTIONS.map((agent) => (
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
                  disabled={formDisabled}
                >
                  <SelectTrigger data-testid="ask-priority-select"><SelectValue /></SelectTrigger>
                  <SelectContent>
                    {PRIORITY_OPTIONS.map((priority) => (
                      <SelectItem key={priority} value={priority}>{priority.replace(/_/g, " ")}</SelectItem>
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
                  disabled={formDisabled}
                >
                  <SelectTrigger data-testid="ask-repository-select"><SelectValue /></SelectTrigger>
                  <SelectContent>
                    <SelectItem value={NONE}>None</SelectItem>
                    {(matchedProject?.repositories || []).map((repository) => (
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
                  disabled={formDisabled}
                >
                  <SelectTrigger data-testid="ask-task-select"><SelectValue /></SelectTrigger>
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
              <Label htmlFor="ask-query-input" className="text-xs">Question</Label>
              <Textarea
                id="ask-query-input"
                className="min-h-36"
                value={form.question}
                onChange={(event) => setForm((prev) => ({ ...prev, question: event.target.value }))}
                placeholder="Ask about this project's current work, memory, wiki evidence, runs, or artifacts."
                maxLength={8000}
                disabled={formDisabled}
                data-testid="ask-query-input"
              />
            </div>

            <div className="flex flex-wrap items-center gap-2">
              <Button type="submit" size="sm" disabled={formDisabled} data-testid="ask-submit-btn">
                {saving ? <Loader2 className="mr-1.5 h-3.5 w-3.5 animate-spin" /> : <Send className="mr-1.5 h-3.5 w-3.5" />}
                Start chat
              </Button>
              <Pill tone={status.tone}>{status.label}</Pill>
            </div>
          </form>

          {chatThread && (
            <div className="mt-4 flex flex-wrap items-center gap-3 rounded-md border border-border bg-muted/20 px-3 py-2.5" data-testid="ask-queued-item">
              <Pill tone="green">Started</Pill>
              <span className="min-w-0 flex-1 truncate text-sm">{chatThread.title}</span>
              <Button size="sm" variant="outline" asChild>
                <Link to={`/projects/${projectId}/agent-chat?thread=${encodeURIComponent(chatThread.id)}`}>Open Chat</Link>
              </Button>
            </div>
          )}
        </Panel>

        <Panel title="Tracked surfaces" dense>
          <nav className="divide-y divide-border/60">
            {[
              { to: scopedPath("/agent-chat"), icon: MessageSquare, label: "Chat", detail: "Persistent agent conversations" },
              { to: scopedPath("/agent-work"), icon: Workflow, label: "Agent Work", detail: "Queued questions and run status" },
              { to: scopedPath("/memory"), icon: Brain, label: "Memory", detail: "Durable responses and handoffs" },
              { to: scopedPath("/kanban"), icon: KanbanSquare, label: "Work", detail: "Project tasks and blockers" },
              { to: scopedPath("/wiki"), icon: BookText, label: "Wiki", detail: "Evidence-backed pages" },
              { to: scopedPath("/runs"), icon: PlayCircle, label: "Runs", detail: "Plugin and analyzer activity" },
            ].map((item) => (
              <Link key={item.to} to={item.to} className="flex items-start gap-3 px-4 py-3 transition-colors hover:bg-accent/40">
                <item.icon className="mt-0.5 h-4 w-4 text-muted-foreground" />
                <span>
                  <span className="block text-sm font-medium">{item.label}</span>
                  <span className="block text-xs text-muted-foreground">{item.detail}</span>
                </span>
              </Link>
            ))}
          </nav>
        </Panel>
      </div>
    </div>
  );
}
