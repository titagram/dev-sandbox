## Task 1: Add the global Hades render-error boundary

**Files:**

- Create `frontend/src/components/devboard/AppErrorBoundary.tsx`.
- Create `frontend/src/components/devboard/AppErrorBoundary.test.tsx`.
- Modify `frontend/src/index.js` around `ReactDOM.createRoot`, `React.StrictMode`, and `QueryClientProvider`.

**Consumed/produced interfaces:**

    export interface AppErrorBoundaryProps { children: React.ReactNode }
    interface AppErrorBoundaryState { error: Error | null; resetKey: number }

The component produces a Hades-branded fallback with `role="alert"`, a safe human-readable error id derived from `resetKey` rather than the exception message, a `Try again` button that clears `error` and increments `resetKey`, and a `Reload dashboard` button that calls `window.location.reload()`. It must not render an exception stack or untrusted exception text.

- [ ] Add the jsdom regression test with a child that throws on the first render, assert the alert and Hades branding are visible, click `Try again`, and assert the healthy child is visible after the boundary reset.
- [ ] Run the RED command and record the expected failure: `cd frontend && CI=true corepack yarn test --watchAll=false --runInBand src/components/devboard/AppErrorBoundary.test.tsx`; expected failure is that the new test file/component cannot be resolved.
- [ ] Implement `AppErrorBoundary` with `static getDerivedStateFromError`, `componentDidCatch` logging only a bounded `console.error("Hades dashboard render error", { componentStack })`, and a reset button that remounts children with `key={resetKey}`.
- [ ] Wrap `<QueryClientProvider client={queryClient}><App /></QueryClientProvider>` with `<AppErrorBoundary>` inside `React.StrictMode` in `frontend/src/index.js`, keeping the existing `AuthProvider`/router ownership inside `App` and avoiding duplicate providers.
- [ ] Run the GREEN command: `cd frontend && CI=true corepack yarn test --watchAll=false --runInBand src/components/devboard/AppErrorBoundary.test.tsx`; expected result is one passing jsdom regression test with no uncaught render exception.
- [ ] Run the focused suite: `cd frontend && CI=true corepack yarn test --watchAll=false --runInBand src/components/devboard/AppErrorBoundary.test.tsx src/components/devboard/Badges.test.tsx`; expected result is both boundary tests and both existing source metadata tests passing.
- [ ] Check the exact diff with `git diff --check -- frontend/src/index.js frontend/src/components/devboard/AppErrorBoundary.tsx frontend/src/components/devboard/AppErrorBoundary.test.tsx`.
- [ ] Prepare the task commit command, without executing it during planning: `git add frontend/src/index.js frontend/src/components/devboard/AppErrorBoundary.tsx frontend/src/components/devboard/AppErrorBoundary.test.tsx && git commit -m "feat(frontend): add branded render error boundary"`.

