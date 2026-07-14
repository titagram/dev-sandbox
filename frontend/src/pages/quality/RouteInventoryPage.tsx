import React, { useState } from "react";
import { Link } from "react-router-dom";
import { api } from "@/api/devboardApi";
import { useApi } from "@/hooks/useApi";
import { PageHeader, MetricCard, Panel } from "@/components/devboard/Layout";
import { DataState } from "@/components/devboard/DataState";
import { Pill } from "@/components/devboard/Badges";
import { Input } from "@/components/ui/input";
import { RouteClassification } from "@/types/devboard";
import { ChevronLeft, AlertTriangle, Search } from "lucide-react";

const CLASS_TONE: Record<RouteClassification, any> = {
  SAFE_READ: "green", MUTATING: "amber", DESTRUCTIVE: "red", AUTH: "blue", UNKNOWN: "red",
};
const METHOD_TONE: Record<string, any> = { GET: "green", POST: "blue", PATCH: "amber", PUT: "amber", DELETE: "red" };

export default function RouteInventoryPage() {
  const state = useApi(() => api.getRouteInventory(), []);
  const [q, setQ] = useState("");

  return (
    <div className="space-y-4" data-testid="route-inventory-page">
      <Link to="/quality" className="inline-flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground"><ChevronLeft className="h-3.5 w-3.5" /> Quality Center</Link>
      <PageHeader title="Route Inventory" subtitle="Laravel routes with classification and parameter provider status. Reading the inventory is a safe operation."
        actions={<div className="relative"><Search className="absolute left-2.5 top-2.5 h-3.5 w-3.5 text-muted-foreground" /><Input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Filter routes…" className="h-9 w-56 pl-8" data-testid="route-search" /></div>} />

      <DataState state={state} isEmpty={(d) => d.length === 0}>
        {(routes) => {
          const f = routes.filter((r) => `${r.name} ${r.path} ${r.controller_action}`.toLowerCase().includes(q.toLowerCase()));
          const unknown = routes.filter((r) => r.classification === "UNKNOWN").length;
          const unconfigured = routes.filter((r) => !r.configured).length;
          return (
            <>
              <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <MetricCard label="Routes" value={routes.length} />
                <MetricCard label="SAFE_READ" value={routes.filter((r) => r.classification === "SAFE_READ").length} tone="good" />
                <MetricCard label="Unconfigured" value={unconfigured} tone={unconfigured ? "warn" : "good"} />
                <MetricCard label="UNKNOWN" value={unknown} tone={unknown ? "bad" : "good"} />
              </div>

              <Panel dense>
                <div className="overflow-x-auto">
                  <table className="w-full min-w-[1000px] text-sm">
                    <thead><tr className="border-b border-border text-left text-[11px] uppercase tracking-wide text-muted-foreground">
                      <th className="px-4 py-2 font-medium">Name</th><th className="px-3 py-2 font-medium">Method</th><th className="px-3 py-2 font-medium">Path</th>
                      <th className="px-3 py-2 font-medium">Controller / action</th><th className="px-3 py-2 font-medium">Classification</th><th className="px-3 py-2 font-medium">Configured</th><th className="px-3 py-2 font-medium">Parameter provider</th>
                    </tr></thead>
                    <tbody>{f.map((r) => (
                      <React.Fragment key={r.id}>
                        <tr className="border-b border-border/60 hover:bg-accent/30" data-testid={`route-${r.id}`}>
                          <td className="px-4 py-2.5 font-mono text-xs">{r.name}</td>
                          <td className="px-3 py-2.5"><Pill tone={METHOD_TONE[r.method]}>{r.method}</Pill></td>
                          <td className="px-3 py-2.5 font-mono text-xs">{r.path}</td>
                          <td className="px-3 py-2.5 font-mono text-xs text-muted-foreground">{r.controller_action}</td>
                          <td className="px-3 py-2.5"><Pill tone={CLASS_TONE[r.classification]}>{r.classification}</Pill></td>
                          <td className="px-3 py-2.5">{r.configured ? <Pill tone="green">Configured</Pill> : <Pill tone="amber">Unconfigured</Pill>}</td>
                          <td className="px-3 py-2.5"><Pill tone={r.parameter_provider === "configured" ? "green" : r.parameter_provider === "missing" ? "red" : "slate"}>{r.parameter_provider.replace(/_/g, " ")}</Pill></td>
                        </tr>
                        {r.warning && (
                          <tr className="border-b border-border/60 bg-red-500/5"><td colSpan={7} className="px-4 py-2 text-[11px] text-red-600 dark:text-red-400"><span className="flex items-center gap-1.5"><AlertTriangle className="h-3.5 w-3.5" />{r.warning}</span></td></tr>
                        )}
                      </React.Fragment>
                    ))}</tbody>
                  </table>
                </div>
              </Panel>
            </>
          );
        }}
      </DataState>
    </div>
  );
}
