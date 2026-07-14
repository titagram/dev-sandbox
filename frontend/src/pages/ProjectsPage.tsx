import React, { useState } from "react";
import { useNavigate } from "react-router-dom";
import { api } from "@/api/devboardApi";
import { useApi } from "@/hooks/useApi";
import { useAuth } from "@/context/AuthContext";
import { PageHeader, MetricCard } from "@/components/devboard/Layout";
import { DataState } from "@/components/devboard/DataState";
import { RiskBadge, PipelineBadge, Pill } from "@/components/devboard/Badges";
import { Button } from "@/components/ui/button";
import {
  Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle,
} from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { relativeTime } from "@/lib/format";
import { Project, ProjectInput, ProjectLifecycleInput, ProjectStatusFilter } from "@/types/devboard";
import { toast } from "sonner";
import { Archive, Boxes, Search, ArrowUpRight, FolderGit2, Pencil, Plus, RotateCcw, Trash2 } from "lucide-react";

const emptyForm: ProjectInput = { name: "", key: "", description: "" };
const statusOptions: ProjectStatusFilter[] = ["active", "archived", "deleted"];
type LifecycleAction = "archive" | "restore" | "delete";

const keyFromName = (value: string) =>
  value
    .trim()
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-+|-+$/g, "");

export default function ProjectsPage() {
  const nav = useNavigate();
  const { user } = useAuth();
  const [statusFilter, setStatusFilter] = useState<ProjectStatusFilter>("active");
  const state = useApi(() => api.getProjects(statusFilter), [statusFilter]);
  const [q, setQ] = useState("");
  const [form, setForm] = useState<ProjectInput>(emptyForm);
  const [editing, setEditing] = useState<Project | null>(null);
  const [open, setOpen] = useState(false);
  const [busy, setBusy] = useState(false);
  const [lifecycleProject, setLifecycleProject] = useState<Project | null>(null);
  const [lifecycleAction, setLifecycleAction] = useState<LifecycleAction | null>(null);
  const [reason, setReason] = useState("");
  const [deleteConfirm, setDeleteConfirm] = useState("");
  const canEditProjects = user?.role === "admin";
  const canManageLifecycle = user?.role === "admin" || user?.role === "pm";

  const beginCreate = () => {
    setEditing(null);
    setForm(emptyForm);
    setOpen(true);
  };
  const beginEdit = (project: Project) => {
    setEditing(project);
    setForm({ name: project.name, key: project.key, description: project.description });
    setOpen(true);
  };

  const setName = (name: string) => {
    setForm((prev) => ({
      ...prev,
      name,
      key: editing || prev.key ? prev.key : keyFromName(name),
    }));
  };

  const submitProject = async (event: React.FormEvent) => {
    event.preventDefault();
    if (!form.name.trim()) {
      toast.error("Project name is required.");
      return;
    }

    const payload = {
      name: form.name.trim(),
      key: form.key.trim() || keyFromName(form.name),
      description: form.description.trim(),
    };

    setBusy(true);
    try {
      const project = editing
        ? await api.updateProject(editing.id, payload)
        : await api.createProject(payload);
      toast.success(editing ? "Project updated" : "Project created");
      setOpen(false);
      state.reload();
      if (!editing) nav(`/projects/${project.id}`);
    } catch (err: any) {
      toast.error(err?.message || "Project save failed.");
    } finally {
      setBusy(false);
    }
  };

  const beginLifecycle = (project: Project, action: LifecycleAction) => {
    setLifecycleProject(project);
    setLifecycleAction(action);
    setReason("");
    setDeleteConfirm("");
  };

  const closeLifecycle = () => {
    if (busy) return;
    setLifecycleProject(null);
    setLifecycleAction(null);
    setReason("");
    setDeleteConfirm("");
  };

  const submitLifecycle = async (event: React.FormEvent) => {
    event.preventDefault();
    if (!lifecycleProject || !lifecycleAction) return;

    const confirmation = deleteConfirm.trim();
    if (lifecycleAction === "delete" && confirmation !== lifecycleProject.key && confirmation !== lifecycleProject.name) {
      toast.error("Confirmation does not match.");
      return;
    }

    const payload: ProjectLifecycleInput = reason.trim() ? { reason: reason.trim() } : {};
    setBusy(true);
    try {
      if (lifecycleAction === "archive") await api.archiveProject(lifecycleProject.id, payload);
      if (lifecycleAction === "restore") await api.restoreProject(lifecycleProject.id, payload);
      if (lifecycleAction === "delete") await api.deleteProject(lifecycleProject.id, payload);
      toast.success(
        lifecycleAction === "delete"
          ? "Project moved to trash"
          : lifecycleAction === "restore"
            ? "Project restored"
            : "Project archived",
      );
      setLifecycleProject(null);
      setLifecycleAction(null);
      setReason("");
      setDeleteConfirm("");
      state.reload();
    } catch (err: any) {
      toast.error(err?.message || "Project lifecycle update failed.");
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="space-y-5" data-testid="projects-page">
      <PageHeader
        title="Projects"
        subtitle="Each project contains local-only Git repositories, runs, artifacts, and wiki evidence."
        actions={
          <>
            <div className="flex h-9 items-center rounded-md border border-border bg-card p-0.5" data-testid="project-status-filter">
              {statusOptions
                .filter((status) => status !== "deleted" || canManageLifecycle)
                .map((status) => (
                  <button
                    key={status}
                    type="button"
                    onClick={() => setStatusFilter(status)}
                    aria-pressed={statusFilter === status}
                    className={`h-7 rounded px-2 text-xs capitalize transition-colors ${statusFilter === status ? "bg-primary text-primary-foreground" : "text-muted-foreground hover:bg-accent hover:text-foreground"}`}
                    data-testid={`project-status-${status}`}
                  >
                    {status === "deleted" ? "Trash" : status}
                  </button>
                ))}
            </div>
            <div className="relative">
              <Search className="absolute left-2.5 top-2.5 h-3.5 w-3.5 text-muted-foreground" />
              <Input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Filter projects…" className="h-9 w-56 pl-8" data-testid="projects-search" />
            </div>
            {canEditProjects && (
              <Button size="sm" onClick={beginCreate} data-testid="create-project-btn">
                <Plus className="mr-1.5 h-3.5 w-3.5" /> Create project
              </Button>
            )}
          </>
        }
      />

      <Dialog open={open} onOpenChange={(next) => { if (!busy) setOpen(next); }}>
        <DialogContent data-testid="project-form-dialog">
          <form onSubmit={submitProject} className="space-y-4">
            <DialogHeader>
              <DialogTitle>{editing ? "Edit project" : "Create project"}</DialogTitle>
            </DialogHeader>

            <div className="space-y-3">
              <div className="space-y-1.5">
                <Label htmlFor="project-name" className="text-xs">Name</Label>
                <Input id="project-name" value={form.name} onChange={(e) => setName(e.target.value)} data-testid="project-name-input" autoFocus />
              </div>
              <div className="space-y-1.5">
                <Label htmlFor="project-key" className="text-xs">Key</Label>
                <Input id="project-key" value={form.key} onChange={(e) => setForm({ ...form, key: keyFromName(e.target.value) })} placeholder="client-portal" data-testid="project-key-input" />
              </div>
              <div className="space-y-1.5">
                <Label htmlFor="project-description" className="text-xs">Description</Label>
                <Textarea id="project-description" value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} className="min-h-24" data-testid="project-description-input" />
              </div>
            </div>

            <DialogFooter>
              <Button type="button" variant="outline" onClick={() => setOpen(false)} disabled={busy} data-testid="project-cancel-btn">Cancel</Button>
              <Button type="submit" disabled={busy} data-testid="project-save-btn">{busy ? "Saving..." : "Save project"}</Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>

      <Dialog open={!!lifecycleAction} onOpenChange={(next) => { if (!next) closeLifecycle(); }}>
        <DialogContent data-testid="project-lifecycle-dialog">
          <form onSubmit={submitLifecycle} className="space-y-4">
            <DialogHeader>
              <DialogTitle>
                {lifecycleAction === "archive" && "Archive project"}
                {lifecycleAction === "restore" && "Restore project"}
                {lifecycleAction === "delete" && "Move project to trash"}
              </DialogTitle>
            </DialogHeader>

            {lifecycleAction === "delete" && lifecycleProject && (
              <div className="space-y-1.5">
                <Label htmlFor="project-delete-confirm" className="text-xs">Confirm</Label>
                <Input
                  id="project-delete-confirm"
                  value={deleteConfirm}
                  onChange={(e) => setDeleteConfirm(e.target.value)}
                  placeholder={lifecycleProject.key}
                  data-testid="project-delete-confirm-input"
                />
              </div>
            )}

            <div className="space-y-1.5">
              <Label htmlFor="project-lifecycle-reason" className="text-xs">Reason</Label>
              <Textarea
                id="project-lifecycle-reason"
                value={reason}
                onChange={(e) => setReason(e.target.value)}
                className="min-h-20"
                data-testid="project-lifecycle-reason-input"
              />
            </div>

            <DialogFooter>
              <Button type="button" variant="outline" onClick={closeLifecycle} disabled={busy}>Cancel</Button>
              <Button type="submit" disabled={busy} data-testid="project-lifecycle-submit">
                {busy ? "Saving..." : "Apply"}
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>

      <DataState state={state} isEmpty={(d) => d.length === 0} empty={<div className="py-12 text-center text-sm text-muted-foreground">No projects.</div>}>
        {(projects) => {
          const filtered = projects.filter((p) => `${p.name} ${p.key} ${p.owner}`.toLowerCase().includes(q.toLowerCase()));
          const totalRepos = projects.reduce((a, p) => a + p.repository_count, 0);
          const totalOpen = projects.reduce((a, p) => a + p.open_tasks, 0);
          const atRisk = projects.filter((p) => p.risk_level === "high" || p.risk_level === "critical").length;
          return (
            <>
              <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <MetricCard label="Projects" value={projects.length} testId="metric-projects" />
                <MetricCard label="Repositories" value={totalRepos} sub="local-only Git sources" />
                <MetricCard label="Open tasks" value={totalOpen} />
                <MetricCard label="High/critical risk" value={atRisk} tone={atRisk ? "warn" : "good"} />
              </div>

              <div className="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
                {filtered.map((p) => {
                  const status = p.status;
                  return (
                    <div
                      key={p.id}
                      data-testid={`project-card-${p.id}`}
                      className="group relative rounded-md border border-border bg-card text-left transition-colors hover:border-primary/50 hover:bg-accent/40"
                    >
                      {(canEditProjects || canManageLifecycle) && (
                        <div className="absolute right-3 top-3 z-10 flex items-center gap-1">
                          {canEditProjects && status === "active" && (
                            <button
                              type="button"
                              onClick={() => beginEdit(p)}
                              data-testid={`edit-project-${p.id}`}
                              className="grid h-7 w-7 place-items-center rounded-md text-muted-foreground hover:bg-accent hover:text-foreground"
                              title="Edit project"
                            >
                              <Pencil className="h-3.5 w-3.5" />
                            </button>
                          )}
                          {canManageLifecycle && status === "active" && (
                            <button
                              type="button"
                              onClick={() => beginLifecycle(p, "archive")}
                              data-testid={`archive-project-${p.id}`}
                              className="grid h-7 w-7 place-items-center rounded-md text-muted-foreground hover:bg-accent hover:text-foreground"
                              title="Archive project"
                            >
                              <Archive className="h-3.5 w-3.5" />
                            </button>
                          )}
                          {canManageLifecycle && status !== "deleted" && (
                            <button
                              type="button"
                              onClick={() => beginLifecycle(p, "delete")}
                              data-testid={`delete-project-${p.id}`}
                              className="grid h-7 w-7 place-items-center rounded-md text-muted-foreground hover:bg-accent hover:text-foreground"
                              title="Move project to trash"
                            >
                              <Trash2 className="h-3.5 w-3.5" />
                            </button>
                          )}
                          {canManageLifecycle && status !== "active" && (
                            <button
                              type="button"
                              onClick={() => beginLifecycle(p, "restore")}
                              data-testid={`restore-project-${p.id}`}
                              className="grid h-7 w-7 place-items-center rounded-md text-muted-foreground hover:bg-accent hover:text-foreground"
                              title="Restore project"
                            >
                              <RotateCcw className="h-3.5 w-3.5" />
                            </button>
                          )}
                        </div>
                      )}
                      <button
                        type="button"
                        onClick={() => nav(`/projects/${p.id}`)}
                        className="flex w-full flex-col p-4 text-left"
                      >
                        <div className="flex items-start justify-between gap-2">
                          <div className="flex items-center gap-2">
                            <span className="grid h-8 w-8 place-items-center rounded-md bg-primary/10 text-primary"><Boxes className="h-4 w-4" /></span>
                            <div>
                              <p className="font-mono text-[11px] text-muted-foreground">{p.key}</p>
                              <p className="text-sm font-semibold leading-tight">{p.name}</p>
                            </div>
                          </div>
                          <ArrowUpRight className="mr-24 h-4 w-4 text-muted-foreground opacity-0 transition-opacity group-hover:opacity-100" />
                        </div>
                        <p className="mt-3 line-clamp-2 text-xs text-muted-foreground">{p.description}</p>
                        <div className="mt-3 flex flex-wrap items-center gap-1.5">
                          <RiskBadge level={p.risk_level} />
                          <PipelineBadge status={p.genesis_status} label="Genesis" />
                          <PipelineBadge status={p.delta_status} label="Delta" />
                          <PipelineBadge status={p.wiki_freshness} label="Wiki" />
                        </div>
                        <div className="mt-3 flex items-center justify-between border-t border-border pt-2.5 text-[11px] text-muted-foreground">
                          <span className="flex items-center gap-1"><FolderGit2 className="h-3 w-3" /> {p.repository_count} repos</span>
                          <Pill tone="slate">{p.open_tasks} open</Pill>
                          <span>{relativeTime(p.updated_at)}</span>
                        </div>
                      </button>
                    </div>
                  );
                })}
              </div>
            </>
          );
        }}
      </DataState>
    </div>
  );
}
