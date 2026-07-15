import {
  LayoutDashboard, Boxes, KanbanSquare, PlayCircle, BookText, Network, Package,
  Bot, ShieldCheck, Settings, ServerCog, MessageSquare, Brain, Wrench, Workflow,
} from "lucide-react";
import { Role } from "@/types/devboard";

export const PROJECT_SCOPE_STORAGE_KEY = "devboard.selectedProjectScope.v1";

export interface NavItem {
  key: string;
  label: string;
  path: string;
  icon: any;
  roles: Role[];
  /** read-only access for some roles (e.g. Developer on Quality). */
  readOnlyRoles?: Role[];
  /** Some read-only routes are reachable without becoming primary nav. */
  hideReadOnlyFromNav?: boolean;
  /** Keep the section reachable by guards without showing it in primary nav. */
  hidden?: boolean;
}

export const NAV_ITEMS: NavItem[] = [
  { key: "projects", label: "Projects", path: "/projects", icon: Boxes, roles: ["admin", "pm", "developer", "sysadmin"] },
  { key: "kanban", label: "Work", path: "/kanban", icon: KanbanSquare, roles: ["admin", "pm", "developer"] },
  { key: "ask", label: "Ask", path: "/ask", icon: MessageSquare, roles: ["admin", "pm", "developer"] },
  { key: "agent-chat", label: "Chat", path: "/agent-chat", icon: Bot, roles: ["admin", "pm", "developer"], readOnlyRoles: ["sysadmin"] },
  { key: "memory", label: "Memory", path: "/memory", icon: Brain, roles: ["admin", "pm", "developer"], readOnlyRoles: ["sysadmin"], hideReadOnlyFromNav: true },
  { key: "wiki", label: "Wiki", path: "/wiki", icon: BookText, roles: ["admin", "pm", "developer"] },
  { key: "engineering", label: "Engineering", path: "/engineering", icon: Wrench, roles: ["admin", "developer", "sysadmin"] },
  { key: "settings", label: "Settings", path: "/admin", icon: Settings, roles: ["admin", "sysadmin"] },
  { key: "hades", label: "Hades", path: "/admin/hades", icon: Workflow, roles: ["admin"] },

  { key: "overview", label: "Overview", path: "/overview", icon: LayoutDashboard, roles: ["admin", "pm", "developer", "sysadmin"], hidden: true },
  { key: "runs", label: "Runs", path: "/runs", icon: PlayCircle, roles: ["admin", "pm", "developer", "sysadmin"], hidden: true },
  { key: "graph", label: "Graph", path: "/graph", icon: Network, roles: ["admin", "pm", "developer"], hidden: true },
  { key: "artifacts", label: "Artifacts", path: "/artifacts", icon: Package, roles: ["admin", "pm", "developer", "sysadmin"], hidden: true },
  { key: "quality", label: "Quality", path: "/quality", icon: ShieldCheck, roles: ["admin", "sysadmin"], readOnlyRoles: ["developer"], hidden: true },
  { key: "agent-work", label: "Agent Work", path: "/agent-work", icon: Workflow, roles: ["admin", "pm", "developer"], readOnlyRoles: ["sysadmin"], hidden: true },
  { key: "admin", label: "Admin", path: "/admin", icon: Settings, roles: ["admin"], hidden: true },
  { key: "system", label: "System", path: "/system", icon: ServerCog, roles: ["admin", "sysadmin"], hidden: true },
];

export function navForRole(role: Role): NavItem[] {
  if (role === "agent") return [];
  return NAV_ITEMS.filter(
    (n) => !n.hidden && (n.roles.includes(role) || (n.readOnlyRoles?.includes(role) && !n.hideReadOnlyFromNav)),
  );
}

const PROJECT_SCOPED_NAV_PATH: Record<string, string> = {
  kanban: "kanban",
  ask: "ask",
  "agent-chat": "agent-chat",
  "agent-work": "agent-work",
  memory: "memory",
  wiki: "wiki",
  engineering: "engineering",
  runs: "runs",
  graph: "graph",
  artifacts: "artifacts",
};

export function navPathForItem(item: NavItem, role: Role, activeProjectId?: string) {
  if (activeProjectId) {
    const scopedPath = PROJECT_SCOPED_NAV_PATH[item.key];
    if (scopedPath) return `/projects/${encodeURIComponent(activeProjectId)}/${scopedPath}`;
  }

  if (item.key === "settings") {
    return role === "sysadmin" ? "/system" : "/admin";
  }

  return item.path;
}

export function canAccess(role: Role, key: string): { ok: boolean; readOnly: boolean } {
  const item = NAV_ITEMS.find((n) => n.key === key);
  if (!item) return { ok: false, readOnly: false };
  if (item.roles.includes(role)) return { ok: true, readOnly: false };
  if (item.readOnlyRoles?.includes(role)) return { ok: true, readOnly: true };
  return { ok: false, readOnly: false };
}

// PM must not see code-write or plugin-command controls.
export function canMutate(role: Role): boolean {
  return role === "admin" || role === "developer" || role === "sysadmin";
}
export function canCodeWrite(role: Role): boolean {
  return role === "admin" || role === "developer";
}
export const ROLE_LABEL: Record<Role, string> = {
  admin: "Admin", pm: "PM", developer: "Developer", sysadmin: "Sysadmin", agent: "Agent",
};
