import React from "react";
import { Link, useParams, useNavigate } from "react-router-dom";
import { api } from "@/api/devboardApi";
import { useApi } from "@/hooks/useApi";
import { PageHeader, Panel } from "@/components/devboard/Layout";
import { DataState } from "@/components/devboard/DataState";
import { QualityStatusBadge, SeverityBadge, Pill } from "@/components/devboard/Badges";
import { GateName, QualityReportFinding } from "@/types/devboard";
import { ChevronLeft, CircleCheck, CircleX, ShieldAlert } from "lucide-react";
import { formatDateTime, titleCase } from "@/lib/format";
import { cn } from "@/lib/utils";

const GATES: GateName[] = ["pull_request", "nightly", "release"];

function FindingList({ findings, empty }: { findings: QualityReportFinding[]; empty: string }) {
  if (findings.length === 0) return <p className="p-4 text-sm text-muted-foreground">{empty}</p>;
  return (
    <ul className="divide-y divide-border/60">
      {findings.map((f) => (
        <li key={f.id} className="px-4 py-3">
          <div className="flex flex-wrap items-center gap-2"><SeverityBadge severity={f.severity} /><Pill tone="slate">{f.type.replace(/_/g, " ")}</Pill><span className="font-mono text-[11px] text-muted-foreground">{f.route}</span></div>
          <p className="mt-1.5 text-sm">{f.message}</p>
          <p className="mt-0.5 text-[11px] text-muted-foreground">expected <span className="font-mono text-foreground">{f.expected}</span> · actual <span className="font-mono text-foreground">{f.actual}</span></p>
        </li>
      ))}
    </ul>
  );
}

export default function QualityGatePage() {
  const { gate } = useParams();
  const nav = useNavigate();
  const current = (gate as GateName) || "pull_request";
  const state = useApi(() => api.getQualityGate(current), [current]);

  return (
    <div className="space-y-4" data-testid="quality-gate-page">
      <Link to="/quality" className="inline-flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground"><ChevronLeft className="h-3.5 w-3.5" /> Quality Center</Link>
      <PageHeader title="Quality Gate Report" subtitle="Blocking findings stop the gate. Warnings are surfaced. Risky operations require explicit human approval." />

      <div className="flex flex-wrap gap-2">
        {GATES.map((g) => (
          <button key={g} onClick={() => nav(`/quality/gates/${g}`)} data-testid={`gate-tab-${g}`}
            className={cn("rounded-md border px-3 py-1.5 text-sm font-medium transition-colors", current === g ? "border-primary bg-primary/10 text-primary" : "border-border hover:bg-accent")}>
            {titleCase(g)}
          </button>
        ))}
      </div>

      <DataState state={state}>
        {(g) => (
          <>
            <div className="flex flex-wrap items-center gap-3 rounded-md border border-border bg-card p-4">
              <span className="text-sm font-semibold">{titleCase(g.gate)} gate</span>
              <QualityStatusBadge status={g.status} testId="gate-status" />
              <span className="text-[11px] text-muted-foreground">generated {formatDateTime(g.generated_at)}</span>
            </div>

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
              <Panel title={<span className="flex items-center gap-2"><CircleX className="h-4 w-4 text-red-500" /> Blocking findings ({g.blocking_findings.length})</span>} dense>
                <FindingList findings={g.blocking_findings} empty="No blocking findings — gate is not blocked." />
              </Panel>
              <Panel title={<span className="flex items-center gap-2"><ShieldAlert className="h-4 w-4 text-amber-500" /> Warnings ({g.warnings.length})</span>} dense>
                <FindingList findings={g.warnings} empty="No warnings." />
              </Panel>
            </div>

            <Panel title="Human approvals required" dense>
              {g.human_approvals_required.length === 0
                ? <p className="p-4 text-sm text-muted-foreground">No human approvals required for this gate.</p>
                : <ul className="divide-y divide-border/60">{g.human_approvals_required.map((a) => (
                    <li key={a.id} className="flex items-center justify-between px-4 py-2.5 text-sm">
                      <span>{a.label}</span>
                      {a.approved ? <Pill tone="green" icon={CircleCheck}>Approved</Pill> : <Pill tone="amber">Awaiting approval</Pill>}
                    </li>
                  ))}</ul>}
            </Panel>
          </>
        )}
      </DataState>
    </div>
  );
}
