import React, { useEffect, useState } from "react";
import { useParams, useNavigate, Link } from "react-router-dom";
import { toast } from "sonner";
import { api } from "@/api/devboardApi";
import { useApi } from "@/hooks/useApi";
import { useAuth } from "@/context/AuthContext";
import { PageHeader, Panel } from "@/components/devboard/Layout";
import { DataState } from "@/components/devboard/DataState";
import { SourceStatusBadge, SourceMetaInline, Pill } from "@/components/devboard/Badges";
import { Button } from "@/components/ui/button";
import {
  Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle,
} from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Textarea } from "@/components/ui/textarea";
import { relativeTime, titleCase } from "@/lib/format";
import { ChevronLeft, Code2, Edit3, Loader2, PlayCircle, Package, PenLine, Network } from "lucide-react";
import { Role, SourceStatus, WikiPageDetail, WikiPageWriteInput } from "@/types/devboard";

const EV_ICON = { code_ref: Code2, run_ref: PlayCircle, artifact_ref: Package, manual_note: PenLine } as const;
const PAGE_TYPES: WikiPageWriteInput["page_type"][] = ["business", "technical", "runbook", "audit"];
const EDITABLE_SOURCE_STATUSES: SourceStatus[] = ["developer_provided", "needs_verification", "ai_generated", "stale", "conflict_with_code"];

function canMutateRole(role: Role | null | undefined): boolean {
  return role === "admin" || role === "pm" || role === "developer";
}

function normalizePageType(value: string): WikiPageWriteInput["page_type"] {
  return PAGE_TYPES.includes(value as WikiPageWriteInput["page_type"]) ? value as WikiPageWriteInput["page_type"] : "technical";
}

function Markdown({ src }: { src: string }) {
  const lines = src.split("\n");
  return (
    <div className="space-y-2 text-sm leading-relaxed">
      {lines.map((ln, i) => {
        if (!ln.trim()) return <div key={i} className="h-1" />;
        if (ln.startsWith("## ")) return <h3 key={i} className="text-base font-semibold">{ln.slice(3)}</h3>;
        if (ln.startsWith("> ")) return <blockquote key={i} className="border-l-2 border-amber-500/50 bg-amber-500/5 py-1.5 pl-3 text-amber-700 dark:text-amber-300">{ln.slice(2)}</blockquote>;
        if (ln.startsWith("- ")) return <p key={i} className="flex gap-2 pl-1 text-muted-foreground"><span className="text-primary">•</span><span dangerouslySetInnerHTML={{ __html: ln.slice(2).replace(/\*\*(.+?)\*\*/g, "<strong>$1</strong>") }} /></p>;
        return <p key={i} className="text-muted-foreground" dangerouslySetInnerHTML={{ __html: ln.replace(/\*\*(.+?)\*\*/g, "<strong class='text-foreground'>$1</strong>") }} />;
      })}
    </div>
  );
}

function EditWikiPageDialog({
  page,
  projectId,
  onSaved,
}: {
  page: WikiPageDetail;
  projectId?: string;
  onSaved: () => void;
}) {
  const [open, setOpen] = useState(false);
  const [title, setTitle] = useState(page.title);
  const [pageType, setPageType] = useState<WikiPageWriteInput["page_type"]>(normalizePageType(page.category));
  const [sourceStatus, setSourceStatus] = useState<SourceStatus>(page.source_status === "verified_from_code" ? "developer_provided" : page.source_status);
  const [content, setContent] = useState(page.body_markdown);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    if (!open) return;
    setTitle(page.title);
    setPageType(normalizePageType(page.category));
    setSourceStatus(page.source_status === "verified_from_code" ? "developer_provided" : page.source_status);
    setContent(page.body_markdown);
  }, [open, page]);

  const setDialogOpen = (nextOpen: boolean) => {
    if (saving) return;
    setOpen(nextOpen);
  };

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    const nextTitle = title.trim();
    const nextContent = content.trim();

    if (nextTitle.length < 3) {
      toast.error("Title is required.");
      return;
    }
    if (!nextContent) {
      toast.error("Markdown content is required.");
      return;
    }

    setSaving(true);
    try {
      await api.updateWikiPage(page.id, {
        title: nextTitle,
        page_type: pageType,
        source_status: sourceStatus,
        content_markdown: nextContent,
      }, projectId);
      toast.success("Wiki page updated");
      setDialogOpen(false);
      onSaved();
    } catch (err: any) {
      toast.error(err?.message || "Wiki page update failed.");
    } finally {
      setSaving(false);
    }
  };

  return (
    <Dialog open={open} onOpenChange={setDialogOpen}>
      <Button size="sm" type="button" onClick={() => setDialogOpen(true)} data-testid="edit-wiki-page-btn">
        <Edit3 className="mr-1.5 h-3.5 w-3.5" />
        Edit
      </Button>
      <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-3xl" data-testid="edit-wiki-page-dialog">
        <form onSubmit={submit} className="space-y-4">
          <DialogHeader>
            <DialogTitle>Edit wiki page</DialogTitle>
            <DialogDescription>Update the title, status, and Markdown body.</DialogDescription>
          </DialogHeader>

          <div className="grid gap-3 md:grid-cols-2">
            <div className="space-y-1.5">
              <Label htmlFor="wiki-edit-title" className="text-xs">Title</Label>
              <Input id="wiki-edit-title" value={title} onChange={(event) => setTitle(event.target.value)} disabled={saving} data-testid="wiki-edit-title" autoFocus />
            </div>
            <div className="space-y-1.5">
              <Label className="text-xs">Page type</Label>
              <Select value={pageType} onValueChange={(value) => setPageType(value as WikiPageWriteInput["page_type"])} disabled={saving}>
                <SelectTrigger data-testid="wiki-edit-page-type"><SelectValue /></SelectTrigger>
                <SelectContent>{PAGE_TYPES.map((value) => <SelectItem key={value} value={value}>{value}</SelectItem>)}</SelectContent>
              </Select>
            </div>
          </div>

          <div className="space-y-1.5">
            <Label className="text-xs">Source status</Label>
            <Select value={sourceStatus} onValueChange={(value) => setSourceStatus(value as SourceStatus)} disabled={saving}>
              <SelectTrigger data-testid="wiki-edit-source-status"><SelectValue /></SelectTrigger>
              <SelectContent>
                {EDITABLE_SOURCE_STATUSES.map((value) => <SelectItem key={value} value={value}>{value.replace(/_/g, " ")}</SelectItem>)}
              </SelectContent>
            </Select>
          </div>

          <div className="space-y-1.5">
            <Label htmlFor="wiki-edit-markdown" className="text-xs">Markdown</Label>
            <Textarea
              id="wiki-edit-markdown"
              className="min-h-80 font-mono text-xs"
              value={content}
              onChange={(event) => setContent(event.target.value)}
              disabled={saving}
              data-testid="wiki-edit-markdown"
            />
          </div>

          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => setDialogOpen(false)} disabled={saving}>Cancel</Button>
            <Button type="submit" disabled={saving} data-testid="wiki-edit-submit">
              {saving ? <Loader2 className="mr-1.5 h-3.5 w-3.5 animate-spin" /> : <Edit3 className="mr-1.5 h-3.5 w-3.5" />}
              Save
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}

