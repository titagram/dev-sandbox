import React from "react";

export interface AppErrorBoundaryProps {
  children: React.ReactNode;
}

interface AppErrorBoundaryState {
  error: Error | null;
  resetKey: number;
}

export default class AppErrorBoundary extends React.Component<
  AppErrorBoundaryProps,
  AppErrorBoundaryState
> {
  state: AppErrorBoundaryState = {
    error: null,
    resetKey: 0,
  };

  static getDerivedStateFromError(error: Error): Partial<AppErrorBoundaryState> {
    return { error };
  }

  componentDidCatch(_error: Error, { componentStack }: React.ErrorInfo): void {
    console.error("Hades dashboard render error", { componentStack });
  }

  private retry = (): void => {
    this.setState(({ resetKey }) => ({
      error: null,
      resetKey: resetKey + 1,
    }));
  };

  private reload = (): void => {
    window.location.reload();
  };

  render(): React.ReactNode {
    const { error, resetKey } = this.state;

    if (error) {
      const errorId = `HADES-RENDER-${String(resetKey + 1).padStart(3, "0")}`;

      return (
        <div
          role="alert"
          className="grid min-h-screen place-items-center bg-background px-6 py-12 text-center"
        >
          <div className="max-w-md space-y-5">
            <div className="mx-auto grid h-12 w-12 place-items-center rounded-md bg-primary text-lg font-bold text-primary-foreground">
              H
            </div>
            <div className="space-y-2">
              <p className="text-xs font-semibold uppercase tracking-[0.28em] text-primary">Hades Agent</p>
              <h1 className="text-2xl font-semibold tracking-tight">The dashboard needs a reset</h1>
              <p className="text-sm text-muted-foreground">
                We could not render this view safely. Try again or reload the dashboard to continue.
              </p>
              <p className="font-mono text-xs text-muted-foreground">Error ID: {errorId}</p>
            </div>
            <div className="flex flex-wrap justify-center gap-2">
              <button
                type="button"
                onClick={this.retry}
                className="rounded-md bg-primary px-3 py-2 text-sm font-medium text-primary-foreground hover:opacity-90"
              >
                Try again
              </button>
              <button
                type="button"
                onClick={this.reload}
                className="rounded-md border border-border bg-background px-3 py-2 text-sm font-medium text-foreground hover:bg-accent"
              >
                Reload dashboard
              </button>
            </div>
          </div>
        </div>
      );
    }

    return <React.Fragment key={resetKey}>{this.props.children}</React.Fragment>;
  }
}
