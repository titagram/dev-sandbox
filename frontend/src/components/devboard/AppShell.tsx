import React, { useEffect, useState } from "react";
import { NavLink, Outlet, useLocation, useNavigate } from "react-router-dom";
import { Boxes, Check, ChevronDown, ChevronRight, Command as CommandIcon, Lock, LogOut, Menu, Moon, Sun, Terminal } from "lucide-react";
import { CommandPalette, useCommandPalette } from "@/components/devboard/CommandPalette";

import { API_BASE_URL, USE_MOCK, api } from "@/api/devboardApi";
import { useAuth } from "@/context/AuthContext";
import { useTheme } from "@/context/ThemeContext";
import { useApi } from "@/hooks/useApi";
import { navForRole, navPathForItem, ROLE_LABEL } from "@/lib/nav";
import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { Sheet, SheetContent, SheetTrigger } from "@/components/ui/sheet";
import {
  DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel,
  DropdownMenuSeparator, DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { Project } from "@/types/devboard";

const PROJECT_SCOPE_STORAGE_KEY = "devboard.selectedProjectScope.v1";
const PROJECT_SCOPED_SECTIONS = new Set(["kanban", "ask", "agent-chat", "agent-work", "memory", "wiki", "engineering", "runs", "graph", "artifacts"]);

function activeProjectFromPath(pathname: string): string | undefined {
  const raw = pathname.match(/^\/projects\/([^/]+)/)?.[1];
  if (!raw) return undefined;

  try {
    return decodeURIComponent(raw);
  } catch {
    return raw;
  }
}

function readStoredProjectScope(): string | undefined {
  if (typeof window === "undefined") return undefined;
  const value = window.localStorage.getItem(PROJECT_SCOPE_STORAGE_KEY);
  return value || undefined;
}

function writeStoredProjectScope(projectId?: string): void {
  if (typeof window === "undefined") return;
  if (projectId) {
    window.localStorage.setItem(PROJECT_SCOPE_STORAGE_KEY, projectId);
  } else {
    window.localStorage.removeItem(PROJECT_SCOPE_STORAGE_KEY);
  }
}

function displayProjectId(projectId: string): string {
  try {
    return decodeURIComponent(projectId);
  } catch {
    return projectId;
  }
}

function projectScopePath(pathname: string, projectId?: string): string {
  if (!projectId) return "/projects";

  const encodedProjectId = encodeURIComponent(projectId);
  const projectMatch = pathname.match(/^\/projects\/[^/]+(\/.*)?$/);
  if (projectMatch) return `/projects/${encodedProjectId}${projectMatch[1] ?? ""}`;

  const globalMatch = pathname.match(/^\/([^/]+)(\/.*)?$/);
  const section = globalMatch?.[1];
  if (section && PROJECT_SCOPED_SECTIONS.has(section)) {
    return `/projects/${encodedProjectId}/${section}${globalMatch?.[2] ?? ""}`;
  }

  return `/projects/${encodedProjectId}`;
}

function projectLabel(project: Project | undefined, activeProjectId: string | undefined): string {
  if (project?.name) return project.name;
  return activeProjectId ? displayProjectId(activeProjectId) : "Global workspace";
}

function NavList({ selectedProjectId, onNavigate }: { selectedProjectId?: string; onNavigate?: () => void }) {
  const { user } = useAuth();
  const loc = useLocation();
  if (!user) return null;
  const items = navForRole(user.role);

  return (
    <nav className="flex flex-col gap-0.5 px-2" data-testid="primary-nav">
      {items.map((it) => {
        const Icon = it.icon;
        const readOnly = !it.roles.includes(user.role) && it.readOnlyRoles?.includes(user.role);
        const path = navPathForItem(it, user.role, selectedProjectId);
        const encodedProjectId = selectedProjectId ? encodeURIComponent(selectedProjectId) : undefined;
        const legacyWorkPath = encodedProjectId ? `/projects/${encodedProjectId}/kanban` : "/kanban";
        return (
          <NavLink
            key={it.key}
            to={path}
            onClick={onNavigate}
            data-testid={`nav-${it.key}`}
            className={({ isActive }) =>
              cn(
                "group flex items-center gap-2.5 rounded-md px-3 py-2 text-sm font-medium transition-colors",
                isActive || (it.key === "kanban" && loc.pathname === legacyWorkPath)
                  ? "bg-primary/12 text-primary"
                  : "text-muted-foreground hover:bg-accent hover:text-foreground",
              )
            }
          >
            <Icon className="h-4 w-4 shrink-0" />
            <span className="truncate">{it.label}</span>
            {readOnly && <Lock className="ml-auto h-3 w-3 opacity-60" aria-label="Read-only" />}
          </NavLink>
        );
      })}
    </nav>
  );
}

function ProjectScopeSwitcher({
  projects,
  selectedProjectId,
  onSelect,
  loading,
  error,
  onNavigate,
}: {
  projects: Project[];
  selectedProjectId?: string;
  onSelect: (projectId?: string) => void;
  loading: boolean;
  error: string | null;
  onNavigate?: () => void;
}) {
  const loc = useLocation();
  const nav = useNavigate();
  const activeProject = selectedProjectId ? projects.find((project) => project.id === selectedProjectId) : undefined;
  const label = projectLabel(activeProject, selectedProjectId);

  const selectScope = (projectId?: string) => {
    onSelect(projectId);
    nav(projectScopePath(loc.pathname, projectId));
    onNavigate?.();
  };

  return (
    <div className="px-3 pb-3" data-testid="project-scope-switcher">
      <DropdownMenu>
        <DropdownMenuTrigger asChild>
          <button
            className="flex h-9 w-full items-center gap-2 rounded-md border border-border bg-background px-2.5 text-left text-xs font-medium text-foreground hover:bg-accent"
            type="button"
          >
            <Boxes className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
            <span className="min-w-0 flex-1 truncate">{label}</span>
            <ChevronDown className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
          </button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="start" className="w-56">
          <DropdownMenuLabel>Project scope</DropdownMenuLabel>
          <DropdownMenuItem onClick={() => selectScope()} data-testid="project-scope-global">
            <Check className={cn("mr-2 h-3.5 w-3.5", !selectedProjectId ? "opacity-100" : "opacity-0")} />
            Global workspace
          </DropdownMenuItem>
          <DropdownMenuSeparator />
          {loading && <DropdownMenuItem disabled>Loading projects...</DropdownMenuItem>}
          {error && <DropdownMenuItem disabled>{error}</DropdownMenuItem>}
          {!loading && !error && projects.length === 0 && <DropdownMenuItem disabled>No active projects</DropdownMenuItem>}
          {projects.map((project) => (
            <DropdownMenuItem
              key={project.id}
              onClick={() => selectScope(project.id)}
              data-testid={`project-scope-${project.id}`}
            >
              <Check className={cn("mr-2 h-3.5 w-3.5", selectedProjectId === project.id ? "opacity-100" : "opacity-0")} />
              <span className="min-w-0 flex-1 truncate">{project.name}</span>
            </DropdownMenuItem>
          ))}
        </DropdownMenuContent>
      </DropdownMenu>
    </div>
  );
}

function Brand() {
  return (
    <div className="flex items-center gap-2 px-4 py-4">
      <div className="grid h-8 w-8 place-items-center rounded-md bg-primary text-primary-foreground">
        <Terminal className="h-4 w-4" />
      </div>
      <div className="leading-tight">
        <p className="text-sm font-semibold tracking-tight">Hades Agent</p>
        <p className="text-[10px] uppercase tracking-widest text-muted-foreground">Operations</p>
      </div>
    </div>
  );
}

function displayName(name: unknown): string {
  return typeof name === "string" && name.trim() ? name.trim() : "User";
}

function userInitials(name: unknown): string {
  const initials = displayName(name)
    .split(/\s+/)
    .map((part) => part[0])
    .join("")
    .slice(0, 2)
    .toUpperCase();

  return initials || "U";
}

export default function AppShell() {
  const { user, logout } = useAuth();
  const { theme, toggle } = useTheme();
  const [mobileOpen, setMobileOpen] = useState(false);
  const cmd = useCommandPalette();
  const [selectedProjectId, setSelectedProjectId] = useState<string | undefined>(() => readStoredProjectScope());

  const nav = useNavigate();
  const loc = useLocation();
  const projectState = useApi(() => api.getProjects("active"), []);

  const crumbs = loc.pathname.split("/").filter(Boolean);
  const activeProjectId = activeProjectFromPath(loc.pathname);
  const effectiveProjectId = activeProjectId ?? selectedProjectId;

  useEffect(() => {
    if (!activeProjectId || activeProjectId === selectedProjectId) return;
    setSelectedProjectId(activeProjectId);
    writeStoredProjectScope(activeProjectId);
  }, [activeProjectId, selectedProjectId]);

  if (!user) return null;

  const activeProject = effectiveProjectId ? projectState.data?.find((project) => project.id === effectiveProjectId) : undefined;
  const scopeLabel = projectLabel(activeProject, effectiveProjectId);
  const name = displayName(user.name);
  const initials = userInitials(user.name);
  const email = typeof user.email === "string" ? user.email : "";
  const roleLabel = ROLE_LABEL[user.role] ?? "User";
  const avatarColor = typeof user.avatar_color === "string" && user.avatar_color ? user.avatar_color : "#64748b";

  const selectProjectScope = (projectId?: string) => {
    setSelectedProjectId(projectId);
    writeStoredProjectScope(projectId);
  };

  const onLogout = async () => { await logout(); nav("/login"); };

  return (
    <div className="flex h-screen overflow-hidden bg-background">
      {/* Desktop sidebar */}
      <aside className="hidden w-60 shrink-0 flex-col border-r border-border bg-card/40 lg:flex">
        <Brand />
        <ProjectScopeSwitcher
          projects={projectState.data ?? []}
          selectedProjectId={effectiveProjectId}
          onSelect={selectProjectScope}
          loading={projectState.loading}
          error={projectState.error}
        />
        <div className="flex-1 overflow-y-auto pb-4"><NavList selectedProjectId={effectiveProjectId} /></div>
        <div className="border-t border-border p-3 text-[11px] text-muted-foreground">
          <p className="font-mono">v0.9 · {USE_MOCK ? "mock adapter" : "HTTP adapter"}</p>
          {!USE_MOCK && <p className="mt-0.5 truncate font-mono">{API_BASE_URL}</p>}
          <p className="mt-0.5">Browser UI uses <span className="font-mono">/api/dashboard</span></p>
        </div>
      </aside>

      <div className="flex min-w-0 flex-1 flex-col">
        {/* Topbar */}
        <header className="flex h-14 shrink-0 items-center gap-3 border-b border-border bg-card/40 px-3 sm:px-5">
          <Sheet open={mobileOpen} onOpenChange={setMobileOpen}>
            <SheetTrigger asChild>
              <Button variant="ghost" size="icon" className="lg:hidden" data-testid="mobile-nav-toggle">
                <Menu className="h-5 w-5" />
              </Button>
            </SheetTrigger>
            <SheetContent side="left" className="w-64 p-0">
              <Brand />
              <ProjectScopeSwitcher
                projects={projectState.data ?? []}
                selectedProjectId={effectiveProjectId}
                onSelect={selectProjectScope}
                loading={projectState.loading}
                error={projectState.error}
                onNavigate={() => setMobileOpen(false)}
              />
              <NavList selectedProjectId={effectiveProjectId} onNavigate={() => setMobileOpen(false)} />
            </SheetContent>
          </Sheet>

          <span
            className="inline-flex h-7 max-w-[42vw] shrink-0 items-center truncate rounded-md border border-border bg-background px-2.5 text-xs font-medium text-muted-foreground sm:max-w-[180px]"
            data-testid="scope-label"
          >
            {scopeLabel}
          </span>

          <div className="flex min-w-0 items-center gap-1.5 text-sm text-muted-foreground" data-testid="breadcrumb">
            {crumbs.length === 0 && <span>dashboard</span>}
            {crumbs.map((c, i) => (
              <React.Fragment key={i}>
                {i > 0 && <ChevronRight className="h-3.5 w-3.5 opacity-50" />}
                <span className={cn("truncate font-mono text-xs", i === crumbs.length - 1 && "text-foreground")}>{c}</span>
              </React.Fragment>
            ))}
          </div>

          <div className="ml-auto flex items-center gap-1.5">
            <button
              type="button"
              onClick={() => cmd.setOpen(true)}
              className="hidden items-center gap-1.5 rounded-md border border-border bg-background px-2 py-1 font-mono text-[11px] text-muted-foreground hover:bg-accent hover:text-foreground sm:inline-flex"
              title="Command palette"
              data-testid="command-open"
            >
              <CommandIcon className="h-3 w-3" /> K
            </button>
            <Button variant="ghost" size="icon" onClick={toggle} data-testid="theme-toggle" title="Toggle theme">
              {theme === "dark" ? <Sun className="h-4 w-4" /> : <Moon className="h-4 w-4" />}
            </Button>

            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <button className="flex items-center gap-2 rounded-md border border-border px-2 py-1.5 text-sm hover:bg-accent" data-testid="user-menu">
                  <span className="grid h-6 w-6 place-items-center rounded-full text-[11px] font-semibold text-white" style={{ background: avatarColor }}>
                    {initials}
                  </span>
                  <span className="hidden text-left leading-none sm:block">
                    <span className="block text-xs font-medium">{name}</span>
                    <span className="block text-[10px] uppercase tracking-wide text-muted-foreground">{roleLabel}</span>
                  </span>
                </button>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="end" className="w-52">
                <DropdownMenuLabel>
                  <p className="text-xs font-medium">{name}</p>
                  <p className="text-[11px] font-normal text-muted-foreground">{email}</p>
                </DropdownMenuLabel>
                <DropdownMenuSeparator />
                <DropdownMenuItem onClick={onLogout} data-testid="logout-button">
                  <LogOut className="mr-2 h-4 w-4" /> Sign out
                </DropdownMenuItem>
              </DropdownMenuContent>
            </DropdownMenu>
          </div>
        </header>

        <main className="flex-1 overflow-y-auto">
          <div className="mx-auto max-w-[1600px] px-3 py-5 sm:px-6">
            <Outlet />
          </div>
        </main>
      </div>
      <CommandPalette open={cmd.open} onOpenChange={cmd.setOpen} />
    </div>

  );
}