export default function WikiPageDetailPage() {
  const { user } = useAuth();
  const { projectId, pageId } = useParams();
  const nav = useNavigate();
  const state = useApi(() => api.getWikiPage(pageId!, projectId), [pageId, projectId]);
  const detailProjectId = state.data?.project_id;
  const projectState = useApi(() => detailProjectId ? api.getProject(detailProjectId) : Promise.resolve(null), [detailProjectId]);
  const canEdit = Boolean(projectState.data?.status === "active" && canMutateRole(user?.role));
  const wikiPath = projectId ? `/projects/${encodeURIComponent(projectId)}/wiki` : "/wiki";
  const graphPath = projectId ? `/projects/${encodeURIComponent(projectId)}/graph` : "/graph";

  return (
    <div className="space-y-5" data-testid="wiki-detail-page">
      <button onClick={() => nav(wikiPath)} className="inline-flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground">
        <ChevronLeft className="h-3.5 w-3.5" /> Wiki
      </button>

      <DataState state={state}>
        {(p) => (
          <>
            <PageHeader
              title={p.title}
              meta={<div className="flex flex-wrap items-center gap-1.5"><Pill tone="slate">{p.category}</Pill><SourceStatusBadge status={p.source_status} /><span className="text-xs text-muted-foreground">Updated {relativeTime(p.updated_at)}</span></div>}
              actions={canEdit ? <EditWikiPageDialog page={p} projectId={projectId} onSaved={state.reload} /> : undefined}
            />

            {p.source_status === "conflict_with_code" && (
              <div className="rounded-md border border-red-500/30 bg-red-500/5 px-3 py-2.5 text-sm text-red-600 dark:text-red-400" data-testid="conflict-banner">
                This page conflicts with the latest imported code graph and needs reconciliation.
              </div>
            )}

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
              <Panel title="Content" className="lg:col-span-2"><Markdown src={p.body_markdown} /></Panel>

              <div className="space-y-4">
                <Panel title="Source"><SourceMetaInline source={p.source} /></Panel>
                <Panel title="Evidence" dense>
                  {p.evidence.length === 0
                    ? <div className="p-4 text-sm text-muted-foreground">No evidence attached. Status is <SourceStatusBadge status={p.source_status} />.</div>
                    : <ul className="divide-y divide-border/60">{p.evidence.map((e) => {
                        const I = EV_ICON[e.kind];
                        return <li key={e.id} className="px-4 py-2.5"><div className="flex items-center gap-2 text-sm font-medium"><I className="h-3.5 w-3.5 text-muted-foreground" />{e.label}</div><p className="mt-0.5 font-mono text-[11px] text-muted-foreground">{e.ref}</p><div className="mt-1.5"><SourceMetaInline source={e.source} /></div></li>;
                      })}</ul>}
                </Panel>
                {p.related_run_ids.length > 0 && (
                  <Panel title="Related runs" dense>
                    <ul className="divide-y divide-border/60">{p.related_run_ids.map((r) => (
                      <li key={r}><Link to={projectId ? `/projects/${encodeURIComponent(projectId)}/runs/${r}` : `/runs/${r}`} className="flex items-center gap-2 px-4 py-2.5 text-sm font-mono hover:bg-accent/50"><PlayCircle className="h-3.5 w-3.5 text-muted-foreground" />{r}</Link></li>
                    ))}</ul>
                  </Panel>
                )}
                <Link to={graphPath} className="flex items-center justify-center gap-1.5 rounded-md border border-border py-2 text-xs text-muted-foreground hover:bg-accent"><Network className="h-3.5 w-3.5" /> Open graph view</Link>
              </div>
            </div>
          </>
        )}
      </DataState>
    </div>
  );
}
