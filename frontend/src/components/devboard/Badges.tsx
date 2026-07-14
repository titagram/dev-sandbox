import React from "react";
import { cn } from "@/lib/utils";
import {
  RiskLevel, RunStatus, SourceStatus, SourceType, PipelineStatus, QualityStatus,
  SourceMeta, Severity,
} from "@/types/devboard";
import { titleCase } from "@/lib/format";
import {
  CircleDot, CircleCheck, CircleX, CircleAlert, Loader2, Clock, FileCheck2,
  GitCommitHorizontal, ScanSearch, History, PenLine, Sparkles, ShieldAlert,
} from "lucide-react";

type Tone = "green" | "amber" | "red" | "blue" | "violet" | "slate" | "teal";

const TONE: Record<Tone, string> = {
  green: "text-emerald-600 dark:text-emerald-400 bg-emerald-500/10 border-emerald-500/25",
  amber: "text-amber-600 dark:text-amber-400 bg-amber-500/10 border-amber-500/25",
  red: "text-red-600 dark:text-red-400 bg-red-500/10 border-red-500/25",
  blue: "text-sky-600 dark:text-sky-400 bg-sky-500/10 border-sky-500/25",
  violet: "text-violet-600 dark:text-violet-400 bg-violet-500/10 border-violet-500/25",
  slate: "text-slate-600 dark:text-slate-300 bg-slate-500/10 border-slate-500/25",
  teal: "text-teal-600 dark:text-teal-300 bg-teal-500/10 border-teal-500/25",
};

export function Pill({
  tone, children, icon: Icon, className, testId,
}: { tone: Tone; children: React.ReactNode; icon?: any; className?: string; testId?: string }) {
  return (
    <span
      data-testid={testId}
      className={cn(
        "inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-[11px] font-medium leading-none whitespace-nowrap",
        TONE[tone], className,
      )}
    >
      {Icon && <Icon className="h-3 w-3 shrink-0" />}
      {children}
    </span>
  );
}

const RISK_TONE: Record<RiskLevel, Tone> = { low: "slate", medium: "amber", high: "red", critical: "red" };
export const RiskBadge = ({ level, testId }: { level: RiskLevel; testId?: string }) => (
  <Pill tone={RISK_TONE[level]} className={level === "critical" ? "font-semibold uppercase tracking-wide" : ""} testId={testId}>
    {titleCase(level)}
  </Pill>
);

const RUN_TONE: Record<RunStatus, Tone> = {
  queued: "slate", running: "blue", passed: "green", failed: "red",
  needs_review: "amber", reviewed: "teal", cancelled: "slate",
};
const RUN_ICON: Record<RunStatus, any> = {
  queued: Clock, running: Loader2, passed: CircleCheck, failed: CircleX,
  needs_review: CircleAlert, reviewed: FileCheck2, cancelled: CircleX,
};
export const RunStatusBadge = ({ status, testId }: { status: RunStatus | null; testId?: string }) => {
  if (!status) return <Pill tone="slate" testId={testId}>no run</Pill>;
  const Icon = RUN_ICON[status];
  return (
    <Pill tone={RUN_TONE[status]} icon={Icon} testId={testId}
      className={status === "running" ? "[&_svg]:animate-spin" : ""}>
      {titleCase(status)}
    </Pill>
  );
};

const SS_TONE: Record<SourceStatus, Tone> = {
  verified_from_code: "green", developer_provided: "blue", ai_generated: "violet",
  needs_verification: "amber", stale: "slate", conflict_with_code: "red",
};
export const SourceStatusBadge = ({ status, testId }: { status: SourceStatus; testId?: string }) => (
  <Pill tone={SS_TONE[status]} testId={testId}
    icon={status === "conflict_with_code" ? ShieldAlert : status === "ai_generated" ? Sparkles : undefined}>
    {titleCase(status)}
  </Pill>
);

const ST_ICON: Record<SourceType, any> = {
  local_plugin_snapshot: GitCommitHorizontal, local_plugin_diff: GitCommitHorizontal,
  local_analyzer: ScanSearch, server_history: History, user_manual: PenLine, ai_generated: Sparkles,
};
const PIPE_TONE: Record<PipelineStatus, Tone> = {
  not_started: "slate", pending: "slate", in_progress: "blue",
  complete: "green", stale: "amber", failed: "red",
};
export const PipelineBadge = ({ status, label, testId }: { status: PipelineStatus; label?: string; testId?: string }) => (
  <Pill tone={PIPE_TONE[status]} testId={testId}
    className={status === "in_progress" ? "[&_svg]:animate-spin" : ""}
    icon={status === "in_progress" ? Loader2 : status === "complete" ? CircleCheck : status === "failed" ? CircleX : status === "stale" ? CircleAlert : CircleDot}>
    {label ? `${label}: ` : ""}{titleCase(status)}
  </Pill>
);

const Q_TONE: Record<QualityStatus, Tone> = { pass: "green", fail: "red", warning: "amber" };
export const QualityStatusBadge = ({ status, testId }: { status: QualityStatus; testId?: string }) => (
  <Pill tone={Q_TONE[status]} testId={testId}
    icon={status === "pass" ? CircleCheck : status === "fail" ? CircleX : CircleAlert}>
    {status.toUpperCase()}
  </Pill>
);

const SEV_TONE: Record<Severity, Tone> = { info: "slate", low: "blue", medium: "amber", high: "red", critical: "red" };
export const SeverityBadge = ({ severity }: { severity: Severity }) => (
  <Pill tone={SEV_TONE[severity]} className={severity === "critical" ? "font-semibold uppercase" : ""}>
    {titleCase(severity)}
  </Pill>
);

/** Inline source provenance — every technical fact must show this. */
export function SourceMetaInline({ source, className }: { source: SourceMeta; className?: string }) {
  const Icon = ST_ICON[source.type];
  return (
    <span className={cn("inline-flex items-center gap-1.5 text-[11px] text-muted-foreground", className)}>
      <Icon className="h-3 w-3 shrink-0" />
      <span className="font-mono">{titleCase(source.type)}</span>
      <span className="opacity-50">·</span>
      <SourceStatusBadge status={source.status} />
      <span className="opacity-50">·</span>
      <span className="truncate">{source.origin}</span>
    </span>
  );
}
