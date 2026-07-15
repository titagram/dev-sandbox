import React, { useEffect, useMemo, useState } from "react";
import { Link, useNavigate, useParams } from "react-router-dom";
import { toast } from "sonner";
import { api } from "@/api/devboardApi";
import { useApi } from "@/hooks/useApi";
import { useAuth } from "@/context/AuthContext";
import { PageHeader, Panel } from "@/components/devboard/Layout";
import { DataState } from "@/components/devboard/DataState";
import { SourceStatusBadge, Pill } from "@/components/devboard/Badges";
import { Button } from "@/components/ui/button";
import {
  Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle,
} from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Textarea } from "@/components/ui/textarea";
import { relativeTime } from "@/lib/format";
import { BookPlus, BookText, Boxes, FileWarning, CheckCircle2, Loader2, RefreshCw, Search } from "lucide-react";
import { Role, WikiPageDetail, WikiPageWriteInput, WikiRefreshRequest, WikiRefreshScope } from "@/types/devboard";

const ALL = "__all__";
const NO_BINDING = "__no_binding__";
const NO_REPOSITORY = "__no_repository__";
const PAGE_TYPES: WikiPageWriteInput["page_type"][] = ["business", "technical", "runbook", "audit"];
type WikiCreateForm = {
  slug: string;
  title: string;
  pageType: WikiPageWriteInput["page_type"];
  content: string;
};

const emptyWikiCreateForm: WikiCreateForm = {
  slug: "",
  title: "",
  pageType: "technical",
  content: "",
};

function canMutateRole(role: Role | null | undefined): boolean {
  return role === "admin" || role === "pm" || role === "developer";
}

function slugFromTitle(value: string): string {
  return value
    .trim()
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-+|-+$/g, "");
}

function refreshTone(status: string): "green" | "amber" | "red" | "blue" | "slate" {
  if (["completed", "passed", "reviewed"].includes(status)) return "green";
  if (["queued", "pending"].includes(status)) return "amber";
  if (["running", "in_progress"].includes(status)) return "blue";
  if (["failed", "cancelled", "canceled"].includes(status)) return "red";
  return "slate";
}

function latestRefresh(requests: WikiRefreshRequest[] | null): WikiRefreshRequest | null {
  return [...(requests || [])].sort((a, b) => String(b.created_at || "").localeCompare(String(a.created_at || "")))[0] || null;
}

interface AppliedWikiPage {
  wiki_page_id?: string;
  slug?: string;
  title?: string;
  source_status?: string;
}

function latestAppliedPages(request: WikiRefreshRequest | null): AppliedWikiPage[] {
  const applied = request?.result?.applied;
  if (!applied || typeof applied !== "object" || Array.isArray(applied)) return [];
  const pages = (applied as { pages?: unknown }).pages;
  if (!Array.isArray(pages)) return [];
  return pages
    .filter((page): page is Record<string, unknown> => !!page && typeof page === "object" && !Array.isArray(page))
    .map((page) => ({
      wiki_page_id: typeof page.wiki_page_id === "string" ? page.wiki_page_id : undefined,
      slug: typeof page.slug === "string" ? page.slug : undefined,
      title: typeof page.title === "string" ? page.title : undefined,
      source_status: typeof page.source_status === "string" ? page.source_status : undefined,
    }));
}

function latestPagesWritten(request: WikiRefreshRequest | null): number | null {
  const applied = request?.result?.applied;
  if (!applied || typeof applied !== "object" || Array.isArray(applied)) return null;
  const pagesWritten = (applied as { pages_written?: unknown }).pages_written;
  return typeof pagesWritten === "number" ? pagesWritten : null;
}

