import React from "react";
import { Link, useParams } from "react-router-dom";
import { useAuth } from "@/context/AuthContext";
import { canAccess } from "@/lib/nav";
import { PageHeader, Panel } from "@/components/devboard/Layout";
import { Pill } from "@/components/devboard/Badges";
import { Button } from "@/components/ui/button";
import {
  ArrowRight, BookText, Boxes, Brain, KanbanSquare, MessageSquare, Network,
  Package, PlayCircle, ServerCog, Settings, ShieldCheck, Workflow, Wrench,
} from "lucide-react";

type EngineeringLink = {
  key: string;
  label: string;
  description: string;
  path: string | null;
  scope: "Project" | "Global";
  icon: any;
};

function LinkList({ items }: { items: (EngineeringLink & { access: { readOnly: boolean } })[] }) {
  if (items.length === 0) {
    return <p className="px-4 py-8 text-center text-sm text-muted-foreground">No permitted surfaces for this role.</p>;
  }

  return (
    <div className="divide-y divide-border/60">
      {items.map((item) => (
        <Link key={item.key} to={item.path!} className="group flex items-center gap-3 px-4 py-3 transition-colors hover:bg-accent/40" data-testid={`engineering-link-${item.key}`}>
          <span className="grid h-9 w-9 shrink-0 place-items-center rounded-md bg-primary/10 text-primary">
            <item.icon className="h-4 w-4" />
          </span>
          <span className="min-w-0 flex-1">
            <span className="flex flex-wrap items-center gap-2">
              <span className="text-sm font-semibold">{item.label}</span>
              <Pill tone={item.scope === "Project" ? "teal" : "slate"}>{item.scope}</Pill>
              {item.access.readOnly && <Pill tone="amber">Read-only</Pill>}
            </span>
            <span className="mt-0.5 block text-xs text-muted-foreground">{item.description}</span>
          </span>
          <ArrowRight className="h-4 w-4 shrink-0 text-muted-foreground opacity-0 transition-opacity group-hover:opacity-100" />
        </Link>
      ))}
    </div>
  );
}

export default function EngineeringPage() {
  const { projectId } = useParams();
  const { user } = useAuth();

  if (!user) return null;

  const scoped = (path: string, globalPath: string | null = path) =>
    projectId ? `/projects/${projectId}${path}` : globalPath;

  const operationalLinks: EngineeringLink[] = [
    {
      key: "kanban",
      label: "Work",
      description: "Kanban board, task state, blockers, and linked run evidence.",
      path: scoped("/kanban", "/kanban"),
      scope: projectId ? "Project" : "Global",
      icon: KanbanSquare,
    },
    {
      key: "agent-work",
      label: "Agent Work",
      description: "Queued, active, completed, and canceled agent work items.",
      path: scoped("/agent-work", null),
      scope: "Project",
      icon: Workflow,
    },
    {
      key: "ask",
      label: "Ask",
      description: "Question intake that queues tracked agent work.",
      path: scoped("/ask", "/ask"),
      scope: projectId ? "Project" : "Global",
      icon: MessageSquare,
    },
    {
      key: "memory",
      label: "Memory",
      description: "Project decisions, handoffs, verification notes, and agent findings.",
      path: scoped("/memory", "/memory"),
      scope: projectId ? "Project" : "Global",
      icon: Brain,
    },
  ];

  const technicalLinks: EngineeringLink[] = [
    {
      key: "runs",
      label: "Runs",
      description: "Execution history, import status, and analyzer outcomes.",
      path: scoped("/runs", "/runs"),
      scope: projectId ? "Project" : "Global",
      icon: PlayCircle,
    },
    {
      key: "wiki",
      label: "Wiki",
      description: "Evidence pages, freshness, and source status.",
      path: scoped("/wiki", "/wiki"),
      scope: projectId ? "Project" : "Global",
      icon: BookText,
    },
    {
      key: "graph",
      label: "Graph",
      description: "Code relationship graph from imported artifacts.",
      path: scoped("/graph", "/graph"),
      scope: projectId ? "Project" : "Global",
      icon: Network,
    },
    {
      key: "artifacts",
      label: "Artifacts",
      description: "Genesis, delta, analysis, and report artifacts.",
      path: scoped("/artifacts", "/artifacts"),
      scope: projectId ? "Project" : "Global",
      icon: Package,
    },
    {
      key: "quality",
      label: "Quality Center",
      description: "Route inventory, smoke checks, gates, and readiness reports.",
      path: "/quality",
      scope: "Global",
      icon: ShieldCheck,
    },
    {
      key: "system",
      label: "System Operations",
      description: "Runtime, queues, retention, audit export, and backup checks.",
      path: "/system",
      scope: "Global",
      icon: ServerCog,
    },
    {
      key: "admin",
      label: "Admin",
      description: "Plugin devices, tokens, model providers, and agent profiles.",
      path: "/admin",
      scope: "Global",
      icon: Settings,
    },
  ];

  const permitted = (items: EngineeringLink[]) =>
    items
      .map((item) => ({ ...item, access: canAccess(user.role, item.key) }))
      .filter((item) => item.path && item.access.ok);

  const permittedOperational = permitted(operationalLinks);
  const permittedTechnical = permitted(technicalLinks);

  return (
    <div className="space-y-5" data-testid="engineering-page">
      <PageHeader
        title="Engineering"
        subtitle={projectId ? "Project cockpit for operational queues, evidence, artifacts, and platform checks." : "Cockpit for DevBoard operational and technical surfaces."}
        meta={projectId && <Link to={`/projects/${projectId}`} className="inline-flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground"><Boxes className="h-3.5 w-3.5" />Project {projectId}</Link>}
        actions={projectId && <Button size="sm" variant="outline" asChild><Link to={`/projects/${projectId}`}><Boxes className="mr-1.5 h-3.5 w-3.5" /> Project</Link></Button>}
      />

      <div className="grid gap-4 xl:grid-cols-2">
        <Panel title={<span className="inline-flex items-center gap-1.5"><Workflow className="h-4 w-4 text-muted-foreground" />Operational surfaces</span>} dense>
          <LinkList items={permittedOperational} />
        </Panel>

        <Panel title={<span className="inline-flex items-center gap-1.5"><Wrench className="h-4 w-4 text-muted-foreground" />Technical surfaces</span>} dense>
          <LinkList items={permittedTechnical} />
        </Panel>
      </div>
    </div>
  );
}
