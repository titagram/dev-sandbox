import React, { useEffect, useMemo, useState } from "react";
import { useNavigate, useLocation } from "react-router-dom";
import { Command } from "cmdk";
import { Boxes, KanbanSquare, Bot, MessageSquare, BookText, GitBranch, Activity, Shield, Search, Command as CommandIcon, Sun, Moon, LogOut, Network, FileText } from "lucide-react";
import { useAuth } from "@/context/AuthContext";
import { useTheme } from "@/context/ThemeContext";

type Action = {
  id: string;
  label: string;
  hint?: string;
  keywords?: string;
  icon: React.ComponentType<{ className?: string }>;
  run: () => void;
  section: "Navigate" | "Actions";
};

function activeProjectFromPath(pathname: string): string | undefined {
  const raw = pathname.match(/^\/projects\/([^/]+)/)?.[1];
  if (!raw) return undefined;
  try { return decodeURIComponent(raw); } catch { return raw; }
}

export function CommandPalette({ open, onOpenChange }: { open: boolean; onOpenChange: (o: boolean) => void }) {
  const nav = useNavigate();
  const loc = useLocation();
  const { logout } = useAuth();
  const { theme, toggle } = useTheme();
  const [query, setQuery] = useState("");

  useEffect(() => {
    if (!open) setQuery("");
  }, [open]);

  const projectId = activeProjectFromPath(loc.pathname);
  const scoped = (path: string) => (projectId ? `/projects/${encodeURIComponent(projectId)}${path}` : path);

  const go = (path: string) => () => {
    onOpenChange(false);
    nav(path);
  };

  const actions: Action[] = useMemo(() => [
    { id: "nav-projects", label: "Projects", section: "Navigate", icon: Boxes, run: go("/projects") },
    { id: "nav-kanban", label: "Kanban board", section: "Navigate", icon: KanbanSquare, keywords: "tasks board", run: go(scoped("/kanban")) },
    { id: "nav-agents", label: "Ask agents", section: "Navigate", icon: MessageSquare, keywords: "ai chat", run: go(scoped("/ask")) },
    { id: "nav-agent-work", label: "Agent work", section: "Navigate", icon: Bot, run: go(scoped("/agent-work")) },
    { id: "nav-runs", label: "Runs", section: "Navigate", icon: Activity, run: go(scoped("/runs")) },
    { id: "nav-wiki", label: "Wiki", section: "Navigate", icon: BookText, run: go(scoped("/wiki")) },
    { id: "nav-engineering", label: "Engineering", section: "Navigate", icon: GitBranch, run: go(scoped("/engineering")) },
    { id: "nav-graph", label: "Graph", section: "Navigate", icon: Network, run: go(scoped("/graph")) },
    { id: "nav-memory", label: "Project memory", section: "Navigate", icon: FileText, run: go(scoped("/memory")) },
    { id: "nav-admin", label: "Admin", section: "Navigate", icon: Shield, keywords: "settings", run: go("/admin") },
    { id: "act-theme", label: theme === "dark" ? "Switch to light theme" : "Switch to dark theme", section: "Actions", icon: theme === "dark" ? Sun : Moon, run: () => { toggle(); onOpenChange(false); } },
    { id: "act-logout", label: "Sign out", section: "Actions", icon: LogOut, run: async () => { onOpenChange(false); await logout(); nav("/login"); } },
  ], [projectId, theme, loc.pathname]);

  const grouped = useMemo(() => {
    const map = new Map<string, Action[]>();
    for (const a of actions) {
      if (!map.has(a.section)) map.set(a.section, []);
      map.get(a.section)!.push(a);
    }
    return Array.from(map.entries());
  }, [actions]);

  if (!open) return null;

  return (
    <div
      className="fixed inset-0 z-50 flex items-start justify-center bg-background/70 px-4 pt-[15vh] backdrop-blur-sm"
      onClick={() => onOpenChange(false)}
      data-testid="command-palette"
    >
      <div
        className="w-full max-w-xl overflow-hidden rounded-lg border border-border bg-card shadow-2xl"
        onClick={(e) => e.stopPropagation()}
      >
        <Command label="Command palette" className="flex flex-col">
          <div className="flex items-center gap-2 border-b border-border px-3 py-2.5">
            <Search className="h-4 w-4 text-muted-foreground" />
            <Command.Input
              autoFocus
              value={query}
              onValueChange={setQuery}
              placeholder="Type a command or search…"
              className="flex-1 bg-transparent text-sm outline-none placeholder:text-muted-foreground"
              data-testid="command-input"
            />
            <kbd className="hidden rounded border border-border bg-background px-1.5 py-0.5 font-mono text-[10px] text-muted-foreground sm:inline">ESC</kbd>
          </div>
          <Command.List className="max-h-[50vh] overflow-y-auto p-1.5">
            <Command.Empty className="px-3 py-6 text-center text-xs text-muted-foreground">No results.</Command.Empty>
            {grouped.map(([section, items]) => (
              <Command.Group key={section} heading={section} className="[&_[cmdk-group-heading]]:px-2 [&_[cmdk-group-heading]]:pb-1 [&_[cmdk-group-heading]]:pt-2 [&_[cmdk-group-heading]]:text-[10px] [&_[cmdk-group-heading]]:font-mono [&_[cmdk-group-heading]]:uppercase [&_[cmdk-group-heading]]:tracking-wide [&_[cmdk-group-heading]]:text-muted-foreground">
                {items.map((a) => (
                  <Command.Item
                    key={a.id}
                    value={`${a.label} ${a.keywords || ""}`}
                    onSelect={() => a.run()}
                    className="flex cursor-pointer items-center gap-2 rounded px-2 py-1.5 text-sm text-foreground data-[selected=true]:bg-accent data-[selected=true]:text-accent-foreground"
                    data-testid={`command-item-${a.id}`}
                  >
                    <a.icon className="h-3.5 w-3.5 text-muted-foreground" />
                    <span className="flex-1">{a.label}</span>
                  </Command.Item>
                ))}
              </Command.Group>
            ))}
          </Command.List>
          <div className="flex items-center justify-between border-t border-border bg-background/40 px-3 py-1.5 text-[10px] text-muted-foreground">
            <span className="flex items-center gap-1">
              <CommandIcon className="h-3 w-3" /> K anywhere
            </span>
            <span className="font-mono">↑↓ navigate · ↵ select</span>
          </div>
        </Command>
      </div>
    </div>
  );
}

export function useCommandPalette() {
  const [open, setOpen] = useState(false);
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === "k") {
        e.preventDefault();
        setOpen((v) => !v);
      } else if (e.key === "Escape") {
        setOpen(false);
      }
    };
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, []);
  return { open, setOpen };
}
