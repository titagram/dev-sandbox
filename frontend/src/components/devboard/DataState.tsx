import React from "react";
import { Loader2, Inbox, TriangleAlert, RefreshCw } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { cn } from "@/lib/utils";

export function LoadingState({ rows = 5, label = "Loading…" }: { rows?: number; label?: string }) {
  return (
    <div className="space-y-2" data-testid="loading-state" aria-busy="true">
      <div className="flex items-center gap-2 text-xs text-muted-foreground">
        <Loader2 className="h-3.5 w-3.5 animate-spin" /> {label}
      </div>
      {Array.from({ length: rows }).map((_, i) => (
        <Skeleton key={i} className="h-9 w-full" />
      ))}
    </div>
  );
}

export function EmptyState({ title, hint, icon: Icon = Inbox, action }: { title: string; hint?: string; icon?: any; action?: React.ReactNode }) {
  return (
    <div className="flex flex-col items-center justify-center rounded-md border border-dashed border-border py-14 text-center" data-testid="empty-state">
      <Icon className="mb-3 h-7 w-7 text-muted-foreground/60" />
      <p className="text-sm font-medium">{title}</p>
      {hint && <p className="mt-1 max-w-sm text-xs text-muted-foreground">{hint}</p>}
      {action && <div className="mt-4">{action}</div>}
    </div>
  );
}

export function ErrorState({ message, onRetry }: { message: string; onRetry?: () => void }) {
  return (
    <div className="flex flex-col items-center justify-center rounded-md border border-red-500/30 bg-red-500/5 py-12 text-center" data-testid="error-state">
      <TriangleAlert className="mb-3 h-7 w-7 text-red-500" />
      <p className="text-sm font-medium text-red-600 dark:text-red-400">Failed to load</p>
      <p className="mt-1 max-w-md text-xs text-muted-foreground">{message}</p>
      {onRetry && (
        <Button variant="outline" size="sm" className="mt-4" onClick={onRetry} data-testid="retry-button">
          <RefreshCw className="mr-1.5 h-3.5 w-3.5" /> Retry
        </Button>
      )}
    </div>
  );
}

/** Wraps async state with consistent loading / error / empty / data handling. */
export function DataState<T>({
  state, isEmpty, empty, loadingRows, children, className,
}: {
  state: { data: T | null; loading: boolean; error: string | null; reload: () => void };
  isEmpty?: (d: T) => boolean;
  empty?: React.ReactNode;
  loadingRows?: number;
  children: (d: T) => React.ReactNode;
  className?: string;
}) {
  if (state.loading && !state.data) return <div className={className}><LoadingState rows={loadingRows} /></div>;
  if (state.error) return <div className={className}><ErrorState message={state.error} onRetry={state.reload} /></div>;
  if (!state.data) return <div className={className}><EmptyState title="No data" /></div>;
  if (isEmpty && isEmpty(state.data)) return <div className={className}>{empty || <EmptyState title="Nothing here yet" />}</div>;
  return <div className={cn(state.loading && "opacity-70 transition-opacity", className)}>{children(state.data)}</div>;
}
