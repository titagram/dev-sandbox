import React from "react";
import { cn } from "@/lib/utils";

export function PageHeader({
  title, subtitle, actions, meta, className,
}: { title: React.ReactNode; subtitle?: React.ReactNode; actions?: React.ReactNode; meta?: React.ReactNode; className?: string }) {
  return (
    <div className={cn("flex flex-col gap-3 border-b border-border pb-4 sm:flex-row sm:items-start sm:justify-between", className)}>
      <div className="min-w-0">
        <h1 className="truncate text-xl font-semibold tracking-tight" data-testid="page-title">{title}</h1>
        {subtitle && <p className="mt-1 text-sm text-muted-foreground">{subtitle}</p>}
        {meta && <div className="mt-2">{meta}</div>}
      </div>
      {actions && <div className="flex shrink-0 flex-wrap items-center gap-2">{actions}</div>}
    </div>
  );
}

export function MetricCard({
  label, value, sub, tone = "neutral", testId,
}: { label: string; value: React.ReactNode; sub?: React.ReactNode; tone?: "neutral" | "good" | "warn" | "bad"; testId?: string }) {
  const toneCls = {
    neutral: "text-foreground", good: "text-emerald-600 dark:text-emerald-400",
    warn: "text-amber-600 dark:text-amber-400", bad: "text-red-600 dark:text-red-400",
  }[tone];
  return (
    <div className="rounded-md border border-border bg-card px-3.5 py-3" data-testid={testId}>
      <p className="text-[11px] uppercase tracking-wide text-muted-foreground">{label}</p>
      <p className={cn("mt-1 text-lg font-semibold tabular-nums", toneCls)}>{value}</p>
      {sub && <p className="mt-0.5 text-xs text-muted-foreground">{sub}</p>}
    </div>
  );
}

export function Panel({ title, action, children, className, dense }: { title?: React.ReactNode; action?: React.ReactNode; children: React.ReactNode; className?: string; dense?: boolean }) {
  return (
    <section className={cn("rounded-md border border-border bg-card", className)}>
      {title && (
        <header className="flex items-center justify-between border-b border-border px-4 py-2.5">
          <h2 className="text-sm font-semibold">{title}</h2>
          {action}
        </header>
      )}
      <div className={dense ? "" : "p-4"}>{children}</div>
    </section>
  );
}