function CreateWikiPageDialog({
  projectId,
  onCreated,
}: {
  projectId: string;
  onCreated: (page: WikiPageDetail) => void;
}) {
  const [open, setOpen] = useState(false);
  const [form, setForm] = useState<WikiCreateForm>(emptyWikiCreateForm);
  const [saving, setSaving] = useState(false);

  const setDialogOpen = (nextOpen: boolean) => {
    if (saving) return;
    setOpen(nextOpen);
    if (!nextOpen) setForm(emptyWikiCreateForm);
  };

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    const title = form.title.trim();
    const slug = (form.slug.trim() || slugFromTitle(title)).replace(/^\/+|\/+$/g, "");
    const content = form.content.trim();

    if (!slug || !/^[a-z0-9][a-z0-9/-]*$/.test(slug)) {
      toast.error("Slug must use lowercase letters, numbers, dashes, and slashes.");
      return;
    }
    if (title.length < 3) {
      toast.error("Title is required.");
      return;
    }
    if (!content) {
      toast.error("Markdown content is required.");
      return;
    }

    setSaving(true);
    try {
      const page = await api.createWikiPage(projectId, {
        slug,
        title,
        page_type: form.pageType,
        content_markdown: content,
      });
      toast.success(`Wiki page created: ${page.title}`);
      setDialogOpen(false);
      onCreated(page);
    } catch (err: any) {
      toast.error(err?.message || "Wiki page create failed.");
    } finally {
      setSaving(false);
    }
  };

  return (
    <Dialog open={open} onOpenChange={setDialogOpen}>
      <Button size="sm" type="button" onClick={() => setDialogOpen(true)} data-testid="create-wiki-page-btn">
        <BookPlus className="mr-1.5 h-3.5 w-3.5" />
        New page
      </Button>
      <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-3xl" data-testid="create-wiki-page-dialog">
        <form onSubmit={submit} className="space-y-4">
          <DialogHeader>
            <DialogTitle>New wiki page</DialogTitle>
            <DialogDescription>Create or paste a Markdown page. Manual pages always enter the needs verification queue until code verification is completed.</DialogDescription>
          </DialogHeader>

          <div className="grid gap-3 md:grid-cols-2">
            <div className="space-y-1.5">
              <Label htmlFor="wiki-title" className="text-xs">Title</Label>
              <Input
                id="wiki-title"
                value={form.title}
                onChange={(event) => setForm((prev) => ({
                  ...prev,
                  title: event.target.value,
                  slug: prev.slug || slugFromTitle(event.target.value),
                }))}
                disabled={saving}
                data-testid="wiki-title-input"
                autoFocus
              />
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="wiki-slug" className="text-xs">Slug</Label>
              <Input
                id="wiki-slug"
                value={form.slug}
                onChange={(event) => setForm((prev) => ({ ...prev, slug: event.target.value }))}
                placeholder="architecture/current-state"
                disabled={saving}
                data-testid="wiki-slug-input"
              />
            </div>
          </div>

          <div className="grid gap-3 md:grid-cols-1">
            <div className="space-y-1.5">
              <Label className="text-xs">Page type</Label>
              <Select value={form.pageType} onValueChange={(value) => setForm((prev) => ({ ...prev, pageType: value as WikiCreateForm["pageType"] }))} disabled={saving}>
                <SelectTrigger data-testid="wiki-page-type-select"><SelectValue /></SelectTrigger>
                <SelectContent>
                  {PAGE_TYPES.map((pageType) => <SelectItem key={pageType} value={pageType}>{pageType}</SelectItem>)}
                </SelectContent>
              </Select>
            </div>
          </div>

          <div className="space-y-1.5">
            <Label htmlFor="wiki-markdown" className="text-xs">Markdown</Label>
            <Textarea
              id="wiki-markdown"
              className="min-h-72 font-mono text-xs"
              value={form.content}
              onChange={(event) => setForm((prev) => ({ ...prev, content: event.target.value }))}
              disabled={saving}
              data-testid="wiki-markdown-input"
            />
          </div>

          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => setDialogOpen(false)} disabled={saving}>Cancel</Button>
            <Button type="submit" disabled={saving} data-testid="wiki-create-submit">
              {saving ? <Loader2 className="mr-1.5 h-3.5 w-3.5 animate-spin" /> : <BookPlus className="mr-1.5 h-3.5 w-3.5" />}
              Create page
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}

