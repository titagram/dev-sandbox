import React, { useEffect, useMemo, useRef, useState } from "react";
import { Link, useParams, useSearchParams } from "react-router-dom";
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
import { cn } from "@/lib/utils";
import { relativeTime, titleCase } from "@/lib/format";
import {
  AgentChatMessage, AgentChatResponse, AgentChatStatus, AgentChatThread,
  AgentChatThreadSummary, AgentKey, Role,
} from "@/types/devboard";
import {
  Archive, Boxes, Bot, Brain, Loader2, MessageSquare, Plus, RefreshCw, Send, Workflow,
} from "lucide-react";

type ChatAgentKey = "socrates" | "platon" | "aristoteles" | "local_agent";

const AGENT_OPTIONS: { value: ChatAgentKey; label: string }[] = [
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

const STATUS_TONE: Record<AgentChatStatus, any> = {
  active: "green",
  waiting_for_agent: "blue",
  pending_local_agent: "amber",
  failed: "red",
  archived: "slate",
};

const PENDING_STATUSES = new Set<AgentChatStatus>(["waiting_for_agent", "pending_local_agent"]);

function validAgent(value: string | null): ChatAgentKey {
  return AGENT_OPTIONS.some((agent) => agent.value === value) ? (value as ChatAgentKey) : "socrates";
}

function canWrite(role: Role | null | undefined): boolean {
  return role === "admin" || role === "pm" || role === "developer";
}

function threadPreview(thread: AgentChatThreadSummary): string {
  return thread.last_message?.content || thread.title;
}

function threadStatus(thread: AgentChatThreadSummary | AgentChatThread | null | undefined) {
  return thread?.status ? titleCase(thread.status.replace(/_/g, " ")) : "No thread";
}

function messageTone(message: AgentChatMessage): string {
  if (message.role === "assistant") return "border-blue-500/20 bg-blue-500/5";
  if (message.role === "system") return "border-amber-500/30 bg-amber-500/10";
  return "border-border bg-muted/20";
}

export default function AgentChatPage() {
  const { user } = useAuth();
  const { projectId } = useParams();
  const [searchParams, setSearchParams] = useSearchParams();
  const [selectedAgent, setSelectedAgent] = useState<ChatAgentKey>(validAgent(searchParams.get("agent")));
  const [activeThreadId, setActiveThreadId] = useState<string | null>(searchParams.get("thread"));
  const [composer, setComposer] = useState(searchParams.get("q") || "");
  const [saving, setSaving] = useState(false);
  const [archivingThreadId, setArchivingThreadId] = useState<string | null>(null);
  const initializedQuery = useRef(false);
  const bottomRef = useRef<HTMLDivElement | null>(null);
  const writable = canWrite(user?.role);

  const threadsState = useApi<AgentChatResponse>(
    () => projectId ? api.getAgentChats(projectId) : Promise.resolve({ threads: [] }),
    [projectId],
  );

  const activeThread = useMemo(
    () => threadsState.data?.threads.find((thread) => thread.id === activeThreadId) ?? null,
    [activeThreadId, threadsState.data],
  );

  const threadState = useApi<{ thread: AgentChatThread } | null>(
    () => projectId && activeThreadId ? api.getAgentChat(projectId, activeThreadId) : Promise.resolve(null),
    [projectId, activeThreadId],
  );
  const detail = threadState.data?.thread ?? null;
  const selectedThread = detail || activeThread;
  const threadArchived = selectedThread?.status === "archived" || Boolean(selectedThread?.archived_at);

  useEffect(() => {
    setActiveThreadId(searchParams.get("thread"));
    setSelectedAgent(validAgent(searchParams.get("agent")));
    if (!initializedQuery.current && searchParams.get("q")) {
      setComposer(searchParams.get("q") || "");
      initializedQuery.current = true;
    }
  }, [searchParams]);

  useEffect(() => {
    bottomRef.current?.scrollIntoView({ block: "end" });
  }, [detail?.messages.length, activeThreadId]);

  useEffect(() => {
    const status = detail?.status || activeThread?.status;
    if (!status || !PENDING_STATUSES.has(status)) return undefined;

    const timer = window.setInterval(() => {
      threadState.reload();
      threadsState.reload();
    }, 4500);

    return () => window.clearInterval(timer);
  }, [activeThread?.status, detail?.status, threadState, threadsState]);

  const setThread = (threadId: string | null) => {
    const next = new URLSearchParams(searchParams);
    if (threadId) next.set("thread", threadId);
    else next.delete("thread");
    next.delete("q");
    setSearchParams(next);
  };

  const submitMessage = async (event: React.FormEvent) => {
    event.preventDefault();
    const content = composer.trim();
    if (!projectId || !writable || !content || threadArchived) return;

    setSaving(true);
    try {
      const response = activeThreadId
        ? await api.sendAgentChatMessage(projectId, activeThreadId, { content, metadata: { source: "agent_chat_page" } })
        : await api.createAgentChat(projectId, {
            agent_key: selectedAgent,
            initial_message: content,
            title: content.length > 80 ? `${content.slice(0, 77)}...` : content,
            metadata: { source: "agent_chat_page" },
          });

      setComposer("");
      setThread(response.thread.id);
      threadsState.reload();
      threadState.reload();
    } catch (err: any) {
      toast.error(err?.message || "Message failed.");
    } finally {
      setSaving(false);
    }
  };

  const archiveThread = async () => {
    if (!projectId || !selectedThread || !writable || threadArchived || archivingThreadId) return;

    setArchivingThreadId(selectedThread.id);
    try {
      await api.archiveAgentChat(projectId, selectedThread.id, "Archived from agent chat.");
      toast.success("Conversation removed");
      setThread(null);
      threadsState.reload();
    } catch (err: any) {
      toast.error(err?.message || "Conversation remove failed.");
    } finally {
      setArchivingThreadId(null);
    }
  };

  if (!projectId) {
    return (
      <div className="space-y-5" data-testid="agent-chat-page">
        <PageHeader
          title="Agent Chat"
          subtitle="Agent conversations are scoped to a project workspace."
          actions={<Button size="sm" asChild><Link to="/projects"><Boxes className="mr-1.5 h-3.5 w-3.5" /> Projects</Link></Button>}
        />
        <Panel title="Project scope required">
          <p className="text-sm text-muted-foreground">Select a project to open agent conversations.</p>
        </Panel>
      </div>
    );
  }

  return (
    <div className="space-y-5" data-testid="agent-chat-page">
      <PageHeader
        title="Agent Chat"
        subtitle="Persistent project conversations with backend and local agents."
        meta={<Link to={`/projects/${projectId}`} className="inline-flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground"><Boxes className="h-3.5 w-3.5" />Project {projectId}</Link>}
        actions={
          <>
            <Button size="sm" variant="outline" onClick={() => { setThread(null); setComposer(""); }}>
              <Plus className="mr-1.5 h-3.5 w-3.5" /> New
            </Button>
            <Button size="sm" variant="outline" onClick={() => { threadsState.reload(); threadState.reload(); }}>
              <RefreshCw className="mr-1.5 h-3.5 w-3.5" /> Refresh
            </Button>
            <Button size="sm" variant="outline" asChild><Link to={`/projects/${projectId}/agent-work`}><Workflow className="mr-1.5 h-3.5 w-3.5" /> Agent Work</Link></Button>
            <Button size="sm" variant="outline" asChild><Link to={`/projects/${projectId}/memory`}><Brain className="mr-1.5 h-3.5 w-3.5" /> Memory</Link></Button>
          </>
        }
      />

      <div className="grid min-h-[620px] gap-4 xl:grid-cols-[300px_minmax(0,1fr)]">
        <Panel title={<span className="inline-flex items-center gap-1.5"><MessageSquare className="h-4 w-4 text-muted-foreground" />Threads</span>} dense>
          <div className="max-h-[620px] overflow-y-auto">
            {threadsState.loading && !threadsState.data && (
              <div className="flex items-center gap-2 p-4 text-sm text-muted-foreground">
                <Loader2 className="h-4 w-4 animate-spin" /> Loading
              </div>
            )}
            {threadsState.error && <div className="p-4 text-sm text-red-600 dark:text-red-400">{threadsState.error}</div>}
            {threadsState.data?.threads.length === 0 && (
              <div className="p-4 text-sm text-muted-foreground">No conversations yet.</div>
            )}
            <div className="divide-y divide-border/60">
              {(threadsState.data?.threads || []).map((thread) => (
                <button
                  key={thread.id}
                  type="button"
                  onClick={() => setThread(thread.id)}
                  className={cn(
                    "block w-full px-4 py-3 text-left transition-colors hover:bg-accent/40",
                    thread.id === activeThreadId && "bg-primary/10",
                  )}
                  data-testid={`agent-chat-thread-${thread.id}`}
                >
                  <div className="mb-1 flex items-center gap-2">
                    <span className="min-w-0 flex-1 truncate text-sm font-medium">{thread.title}</span>
                    <Pill tone={STATUS_TONE[thread.status]}>{threadStatus(thread)}</Pill>
                  </div>
                  <p className="line-clamp-2 text-xs text-muted-foreground">{threadPreview(thread)}</p>
                  <div className="mt-2 flex items-center justify-between gap-2 text-[11px] text-muted-foreground">
                    <span>{AGENT_LABELS[thread.agent_key] || titleCase(thread.agent_key)}</span>
                    <span>{thread.last_message_at ? relativeTime(thread.last_message_at) : relativeTime(thread.updated_at)}</span>
                  </div>
                </button>
              ))}
            </div>
          </div>
        </Panel>

        <Panel
          title={
            <span className="inline-flex min-w-0 items-center gap-2">
              <Bot className="h-4 w-4 shrink-0 text-muted-foreground" />
              <span className="truncate">{detail?.title || activeThread?.title || "New conversation"}</span>
              {(detail || activeThread) && <Pill tone={STATUS_TONE[(detail || activeThread)!.status]}>{threadStatus(detail || activeThread)}</Pill>}
            </span>
          }
          action={selectedThread && writable && !threadArchived ? (
            <Button
              size="sm"
              variant="outline"
              type="button"
              onClick={archiveThread}
              disabled={archivingThreadId === selectedThread.id}
              data-testid="agent-chat-archive-thread"
            >
              {archivingThreadId === selectedThread.id ? <Loader2 className="mr-1.5 h-3.5 w-3.5 animate-spin" /> : <Archive className="mr-1.5 h-3.5 w-3.5" />}
              Remove
            </Button>
          ) : undefined}
          dense
        >
          <div className="flex min-h-[620px] flex-col">
            <div className="flex-1 overflow-y-auto p-4">
              {!detail && !activeThreadId && (
                <div className="grid h-full place-items-center rounded-md border border-dashed border-border py-16 text-center">
                  <div>
                    <Bot className="mx-auto mb-3 h-8 w-8 text-muted-foreground/60" />
                    <p className="text-sm font-medium">New conversation</p>
                    <p className="mt-1 text-xs text-muted-foreground">Choose an agent and send the first message.</p>
                  </div>
                </div>
              )}
              {threadState.loading && activeThreadId && !detail && (
                <div className="flex items-center gap-2 text-sm text-muted-foreground">
                  <Loader2 className="h-4 w-4 animate-spin" /> Loading conversation
                </div>
              )}
              {threadState.error && <div className="rounded-md border border-red-500/30 bg-red-500/5 p-3 text-sm text-red-600 dark:text-red-400">{threadState.error}</div>}
              {detail && (
                <div className="space-y-3">
                  {detail.messages.map((message) => (
                    <div key={message.id} className={cn("rounded-md border px-3 py-2.5", messageTone(message))}>
                      <div className="mb-1 flex flex-wrap items-center gap-2">
                        <Pill tone={message.role === "assistant" ? "blue" : message.role === "system" ? "amber" : "slate"}>{message.role}</Pill>
                        <span className="text-[11px] text-muted-foreground">{relativeTime(message.created_at)}</span>
                        {message.agent_work_item_id && <span className="font-mono text-[11px] text-muted-foreground">{message.agent_work_item_id}</span>}
                      </div>
                      <p className="whitespace-pre-wrap text-sm leading-6">{message.content}</p>
                    </div>
                  ))}
                  <div ref={bottomRef} />
                </div>
              )}
            </div>

            <form onSubmit={submitMessage} className="border-t border-border p-4">
              <div className="grid gap-3 md:grid-cols-[190px_minmax(0,1fr)_auto] md:items-end">
                <div className="space-y-1.5">
                  <Label className="text-xs">Agent</Label>
                  <Select
                    value={activeThreadId ? (detail?.agent_key || activeThread?.agent_key || selectedAgent) : selectedAgent}
                    onValueChange={(value) => setSelectedAgent(value as ChatAgentKey)}
                    disabled={saving || Boolean(activeThreadId)}
                  >
                    <SelectTrigger data-testid="agent-chat-agent-select"><SelectValue /></SelectTrigger>
                    <SelectContent>
                      {AGENT_OPTIONS.map((agent) => (
                        <SelectItem key={agent.value} value={agent.value}>{agent.label}</SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
                <div className="space-y-1.5">
                  <Label htmlFor="agent-chat-composer" className="text-xs">Message</Label>
                  <Textarea
                    id="agent-chat-composer"
                    value={composer}
                    onChange={(event) => setComposer(event.target.value)}
                    className="min-h-24 resize-y"
                    maxLength={8000}
                    disabled={saving || !writable || threadArchived}
                    data-testid="agent-chat-composer"
                  />
                </div>
                <Button type="submit" disabled={saving || !writable || threadArchived || !composer.trim()} data-testid="agent-chat-send">
                  {saving ? <Loader2 className="mr-1.5 h-3.5 w-3.5 animate-spin" /> : <Send className="mr-1.5 h-3.5 w-3.5" />}
                  Send
                </Button>
              </div>
              {!writable && <p className="mt-2 text-xs text-muted-foreground">Read-only access.</p>}
              {threadArchived && <p className="mt-2 text-xs text-muted-foreground">Archived conversation.</p>}
            </form>
          </div>
        </Panel>
      </div>
    </div>
  );
}
