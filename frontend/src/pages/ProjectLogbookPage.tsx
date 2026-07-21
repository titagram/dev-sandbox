import React, { useEffect, useMemo, useState } from "react";
import { Link, useNavigate, useParams } from "react-router-dom";
import ReactMarkdown from "react-markdown";
import rehypeSanitize from "rehype-sanitize";
import remarkGfm from "remark-gfm";
import { Boxes, FileText, Plus, RefreshCw } from "lucide-react";
import { api } from "@/api/devboardApi";
import { useAuth } from "@/context/AuthContext";
import { useApi } from "@/hooks/useApi";
import { EmptyState, ErrorState, LoadingState } from "@/components/devboard/DataState";
import { PageHeader, Panel } from "@/components/devboard/Layout";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Textarea } from "@/components/ui/textarea";
import { formatDateTime, relativeTime } from "@/lib/format";
import {
  ProjectLogbookActorKind, ProjectLogbookEntry, ProjectLogbookEventType,
  ProjectLogbookQuery, ProjectLogbookReference, ProjectLogbookSeverity, Role,
} from "@/types/devboard";

const ALL = "__all__";
const EVENT_TYPES: ProjectLogbookEventType[] = ["change", "creation", "import", "projection", "verification", "wiki", "decision", "failure", "rollback", "note"];
const ACTOR_KINDS: ProjectLogbookActorKind[] = ["user", "agent", "subagent", "system"];
const SEVERITIES: ProjectLogbookSeverity[] = ["info", "warning", "error"];

function escapeRawHtml(value: string): string {
  return value.replace(/<\/?[A-Za-z][^>]*>/g, (tag) => tag.replaceAll("<", "&lt;").replaceAll(">", "&gt;"));
}