function WikiRefreshPanel({ projectId }: { projectId: string }) {
  const refreshState = useApi(() => api.getWikiRefreshRequests(projectId), [projectId]);
  const workspaceState = useApi(() => api.getProjectWorkspaceBindings(projectId), [projectId]);
  const projectState = useApi(() => api.getProject(projectId), [projectId]);
  const [workspaceBindingId, setWorkspaceBindingId] = useState(NO_BINDING);
  const [repositoryId, setRepositoryId] = useState(NO_REPOSITORY);
  const [scope, setScope] = useState<WikiRefreshScope>("project");
  const [reason, setReason] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const bindings = useMemo(() => workspaceState.data || [], [workspaceState.data]);
  const repositories = useMemo(() => projectState.data?.repositories || [], [projectState.data]);
  const latest = latestRefresh(refreshState.data);
  const appliedPages = latestAppliedPages(latest);
  const pagesWritten = latestPagesWritten(latest);

  useEffect(() => {
    if (workspaceBindingId === NO_BINDING && bindings.length > 0) {
      setWorkspaceBindingId(bindings[0].id);
    }
  }, [bindings, workspaceBindingId]);

  useEffect(() => {
    if (repositoryId === NO_REPOSITORY && repositories.length > 0) {
      setRepositoryId(repositories[0].id);
    }
  }, [repositories, repositoryId]);

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    if (workspaceBindingId === NO_BINDING) {
      toast.error("Select a workspace binding before requesting wiki refresh.");
      return;
    }
    if (scope === "repository" && repositoryId === NO_REPOSITORY) {
      toast.error("Select a repository before requesting repository wiki refresh.");
      return;
    }

    setSubmitting(true);
    try {
      const request = await api.createWikiRefreshRequest(projectId, {
        workspace_binding_id: workspaceBindingId,
        repository_id: scope === "repository" ? repositoryId : null,
        scope,
        reason: reason.trim() || "Manual project wiki refresh from Hades Agent.",
        sections: ["overview", "architecture", "runbook", "risks"],
        policy: "manual",
      });
      toast.success(`Wiki refresh queued: ${request.id}`);
      setReason("");
      refreshState.reload();
    } catch (err: any) {
      toast.error(err?.message || "Wiki refresh request failed.");
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <details className="rounded-md border border-border bg-card" data-testid="wiki-refresh-panel">
      <summary className="flex cursor-pointer list-inside flex-wrap items-center gap-2 px-4 py-3 text-sm font-medium">
        <span className="inline-flex items-center gap-1.5"><RefreshCw className="h-4 w-4 text-muted-foreground" />Wiki refresh</span>
        {latest ? <Pill tone={refreshTone(latest.status)}>{latest.status.replace(/_/g, " ")}</Pill> : <Pill tone="slate">No jobs</Pill>}
        <span className="text-xs font-normal text-muted-foreground">{latest?.reason || "Run a bounded Hades populate job when pages need refreshing."}</span>
      </summary>
      <div className="border-t border-border">
      <Panel dense>
        <div className="grid gap-3 p-3 xl:grid-cols-[minmax(0,1fr)_auto]">
        <div className="min-w-0 text-xs text-muted-foreground">
          {latest ? (
            <>
              <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                <span>Latest</span>
                <span className="font-mono text-foreground">{latest.id}</span>
                <span>{relativeTime(latest.created_at)}</span>
                {latest.reason && <span className="min-w-0 truncate">· {latest.reason}</span>}
              </div>
              {pagesWritten !== null && (
                <div className="mt-2 flex flex-wrap items-center gap-1.5">
                  <span className="text-foreground">Applied {pagesWritten} {pagesWritten === 1 ? "page" : "pages"}</span>
                  {appliedPages.map((page, index) => {
                    const label = page.title || page.slug || page.wiki_page_id || `Page ${index + 1}`;
                    const key = page.wiki_page_id || page.slug || label;
                    return page.wiki_page_id ? (
                      <Link
                        key={key}
                        to={`/projects/${encodeURIComponent(projectId)}/wiki/${page.wiki_page_id}`}
                        className="rounded border border-border bg-background px-1.5 py-0.5 font-mono text-[11px] text-foreground hover:border-primary/60"
                      >
                        {label}
                      </Link>
                    ) : (
                      <span key={key} className="rounded border border-border bg-background px-1.5 py-0.5 font-mono text-[11px] text-foreground">
                        {label}
                      </span>
                    );
                  })}
                </div>
              )}
            </>
          ) : (
            <span>Request a Hades populate job for the project wiki.</span>
          )}
          {(refreshState.error || workspaceState.error || projectState.error) && (
            <div className="mt-1 text-red-600 dark:text-red-400">{refreshState.error || workspaceState.error || projectState.error}</div>
          )}
        </div>

        <form onSubmit={submit} className="flex flex-wrap items-center gap-2">
          <Select value={workspaceBindingId} onValueChange={setWorkspaceBindingId} disabled={submitting || workspaceState.loading || bindings.length === 0}>
            <SelectTrigger className="h-8 w-52 text-xs" data-testid="wiki-refresh-workspace">
              <SelectValue placeholder="Workspace" />
            </SelectTrigger>
            <SelectContent>
              {bindings.length === 0 && <SelectItem value={NO_BINDING}>No workspace</SelectItem>}
              {bindings.map((binding) => (
                <SelectItem key={binding.id} value={binding.id}>{binding.display_path}</SelectItem>
              ))}
            </SelectContent>
          </Select>
          <Select value={scope} onValueChange={(value) => setScope(value as WikiRefreshScope)} disabled={submitting}>
            <SelectTrigger className="h-8 w-32 text-xs" data-testid="wiki-refresh-scope"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem value="project">Project</SelectItem>
              <SelectItem value="repository">Repository</SelectItem>
            </SelectContent>
          </Select>
          {scope === "repository" && (
            <Select value={repositoryId} onValueChange={setRepositoryId} disabled={submitting || projectState.loading || repositories.length === 0}>
              <SelectTrigger className="h-8 w-52 text-xs" data-testid="wiki-refresh-repository">
                <SelectValue placeholder="Repository" />
              </SelectTrigger>
              <SelectContent>
                {repositories.length === 0 && <SelectItem value={NO_REPOSITORY}>No repository</SelectItem>}
                {repositories.map((repository) => (
                  <SelectItem key={repository.id} value={repository.id}>{repository.name}</SelectItem>
                ))}
              </SelectContent>
            </Select>
          )}
          <Input
            className="h-8 w-64 text-xs"
            value={reason}
            onChange={(event) => setReason(event.target.value)}
            placeholder="Reason"
            maxLength={180}
            disabled={submitting}
            data-testid="wiki-refresh-reason"
          />
          <Button size="sm" type="submit" disabled={submitting || workspaceBindingId === NO_BINDING} data-testid="wiki-refresh-submit">
            {submitting ? <Loader2 className="mr-1.5 h-3.5 w-3.5 animate-spin" /> : <RefreshCw className="mr-1.5 h-3.5 w-3.5" />}
            Request
          </Button>
        </form>
        </div>
      </Panel>
      </div>
    </details>
  );
}

