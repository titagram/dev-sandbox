import React from "react";
import { Link } from "react-router-dom";
import { api } from "@/api/devboardApi";
import { useApi } from "@/hooks/useApi";
import { PageHeader, MetricCard, Panel } from "@/components/devboard/Layout";
import { DataState } from "@/components/devboard/DataState";
import { Pill } from "@/components/devboard/Badges";
import { AuthDecision } from "@/types/devboard";
import { ChevronLeft, CircleCheck, CircleX, MinusCircle, AlertTriangle } from "lucide-react";

const RESULT = {
  pass: { tone: "green", I: CircleCheck, label: "Pass" },
  fail: { tone: "red", I: CircleX, label: "Fail" },
  skipped: { tone: "slate", I: MinusCircle, label: "Skipped" },
} as const;
const DECISION_TONE: Record<AuthDecision, any> = {
  allowed: "green", denied: "red", allowed_owner_only: "blue", allowed_same_tenant: "teal", unknown: "amber",
};
const ACTORS = ["guest", "user", "admin", "developer", "sysadmin"];

export default function RouteSmokePage() {
  const state = useApi(() => api.getRouteSmoke(), []);

  return (
    <div className="space-y-4" data-testid="route-smoke-page">
      <Link to="/quality" className="inline-flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground"><ChevronLeft className="h-3.5 w-3.5" /> Quality Center</Link>
      <PageHeader title="Route Smoke Report" subtitle="Smoke runs only for SAFE_READ routes. A 5xx is always a blocking failure. Mutating/destructive routes are skipped unless explicitly allowed." />

      <DataState state={state}>
        {(d) => {
          const rows = d.rows;
          const passed = rows.filter((r) => r.result === "pass").length;
          const failed = rows.filter((r) => r.result === "fail").length;
          const skipped = rows.filter((r) => r.result === "skipped").length;
          const blocking = rows.filter((r) => r.blocking).length;
          return (
            <>
              <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <MetricCard label="Passed" value={passed} tone="good" />
                <MetricCard label="Failed" value={failed} tone={failed ? "bad" : "good"} />
                <MetricCard label="Skipped" value={skipped} />
                <MetricCard label="Blocking" value={blocking} tone={blocking ? "bad" : "good"} />
              </div>

              {blocking > 0 && (
                <div className="flex items-center gap-2 rounded-md border border-red-500/30 bg-red-500/5 px-3 py-2.5 text-sm text-red-600 dark:text-red-400" data-testid="blocking-banner">
                  <AlertTriangle className="h-4 w-4" /> {blocking} blocking failure(s) — a route returned 5xx or an unexpected status that blocks the gate.
                </div>
              )}

              <Panel title="Smoke results" dense>
                <div className="overflow-x-auto">
                  <table className="w-full min-w-[760px] text-sm">
                    <thead><tr className="border-b border-border text-left text-[11px] uppercase tracking-wide text-muted-foreground">
                      <th className="px-4 py-2 font-medium">Route</th><th className="px-3 py-2 font-medium">Actor</th><th className="px-3 py-2 font-medium">Expected</th><th className="px-3 py-2 font-medium">Actual</th><th className="px-3 py-2 font-medium">Result</th><th className="px-3 py-2 font-medium">Notes</th>
                    </tr></thead>
                    <tbody>{rows.map((r) => { const cfg = RESULT[r.result]; const I = cfg.I; return (
                      <tr key={r.id} className={`border-b border-border/60 last:border-0 ${r.blocking ? "bg-red-500/5" : "hover:bg-accent/30"}`} data-testid={`smoke-${r.id}`}>
                        <td className="px-4 py-2.5 font-mono text-xs">{r.route}</td>
                        <td className="px-3 py-2.5"><Pill tone="slate">{r.actor}</Pill></td>
                        <td className="px-3 py-2.5 font-mono text-xs tabular-nums">{r.expected_status}</td>
                        <td className={`px-3 py-2.5 font-mono text-xs tabular-nums ${r.actual_status && r.actual_status >= 500 ? "font-bold text-red-500" : ""}`}>{r.actual_status ?? "—"}</td>
                        <td className="px-3 py-2.5"><Pill tone={cfg.tone as any} icon={I}>{cfg.label}{r.blocking ? " · blocking" : ""}</Pill></td>
                        <td className="px-3 py-2.5 text-xs text-muted-foreground">{r.skipped_reason || "—"}</td>
                      </tr>
                    ); })}</tbody>
                  </table>
                </div>
              </Panel>

              <Panel title="Authorization matrix" dense action={<span className="px-1 text-[11px] text-muted-foreground">Laravel policies / gates</span>}>
                <div className="overflow-x-auto">
                  <table className="w-full min-w-[760px] text-sm">
                    <thead><tr className="border-b border-border text-left text-[11px] uppercase tracking-wide text-muted-foreground"><th className="px-4 py-2 font-medium">Resource</th>{ACTORS.map((a) => <th key={a} className="px-3 py-2 font-medium">{a}</th>)}</tr></thead>
                    <tbody>{d.matrix.map((row) => (
                      <tr key={row.resource} className="border-b border-border/60 last:border-0"><td className="px-4 py-2.5 font-mono text-xs">{row.resource}</td>{ACTORS.map((a) => { const dec = row.decisions[a] || "unknown"; return <td key={a} className="px-3 py-2.5"><Pill tone={DECISION_TONE[dec]}>{dec.replace(/_/g, " ")}</Pill></td>; })}</tr>
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
