import React from "react";
import { Link } from "react-router-dom";
import { toast } from "sonner";
import { api } from "@/api/devboardApi";
import { useApi } from "@/hooks/useApi";
import { useAuth } from "@/context/AuthContext";
import { canAccess } from "@/lib/nav";
import { PageHeader, MetricCard, Panel } from "@/components/devboard/Layout";
import { DataState } from "@/components/devboard/DataState";
import { QualityStatusBadge, SeverityBadge, RiskBadge, SourceMetaInline, Pill } from "@/components/devboard/Badges";
import { Tabs, TabsList, TabsTrigger, TabsContent } from "@/components/ui/tabs";
import { Button } from "@/components/ui/button";
import { CheckState, AuthDecision } from "@/types/devboard";
import { relativeTime, titleCase, formatDateTime } from "@/lib/format";
import {
  ListTree, Activity, ShieldAlert, ArrowRight, AlertTriangle, Lock, Play,
  CircleCheck, Ban, Clock,
} from "lucide-react";

const CHECK_STATE: Record<CheckState, { tone: any; label: string }> = {
  implemented: { tone: "green", label: "Implemented" },
  configured_disabled: { tone: "slate", label: "Configured · disabled" },
  missing_setup: { tone: "amber", label: "Missing setup" },
  warning: { tone: "amber", label: "Warning" },
  blocking: { tone: "red", label: "Blocking" },
};
const DECISION_TONE: Record<AuthDecision, any> = {
  allowed: "green", denied: "red", allowed_owner_only: "blue", allowed_same_tenant: "teal", unknown: "amber",
};