function labelFor(value: string): string {
  return value.replace(/_/g, " ").replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function canAddNote(role: Role | null | undefined): boolean {
  return role === "admin" || role === "pm" || role === "developer";
}

function addUnique(entries: ProjectLogbookEntry[], additions: ProjectLogbookEntry[]): ProjectLogbookEntry[] {
  const byId = new Map(entries.map((entry) => [entry.id, entry]));
  additions.forEach((entry) => byId.set(entry.id, entry));
  return Array.from(byId.values()).sort((left, right) => (right.recorded_at || "").localeCompare(left.recorded_at || ""));
}

function referenceTarget(projectId: string, reference: ProjectLogbookReference): string | null {
  const base = `/projects/${encodeURIComponent(projectId)}`;
  if (reference.kind === "wiki_page") return `${base}/wiki/${encodeURIComponent(reference.id)}`;
  if (reference.kind === "graph_import") return `${base}/graph`;
  if (reference.kind === "kanban_task") return `${base}/kanban`;
  if (reference.kind === "run") return `${base}/runs/${encodeURIComponent(reference.id)}`;
  return null;
}

function References({ projectId, references }: { projectId: string; references: ProjectLogbookReference[] }) {
  if (references.length === 0) return null;
  return <div className="mt-3 flex flex-wrap gap-1.5" aria-label="References">
    {references.map((reference) => {
      const target = referenceTarget(projectId, reference);
      const label = `${reference.kind.replace(/_/g, " ")}: ${reference.id}`;
      const classes = "max-w-full truncate rounded border border-border bg-background px-1.5 py-0.5 font-mono text-[11px] text-muted-foreground";
      return target ? <Link key={`${reference.kind}:${reference.id}`} to={target} className={`${classes} hover:border-primary/60 hover:text-foreground`}>{label}</Link>
        : <span key={`${reference.kind}:${reference.id}`} className={classes} title="This reference cannot be opened from the project dashboard.">{label} · unavailable here</span>;
    })}
  </div>;
}

function TimelineEntry({ entry, projectId }: { entry: ProjectLogbookEntry; projectId: string }) {
  const [technicalEvidenceOpen, setTechnicalEvidenceOpen] = useState(false);
  return <Panel dense className="overflow-hidden">
    <article className="p-4" data-testid={`logbook-entry-${entry.id}`}>
      <div className="flex flex-wrap items-start justify-between gap-x-4 gap-y-2">
        <div className="min-w-0">
          <div className="flex flex-wrap items-center gap-2">
            <span className="rounded bg-muted px-1.5 py-0.5 text-[11px] font-medium text-foreground">{labelFor(entry.event_type)}</span>
            <span className={`rounded px-1.5 py-0.5 text-[11px] font-medium ${entry.severity === "error" ? "bg-red-500/15 text-red-700 dark:text-red-300" : entry.severity === "warning" ? "bg-amber-500/15 text-amber-700 dark:text-amber-300" : "bg-sky-500/15 text-sky-700 dark:text-sky-300"}`}>{entry.severity}</span>
          </div>
          <h2 className="mt-2 text-sm font-semibold text-foreground">{entry.summary}</h2>
          <p className="mt-1 text-xs text-muted-foreground">{entry.actor.label} · {labelFor(entry.actor.kind)}</p>
        </div>
        <time className="shrink-0 text-right text-xs text-muted-foreground" dateTime={entry.recorded_at || undefined} title={formatDateTime(entry.recorded_at)}>
          {relativeTime(entry.recorded_at)}<br /><span>{entry.recorded_at ? formatDateTime(entry.recorded_at) : "Time not recorded"}</span>
        </time>
      </div>
      {entry.narrative_markdown && <div className="prose prose-sm mt-3 max-w-none text-muted-foreground dark:prose-invert">
        <ReactMarkdown remarkPlugins={[remarkGfm]} rehypePlugins={[[rehypeSanitize, { protocols: { href: ["http", "https", "mailto"], src: [] } }]]}>{escapeRawHtml(entry.narrative_markdown)}</ReactMarkdown>
      </div>}
      <References projectId={projectId} references={entry.references} />
      <div className="mt-3 rounded border border-border bg-muted/20">
        <button type="button" className="w-full px-3 py-2 text-left text-xs font-medium text-muted-foreground" aria-expanded={technicalEvidenceOpen} onClick={() => setTechnicalEvidenceOpen((open) => !open)}>Technical evidence</button>
        {technicalEvidenceOpen && <pre className="max-h-72 overflow-auto border-t border-border p-3 text-[11px] text-muted-foreground">{JSON.stringify(entry.payload, null, 2)}</pre>}
      </div>
    </article>
  </Panel>;
}

function AddNote({ projectId, onAdded }: { projectId: string; onAdded: (entry: ProjectLogbookEntry) => void }) {
  const [open, setOpen] = useState(false);
  const [summary, setSummary] = useState("");
  const [narrative, setNarrative] = useState("");
  const [saving, setSaving] = useState(false);
  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    if (!summary.trim()) return;
    setSaving(true);
    try {
      const response = await api.createProjectLogbookNote(projectId, {
        event_type: "note", severity: "info", summary: summary.trim(), narrative_markdown: narrative.trim() || null,
        references: [], correlation_id: null, idempotency_key: typeof crypto?.randomUUID === "function" ? crypto.randomUUID() : `dashboard-note-${Date.now()}-${Math.random()}`, supersedes_entry_id: null,
      });
      onAdded(response.entry);
      setOpen(false); setSummary(""); setNarrative("");
    } finally {
      setSaving(false);
    }
  };
  return <div>
    <Button size="sm" type="button" onClick={() => setOpen((value) => !value)} data-testid="add-logbook-note"><Plus className="mr-1.5 h-3.5 w-3.5" />Add note</Button>
    {open && <form onSubmit={submit} className="mt-3 grid gap-2 rounded border border-border bg-card p-3">
      <Input value={summary} onChange={(event) => setSummary(event.target.value)} placeholder="What should the project remember?" maxLength={240} autoFocus />
      <Textarea value={narrative} onChange={(event) => setNarrative(event.target.value)} placeholder="Optional Markdown context" maxLength={8000} />
      <div className="flex justify-end gap-2"><Button type="button" variant="outline" onClick={() => setOpen(false)} disabled={saving}>Cancel</Button><Button type="submit" disabled={saving || !summary.trim()}>Save note</Button></div>
    </form>}
  </div>;
}