export default function WikiPage() {
  const { user } = useAuth();
  const nav = useNavigate();
  const { projectId } = useParams();
  const state = useApi(() => api.getWiki(projectId), [projectId]);
  const projectState = useApi(() => projectId ? api.getProject(projectId) : Promise.resolve(null), [projectId]);
  const [query, setQuery] = useState("");
  const [audience, setAudience] = useState(ALL);
  const [pageType, setPageType] = useState(ALL);
  const [sourceType, setSourceType] = useState(ALL);
  const [verificationStatus, setVerificationStatus] = useState(ALL);
  const canCreate = Boolean(projectId && projectState.data?.status === "active" && canMutateRole(user?.role));

  return (
    <div className="space-y-4" data-testid="wiki-page">
      <PageHeader
        title="Wiki"
        subtitle={projectId ? "Project-specific evidence pages. Other projects are excluded server-side." : "Evidence-backed pages. Verification status is assigned by the backend workflow."}
        meta={projectId && <Link to={`/projects/${encodeURIComponent(projectId)}`} title={`Project ${projectId}`} className="inline-flex min-w-0 max-w-full items-center gap-1 text-xs text-muted-foreground hover:text-foreground"><Boxes className="h-3.5 w-3.5 shrink-0" /><span className="truncate">Project {projectId}</span></Link>}
        actions={canCreate && projectId ? <CreateWikiPageDialog projectId={projectId} onCreated={(page) => { state.reload(); nav(`/projects/${encodeURIComponent(projectId)}/wiki/${page.id}`); }} /> : undefined}
      />

      {projectId && <WikiRefreshPanel projectId={projectId} />}

      <DataState state={state} isEmpty={(d) => d.length === 0}>
        {(pages) => {
          const audiences = Array.from(new Set(pages.map((p) => p.audience || "mixed"))).sort();
          const pageTypes = Array.from(new Set(pages.map((p) => p.page_type || p.category))).sort();
          const sources = Array.from(new Set(pages.map((p) => p.source_type || p.source.type))).sort();
          const normalizedQuery = query.trim().toLocaleLowerCase();
          const filtered = pages.filter((p) => {
            const type = p.page_type || p.category;
            const facetAudience = p.audience || "mixed";
            const facetSource = p.source_type || p.source.type;
            const searchable = [p.title, p.category, type, facetAudience, facetSource, p.source_status, p.source.origin].join(" ").toLocaleLowerCase();
            return (!normalizedQuery || searchable.includes(normalizedQuery))
              && (audience === ALL || facetAudience === audience)
              && (pageType === ALL || type === pageType)
              && (sourceType === ALL || facetSource === sourceType)
              && (verificationStatus === ALL || p.source_status === verificationStatus);
          });
          const cats = Array.from(new Set(filtered.map((p) => p.page_type || p.category)));
          const verificationQueueCount = pages.filter((p) => p.source_status === "needs_verification").length;
          return (
            <>
              <div className="flex flex-wrap items-center gap-2" aria-label="Wiki filters">
                <div className="relative min-w-0 flex-1 sm:max-w-xs">
                  <Search className="pointer-events-none absolute left-2 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-muted-foreground" />
                  <Input value={query} onChange={(event) => setQuery(event.target.value)} placeholder="Search wiki pages" className="h-8 pl-7 text-xs" data-testid="wiki-search-input" />
                </div>
                <Select value={audience} onValueChange={setAudience}>
                  <SelectTrigger className="h-8 w-40 text-xs" data-testid="filter-audience"><span className="text-muted-foreground">Audience:</span><SelectValue /></SelectTrigger>
                  <SelectContent><SelectItem value={ALL}>All</SelectItem>{audiences.map((value) => <SelectItem key={value} value={value}>{value}</SelectItem>)}</SelectContent>
                </Select>
                <Select value={pageType} onValueChange={setPageType}>
                  <SelectTrigger className="h-8 w-40 text-xs" data-testid="filter-page-type"><span className="text-muted-foreground">Page type:</span><SelectValue /></SelectTrigger>
                  <SelectContent><SelectItem value={ALL}>All</SelectItem>{pageTypes.map((value) => <SelectItem key={value} value={value}>{value}</SelectItem>)}</SelectContent>
                </Select>
                <Select value={verificationStatus} onValueChange={setVerificationStatus}>
                  <SelectTrigger className="h-8 w-48 text-xs" data-testid="filter-source"><span className="text-muted-foreground">Verification:</span><SelectValue /></SelectTrigger>
                  <SelectContent><SelectItem value={ALL}>All</SelectItem>{["verified_from_code", "developer_provided", "ai_generated", "needs_verification", "stale", "conflict_with_code"].map((value) => <SelectItem key={value} value={value}>{value.replace(/_/g, " ")}</SelectItem>)}</SelectContent>
                </Select>
                <Select value={sourceType} onValueChange={setSourceType}>
                  <SelectTrigger className="h-8 w-40 text-xs" data-testid="filter-source-type"><span className="text-muted-foreground">Source:</span><SelectValue /></SelectTrigger>
                  <SelectContent><SelectItem value={ALL}>All</SelectItem>{sources.map((value) => <SelectItem key={value} value={value}>{value.replace(/_/g, " ")}</SelectItem>)}</SelectContent>
                </Select>
                <span className="text-xs text-muted-foreground" data-testid="wiki-verification-queue">Verification queue: {verificationQueueCount}</span>
                <span className="text-xs text-muted-foreground">{filtered.length} pages</span>
              </div>
              {filtered.length === 0 && <div className="rounded-md border border-dashed border-border px-4 py-8 text-center text-sm text-muted-foreground">No pages match these filters.</div>}

              {cats.map((cat) => (
                <div key={cat}>
                  <h3 className="mb-2 text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">{cat}</h3>
                  <div className="grid grid-cols-1 gap-2 md:grid-cols-2 xl:grid-cols-3">
                    {filtered.filter((p) => (p.page_type || p.category) === cat).map((p) => {
                      const type = p.page_type || p.category;
                      const facetAudience = p.audience || "mixed";
                      const facetSource = p.source_type || p.source.type;
                      return (
                      <button key={p.id} onClick={() => nav(projectId ? `/projects/${encodeURIComponent(projectId)}/wiki/${p.id}` : `/wiki/${p.id}`)} data-testid={`wiki-card-${p.id}`}
                        className="flex min-w-0 flex-col rounded-md border border-border bg-card p-3.5 text-left transition-colors hover:border-primary/50">
                        <div className="flex min-w-0 items-start justify-between gap-2">
                          <span className="flex min-w-0 items-center gap-2 text-sm font-semibold"><BookText className="h-4 w-4 shrink-0 text-muted-foreground" /><span className="truncate" title={p.title}>{p.title}</span></span>
                          {p.has_evidence ? <CheckCircle2 aria-label="Has evidence" className="h-4 w-4 shrink-0 text-emerald-500" /> : <FileWarning aria-label="Needs evidence" className="h-4 w-4 shrink-0 text-amber-500" />}
                        </div>
                        <div className="mt-2 flex flex-wrap items-center gap-1.5"><SourceStatusBadge status={p.source_status} /><Pill tone="slate">{facetAudience}</Pill>{!p.has_evidence && <Pill tone="amber">No evidence</Pill>}</div>
                        <div className="mt-2.5 flex flex-wrap items-center gap-x-2 gap-y-1 border-t border-border/60 pt-2 text-[11px] text-muted-foreground"><span>{type}</span><span>·</span><span>{facetSource.replace(/_/g, " ")}</span></div>
                        <p className="mt-1 text-[11px] text-muted-foreground">Updated {relativeTime(p.updated_at)}</p>
                      </button>
                      );
                    })}
                  </div>
                </div>
              ))}
            </>
          );
        }}
      </DataState>
    </div>
  );
}