export default function QualityCenterPage() {
  const { user } = useAuth();
  const readOnly = canAccess(user!.role, "quality").readOnly;
  const ov = useApi(() => api.getQualityOverview(), []);
  const cs = useApi(() => api.getQualityCurrentState(), []);
  const rm = useApi(() => api.getQualityRoadmap(), []);
  const rep = useApi(() => api.getQualityReports(), []);
  const sm = useApi(() => api.getRouteSmoke(), []);

  const runSmoke = async () => { await api.runQualityCheck("route-smoke", false); toast.success("Route smoke (SAFE_READ) re-run queued"); rep.reload(); ov.reload(); };

  return (
    <div className="space-y-5" data-testid="quality-center-page">
      <PageHeader
        title="Quality Center"
        subtitle="Deterministic, controlled verification. Tests verify domain truth — not only that routes return 200."
        meta={readOnly && <Pill tone="amber" icon={Lock}>Read-only access</Pill>}
        actions={readOnly
          ? <Button size="sm" variant="outline" disabled><Lock className="mr-1.5 h-3.5 w-3.5" /> Run check</Button>
          : <Button size="sm" variant="outline" onClick={runSmoke} data-testid="run-smoke-btn"><Play className="mr-1.5 h-3.5 w-3.5" /> Run SAFE_READ smoke</Button>}
      />

      {/* Sub-navigation */}
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
        {[
          { to: "/quality/route-inventory", icon: ListTree, label: "Route Inventory", desc: "Laravel routes, classification, parameter providers" },
          { to: "/quality/route-smoke", icon: Activity, label: "Route Smoke", desc: "Actor vs expected/actual status, 5xx blocking" },
          { to: "/quality/gates/pull_request", icon: ShieldAlert, label: "Quality Gates", desc: "PR / nightly / release blocking findings" },
        ].map((c) => (
          <Link key={c.to} to={c.to} data-testid={`quality-link-${c.label.toLowerCase().replace(/ /g, "-")}`}
            className="group flex items-start justify-between rounded-md border border-border bg-card p-4 transition-colors hover:border-primary/50">
            <div className="flex items-start gap-3"><span className="grid h-9 w-9 place-items-center rounded-md bg-primary/10 text-primary"><c.icon className="h-4 w-4" /></span><div><p className="text-sm font-semibold">{c.label}</p><p className="mt-0.5 text-[11px] text-muted-foreground">{c.desc}</p></div></div>
            <ArrowRight className="h-4 w-4 text-muted-foreground opacity-0 transition-opacity group-hover:opacity-100" />
          </Link>
        ))}
      </div>

      <Tabs defaultValue="overview">
        <TabsList data-testid="quality-tabs" className="flex-wrap">
          <TabsTrigger value="overview">Overview</TabsTrigger>
          <TabsTrigger value="truth">Truth Registry</TabsTrigger>
          <TabsTrigger value="authz">Authorization</TabsTrigger>
          <TabsTrigger value="security">Security Readiness</TabsTrigger>
          <TabsTrigger value="reports">Reports</TabsTrigger>
          <TabsTrigger value="roadmap">Roadmap</TabsTrigger>
        </TabsList>

        {/* Overview */}
        <TabsContent value="overview" className="mt-4 space-y-4">
          <DataState state={ov}>
            {(o) => (
              <>
                <div className="flex items-center gap-3 rounded-md border border-border bg-card p-4">
                  <span className="text-sm text-muted-foreground">Overall status</span>
                  <QualityStatusBadge status={o.overall_status} testId="overall-status" />
                </div>
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                  <MetricCard label="Passed" value={o.counters.passed} tone="good" />
                  <MetricCard label="Failed" value={o.counters.failed} tone={o.counters.failed ? "bad" : "good"} />
                  <MetricCard label="Warnings" value={o.counters.warnings} tone="warn" />
                  <MetricCard label="Skipped" value={o.counters.skipped} />
                </div>
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                  <div className="rounded-md border border-border bg-card p-4"><p className="text-[11px] uppercase tracking-wide text-muted-foreground">Latest gate · {titleCase(o.latest_gate.gate)}</p><div className="mt-1.5 flex items-center gap-2"><QualityStatusBadge status={o.latest_gate.status} /><span className="text-[11px] text-muted-foreground">{relativeTime(o.latest_gate.generated_at)}</span></div></div>
                  <div className="rounded-md border border-border bg-card p-4"><p className="text-[11px] uppercase tracking-wide text-muted-foreground">Latest route smoke</p><div className="mt-1.5 flex items-center gap-2"><QualityStatusBadge status={o.latest_route_smoke.status} /><span className="text-[11px] text-muted-foreground">{relativeTime(o.latest_route_smoke.generated_at)}</span></div></div>
                  <div className="rounded-md border border-border bg-card p-4"><p className="text-[11px] uppercase tracking-wide text-muted-foreground">Latest security/audit</p><div className="mt-1.5 flex items-center gap-2"><QualityStatusBadge status={o.latest_security.status} /><span className="text-[11px] text-muted-foreground">{relativeTime(o.latest_security.generated_at)}</span></div></div>
                </div>
                <Panel title="Stale or missing coverage">
                  <ul className="space-y-2">{o.stale_or_missing.map((s, i) => (
                    <li key={i} className="flex items-start gap-2 text-sm"><AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-amber-500" /><span><span className="font-medium">{s.label}</span> — <span className="text-muted-foreground">{s.reason}</span></span></li>
                  ))}</ul>
                </Panel>
                <DataState state={cs}>
                  {(c) => (
                    <Panel title="Current → desired state">
                      <div className="flex items-center gap-2 text-xs"><Pill tone={c.deterministic ? "green" : "amber"}>{c.deterministic ? "Deterministic & controlled" : "Non-deterministic"}</Pill></div>
                      <p className="mt-2 text-sm text-muted-foreground">{c.description}</p>
                      <div className="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div className="rounded-md border border-border bg-muted/20 p-3"><p className="text-[11px] uppercase tracking-wide text-muted-foreground">Current state</p><p className="mt-1 text-sm">{c.current_state}</p></div>
                        <div className="rounded-md border border-border bg-muted/20 p-3"><p className="text-[11px] uppercase tracking-wide text-muted-foreground">Desired state</p><p className="mt-1 text-sm">{c.desired_state}</p></div>
                      </div>
                      <ul className="mt-3 space-y-1 text-xs text-muted-foreground">{c.transition_notes.map((n, i) => <li key={i}>• {n}</li>)}</ul>
                    </Panel>
                  )}
                </DataState>
              </>
            )}
          </DataState>
        </TabsContent>

        {/* Truth Registry */}
        <TabsContent value="truth" className="mt-4">
          <DataState state={rm}>
            {(d) => (
              <Panel dense>
                <div className="border-b border-border bg-muted/20 px-4 py-2 text-xs text-muted-foreground">Feature → domain rules → required tests → risk → evidence. Examples and inferred rules are marked; they are not verified facts.</div>
                <ul className="divide-y divide-border/60">
                  {d.truth.map((t) => (
                    <li key={t.id} className="px-4 py-3" data-testid={`truth-${t.id}`}>
                      <div className="flex flex-wrap items-center gap-2">
                        <span className="text-sm font-semibold">{t.feature}</span>
                        <RiskBadge level={t.risk} />
                        <Pill tone={t.marking === "verified" ? "green" : t.marking === "inferred" ? "blue" : "amber"}>{t.marking === "example" ? "EXAMPLE" : t.marking === "inferred" ? "INFERRED" : "VERIFIED"}</Pill>
                      </div>
                      <div className="mt-2 grid grid-cols-1 gap-2 md:grid-cols-3">
                        <div><p className="text-[11px] uppercase tracking-wide text-muted-foreground">Domain rules</p><ul className="mt-0.5 text-xs text-muted-foreground">{t.domain_rules.map((r, i) => <li key={i}>• {r}</li>)}</ul></div>
                        <div><p className="text-[11px] uppercase tracking-wide text-muted-foreground">Required tests</p><ul className="mt-0.5 font-mono text-xs text-muted-foreground">{t.required_tests.map((r, i) => <li key={i}>{r}</li>)}</ul></div>
                        <div><p className="text-[11px] uppercase tracking-wide text-muted-foreground">Evidence</p><p className="mt-0.5 text-xs text-muted-foreground">{t.evidence}</p></div>
                      </div>
                      <div className="mt-2"><SourceMetaInline source={t.source} /></div>
                    </li>
                  ))}
                </ul>
              </Panel>
            )}
          </DataState>
        </TabsContent>

        {/* Authorization matrix */}
        <TabsContent value="authz" className="mt-4">
          <DataState state={sm}>
            {(d) => (
              <Panel dense action={<span className="px-1 text-[11px] text-muted-foreground">Laravel policies / gates</span>} title="Authorization matrix">
                <div className="overflow-x-auto">
                  <table className="w-full min-w-[760px] text-sm">
                    <thead><tr className="border-b border-border text-left text-[11px] uppercase tracking-wide text-muted-foreground"><th className="px-4 py-2 font-medium">Resource / route</th>{["guest", "user", "admin", "developer", "sysadmin"].map((a) => <th key={a} className="px-3 py-2 font-medium">{a}</th>)}</tr></thead>
                    <tbody>{d.matrix.map((row) => (
                      <tr key={row.resource} className="border-b border-border/60 last:border-0"><td className="px-4 py-2.5 font-mono text-xs">{row.resource}</td>
                        {["guest", "user", "admin", "developer", "sysadmin"].map((a) => { const dec = row.decisions[a] || "unknown"; return <td key={a} className="px-3 py-2.5"><Pill tone={DECISION_TONE[dec]}>{dec.replace(/_/g, " ")}</Pill></td>; })}
                      </tr>
                    ))}</tbody>
                  </table>
                </div>
              </Panel>
            )}
          </DataState>
        </TabsContent>

        {/* Security readiness */}
        <TabsContent value="security" className="mt-4">
          <DataState state={rm}>
            {(d) => (
              <>
                <div className="mb-3 flex flex-wrap gap-2 text-[11px]">
                  {Object.entries(CHECK_STATE).map(([k, v]) => <span key={k} className="flex items-center gap-1.5"><Pill tone={v.tone}>{v.label}</Pill></span>)}
                </div>
                <Panel dense>
                  <table className="w-full text-sm">
                    <thead><tr className="border-b border-border text-left text-[11px] uppercase tracking-wide text-muted-foreground"><th className="px-4 py-2 font-medium">Tool</th><th className="px-3 py-2 font-medium">Category</th><th className="px-3 py-2 font-medium">State</th><th className="px-3 py-2 font-medium">Approval</th><th className="px-3 py-2 font-medium">Last run</th></tr></thead>
                    <tbody>{d.checks.map((c) => (
                      <tr key={c.id} className="border-b border-border/60 last:border-0 hover:bg-accent/30" data-testid={`check-${c.id}`}>
                        <td className="px-4 py-2.5"><p className="font-medium">{c.tool}</p><p className="text-[11px] text-muted-foreground">{c.description}</p></td>
                        <td className="px-3 py-2.5"><Pill tone="slate">{titleCase(c.category)}</Pill></td>
                        <td className="px-3 py-2.5"><Pill tone={CHECK_STATE[c.state].tone}>{CHECK_STATE[c.state].label}</Pill></td>
                        <td className="px-3 py-2.5">{c.destructive ? <Pill tone="red" icon={Ban}>Destructive · human approval</Pill> : c.requires_human_approval ? <Pill tone="amber" icon={Lock}>Approval required</Pill> : <Pill tone="green" icon={CircleCheck}>Auto</Pill>}</td>
                        <td className="px-3 py-2.5 text-xs text-muted-foreground">{c.last_run_at ? relativeTime(c.last_run_at) : <span className="flex items-center gap-1"><Clock className="h-3 w-3" /> never</span>}</td>
                      </tr>
                    ))}</tbody>
                  </table>
                </Panel>
                <p className="mt-3 flex items-center gap-2 text-[11px] text-muted-foreground"><ShieldAlert className="h-3.5 w-3.5 text-amber-500" /> ZAP active scan, Nuclei, Wapiti, Greenbone/OpenVAS, load tests, destructive and email-producing tests never run by default and require explicit human approval.</p>
              </>
            )}
          </DataState>
        </TabsContent>

        {/* Reports history */}
        <TabsContent value="reports" className="mt-4">
          <DataState state={rep} isEmpty={(d) => d.length === 0}>
            {(reports) => (
              <div className="space-y-3">
                {reports.map((r) => (
                  <Panel key={r.tool + r.generated_at} title={<span className="flex items-center gap-2 font-mono">{r.tool}</span>}
                    action={<div className="flex items-center gap-2"><QualityStatusBadge status={r.status} /><span className="text-[11px] text-muted-foreground">{formatDateTime(r.generated_at)}</span></div>}>
                    <div className="mb-3 flex flex-wrap gap-2 text-xs">
                      <Pill tone="slate">total {r.summary.total}</Pill><Pill tone="green">passed {r.summary.passed}</Pill>
                      <Pill tone="red">failed {r.summary.failed}</Pill><Pill tone="amber">warnings {r.summary.warnings}</Pill><Pill tone="slate">skipped {r.summary.skipped}</Pill>
                    </div>
                    {r.findings.length === 0 ? <p className="text-sm text-muted-foreground">No findings.</p> :
                      <ul className="space-y-2">{r.findings.map((f) => (
                        <li key={f.id} className="rounded-md border border-border p-2.5">
                          <div className="flex flex-wrap items-center gap-2"><SeverityBadge severity={f.severity} /><Pill tone="slate">{f.type.replace(/_/g, " ")}</Pill><span className="font-mono text-[11px] text-muted-foreground">{f.route}</span></div>
                          <p className="mt-1.5 text-sm">{f.message}</p>
                          <p className="mt-0.5 text-[11px] text-muted-foreground">expected <span className="font-mono text-foreground">{f.expected}</span> · actual <span className="font-mono text-foreground">{f.actual}</span></p>
                        </li>
                      ))}</ul>}
                  </Panel>
                ))}
              </div>
            )}
          </DataState>
        </TabsContent>

        {/* Roadmap */}
        <TabsContent value="roadmap" className="mt-4">
          <DataState state={rm}>
            {(d) => (
              <ol className="relative ml-3 border-l border-border">
                {d.phases.map((ph) => (
                  <li key={ph.id} className="mb-5 ml-5 last:mb-2" data-testid={`roadmap-${ph.id}`}>
                    <span className={`absolute -left-[7px] h-3.5 w-3.5 rounded-full border-2 border-background ${ph.status === "done" ? "bg-emerald-500" : ph.status === "in_progress" ? "bg-sky-500" : "bg-slate-500"}`} />
                    <div className="flex flex-wrap items-center gap-2"><span className="font-mono text-[11px] text-muted-foreground">{ph.phase}</span><span className="text-sm font-semibold">{ph.title}</span><Pill tone={ph.status === "done" ? "green" : ph.status === "in_progress" ? "blue" : "slate"}>{titleCase(ph.status)}</Pill></div>
                    <ul className="mt-1.5 space-y-0.5 text-xs text-muted-foreground">{ph.items.map((it, i) => <li key={i}>• {it}</li>)}</ul>
                  </li>
                ))}
              </ol>
            )}
          </DataState>
        </TabsContent>
      </Tabs>
    </div>
  );
}