function ProjectLogbookTimeline({ projectId }: { projectId: string }) {
  const { user } = useAuth();
  const [type, setType] = useState<string>(ALL);
  const [actor, setActor] = useState<string>(ALL);
  const [severity, setSeverity] = useState<string>(ALL);
  const [from, setFrom] = useState("");
  const [to, setTo] = useState("");
  const [query, setQuery] = useState("");
  const filters = useMemo<ProjectLogbookQuery>(() => ({
    types: type === ALL ? undefined : [type as ProjectLogbookEventType],
    actor: actor === ALL ? undefined : actor as ProjectLogbookActorKind,
    severity: severity === ALL ? undefined : severity as ProjectLogbookSeverity,
    from: from || undefined, to: to || undefined, q: query.trim() || undefined, limit: 20,
  }), [actor, from, query, severity, to, type]);
  const filterKey = JSON.stringify(filters);
  const [items, setItems] = useState<ProjectLogbookEntry[]>([]);
  const [nextCursor, setNextCursor] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const [loadingMore, setLoadingMore] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const load = async (cursor?: string, append = false) => {
    if (append) setLoadingMore(true); else { setLoading(true); setError(null); }
    try {
      const response = await api.getProjectLogbook(projectId, { ...filters, cursor });
      setItems((current) => append ? addUnique(current, response.items) : response.items);
      setNextCursor(response.next_cursor);
      setError(null);
    } catch (reason: any) {
      setError(reason?.message || "The logbook could not be loaded.");
    } finally {
      if (append) setLoadingMore(false); else setLoading(false);
    }
  };

  useEffect(() => {
    let active = true;
    setLoading(true); setError(null); setItems([]); setNextCursor(null);
    api.getProjectLogbook(projectId, filters).then(
      (response) => { if (active) { setItems(response.items); setNextCursor(response.next_cursor); setLoading(false); } },
      (reason: any) => { if (active) { setError(reason?.message || "The logbook could not be loaded."); setLoading(false); } },
    );
    return () => { active = false; };
  }, [projectId, filterKey]);

  return <div className="space-y-4">
    <div className="flex flex-wrap items-center gap-2" aria-label="Logbook filters">
      <Input value={query} onChange={(event) => setQuery(event.target.value)} placeholder="Search the logbook" className="h-8 min-w-48 flex-1 text-xs" data-testid="logbook-query" />
      <Select value={type} onValueChange={setType}><SelectTrigger className="h-8 w-36 text-xs"><span>Type:</span><SelectValue /></SelectTrigger><SelectContent><SelectItem value={ALL}>All types</SelectItem>{EVENT_TYPES.map((value) => <SelectItem key={value} value={value}>{labelFor(value)}</SelectItem>)}</SelectContent></Select>
      <Select value={actor} onValueChange={setActor}><SelectTrigger className="h-8 w-36 text-xs"><span>Actor:</span><SelectValue /></SelectTrigger><SelectContent><SelectItem value={ALL}>All actors</SelectItem>{ACTOR_KINDS.map((value) => <SelectItem key={value} value={value}>{labelFor(value)}</SelectItem>)}</SelectContent></Select>
      <Select value={severity} onValueChange={setSeverity}><SelectTrigger className="h-8 w-36 text-xs"><span>Severity:</span><SelectValue /></SelectTrigger><SelectContent><SelectItem value={ALL}>All severities</SelectItem>{SEVERITIES.map((value) => <SelectItem key={value} value={value}>{labelFor(value)}</SelectItem>)}</SelectContent></Select>
      <Input aria-label="From time" type="datetime-local" value={from} onChange={(event) => setFrom(event.target.value)} className="h-8 w-44 text-xs" />
      <Input aria-label="To time" type="datetime-local" value={to} onChange={(event) => setTo(event.target.value)} className="h-8 w-44 text-xs" />
    </div>
    {loading && items.length === 0 && <LoadingState label="Loading project logbook…" />}
    {error && items.length === 0 && <ErrorState message={error} onRetry={() => void load()} />}
    {error && items.length > 0 && <div className="flex items-center justify-between rounded border border-amber-500/30 bg-amber-500/5 px-3 py-2 text-xs text-muted-foreground" role="status">Some newer logbook data could not be loaded.<Button size="sm" variant="outline" onClick={() => void load()}>Retry</Button></div>}
    {!loading && !error && items.length === 0 && <EmptyState title="No logbook activity yet" hint="Project events and dashboard notes will appear here in newest-first order." icon={FileText} />}
    {items.map((entry) => <TimelineEntry key={entry.id} entry={entry} projectId={projectId} />)}
    {nextCursor && <div className="flex justify-center"><Button variant="outline" onClick={() => void load(nextCursor, true)} disabled={loadingMore}>{loadingMore ? "Loading…" : <><RefreshCw className="mr-1.5 h-3.5 w-3.5" />Load more</>}</Button></div>}
    {canAddNote(user?.role) && <AddNote projectId={projectId} onAdded={(entry) => setItems((current) => addUnique(current, [entry]))} />}
  </div>;
}

function GlobalLogbookPage() {
  const navigate = useNavigate();
  const projects = useApi(() => api.getProjects("active"), []);
  return <div className="space-y-4" data-testid="project-logbook-page">
    <PageHeader title="Project logbook" subtitle="Choose a project to view its immutable activity timeline." />
    {projects.loading && <LoadingState label="Loading projects…" />}
    {projects.error && <ErrorState message={projects.error} onRetry={projects.reload} />}
    {projects.data && <Panel title="Choose a project"><select aria-label="Project" defaultValue="" onChange={(event) => event.target.value && navigate(`/projects/${encodeURIComponent(event.target.value)}/logbook`)} className="block w-full max-w-xl rounded border border-input bg-background px-3 py-2 text-sm"><option value="">Select a project</option>{projects.data.map((project) => <option key={project.id} value={project.id}>{project.name}</option>)}</select></Panel>}
  </div>;
}

export default function ProjectLogbookPage() {
  const { projectId } = useParams();
  if (!projectId) return <GlobalLogbookPage />;
  return <div className="space-y-4" data-testid="project-logbook-page">
    <PageHeader title="Project logbook" subtitle="Newest-first project activity with immutable system and human events."
      meta={<Link to={`/projects/${encodeURIComponent(projectId)}`} className="inline-flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground"><Boxes className="h-3.5 w-3.5" />Project {projectId}</Link>} />
    <ProjectLogbookTimeline projectId={projectId} />
  </div>;
}
