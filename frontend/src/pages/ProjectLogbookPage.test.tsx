import React, { act } from "react";
import { createRoot, Root } from "react-dom/client";

declare const jest: any;
declare const beforeEach: any;
declare const afterEach: any;
declare const describe: any;
declare const it: any;
declare const expect: any;

(globalThis as any).IS_REACT_ACT_ENVIRONMENT = true;

jest.mock("@/api/devboardApi", () => ({
  api: { getProjectLogbook: require("jest-mock").fn(), createProjectLogbookNote: require("jest-mock").fn() },
}), { virtual: true });
jest.mock("@/hooks/useApi", () => {
  const React = require("react");
  return {
    useApi: (fn: () => Promise<unknown>, dependencies: unknown[] = []) => {
      const [state, setState] = React.useState({ data: null, loading: true, error: null });
      React.useEffect(() => {
        let active = true;
        fn().then(
          (data: unknown) => active && setState({ data, loading: false, error: null }),
          (error: any) => active && setState({ data: null, loading: false, error: error?.message || String(error) }),
        );
        return () => { active = false; };
      }, dependencies);
      return { ...state, reload: require("jest-mock").fn() };
    },
  };
}, { virtual: true });
jest.mock("@/context/AuthContext", () => ({ useAuth: () => ({ user: { role: (globalThis as any).__logbookTestRole } }) }), { virtual: true });
jest.mock("react-router-dom", () => ({
  Link: ({ children, to, ...props }: any) => <a href={to} {...props}>{children}</a>,
  useParams: () => ({ projectId: (globalThis as any).__logbookTestProjectId }),
  useNavigate: () => require("jest-mock").fn(),
}), { virtual: true });
jest.mock("@/components/devboard/Layout", () => ({
  PageHeader: ({ title, subtitle, actions }: any) => <header><h1>{title}</h1>{subtitle}{actions}</header>,
  Panel: ({ title, children }: any) => <section>{title && <h2>{title}</h2>}{children}</section>,
}), { virtual: true });
jest.mock("@/components/devboard/DataState", () => ({
  DataState: ({ state, children, empty }: any) => state.loading ? <div>Loading</div> : state.error ? <div>{state.error}</div> : state.data?.items?.length ? children(state.data) : empty,
  LoadingState: ({ label }: any) => <div>{label}</div>,
  ErrorState: ({ message }: any) => <div>{message}</div>,
  EmptyState: ({ title }: any) => <div>{title}</div>,
}), { virtual: true });
jest.mock("@/components/ui/button", () => ({ Button: ({ children, ...props }: any) => <button {...props}>{children}</button> }), { virtual: true });
jest.mock("@/components/ui/input", () => ({ Input: (props: any) => <input {...props} /> }), { virtual: true });
jest.mock("@/components/ui/select", () => ({
  Select: ({ children }: any) => <div>{children}</div>, SelectContent: ({ children }: any) => <div>{children}</div>,
  SelectItem: ({ children }: any) => <div>{children}</div>, SelectTrigger: ({ children, ...props }: any) => <button {...props}>{children}</button>, SelectValue: () => null,
}), { virtual: true });
jest.mock("@/components/ui/textarea", () => ({ Textarea: (props: any) => <textarea {...props} /> }), { virtual: true });
jest.mock("@/components/ui/dialog", () => ({ Dialog: ({ children }: any) => <>{children}</>, DialogContent: ({ children }: any) => <div>{children}</div>, DialogHeader: ({ children }: any) => <header>{children}</header>, DialogTitle: ({ children }: any) => <h2>{children}</h2>, DialogDescription: ({ children }: any) => <p>{children}</p>, DialogFooter: ({ children }: any) => <footer>{children}</footer> }), { virtual: true });
jest.mock("@/lib/format", () => ({ relativeTime: () => "just now", formatDateTime: () => "Jul 21, 2026" }), { virtual: true });
jest.mock("sonner", () => ({ toast: { error: require("jest-mock").fn(), success: require("jest-mock").fn() } }), { virtual: true });
jest.mock("lucide-react", () => new Proxy({}, { get: () => () => null }), { virtual: true });

import { api } from "@/api/devboardApi";
import ProjectLogbookPage from "./ProjectLogbookPage";

const page = {
  project_id: "project-1",
  next_cursor: null,
  items: [{
    id: "log-1", project_id: "project-1", occurred_at: "2026-07-21T10:00:00Z", recorded_at: "2026-07-21T10:01:00Z",
    actor: { kind: "agent", label: "Hades Agent", user_id: null, agent_id: "hades", device_id: null, role: null, model: null },
    event_type: "import", severity: "info", summary: "Imported Symfony Demo graph",
    narrative_markdown: "Imported **canonical** graph.", references: [
      { kind: "wiki_page", id: "wiki-architecture" },
      { kind: "commit", id: "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa" },
    ], correlation_id: "corr-1", payload: { request_sha256: "evidence-hash", nodes: 42 }, supersedes_entry_id: null,
  }],
};

let container: HTMLDivElement;
let root: Root;

async function settle(): Promise<void> {
  await act(async () => { await new Promise((resolve) => setTimeout(resolve, 0)); });
}

function deferred<T>() {
  let resolve!: (value: T) => void;
  let reject!: (reason?: unknown) => void;
  const promise = new Promise<T>((promiseResolve, promiseReject) => {
    resolve = promiseResolve;
    reject = promiseReject;
  });
  return { promise, resolve, reject };
}

function button(label: string): HTMLButtonElement {
  const match = Array.from(container.querySelectorAll("button")).find((candidate) => candidate.textContent?.includes(label));
  if (!match) throw new Error(`Button not found: ${label}`);
  return match as HTMLButtonElement;
}

async function click(element: HTMLElement): Promise<void> {
  await act(async () => { element.click(); });
  await settle();
}

async function changeInput(element: HTMLInputElement | HTMLTextAreaElement, value: string): Promise<void> {
  const prototype = element instanceof HTMLTextAreaElement ? HTMLTextAreaElement.prototype : HTMLInputElement.prototype;
  const setter = Object.getOwnPropertyDescriptor(prototype, "value")?.set;
  await act(async () => {
    setter?.call(element, value);
    element.dispatchEvent(new Event("input", { bubbles: true }));
  });
  await settle();
}

function entry(id: string, summary: string, narrative = "Narrative"): typeof page.items[number] {
  return {
    ...page.items[0], id, summary, narrative_markdown: narrative,
    recorded_at: `2026-07-21T10:${id === "log-1" ? "01" : "02"}:00Z`,
  };
}

describe("ProjectLogbookPage", () => {
  beforeEach(() => {
    (globalThis as any).__logbookTestRole = "developer";
    (globalThis as any).__logbookTestProjectId = "project-1";
    container = document.createElement("div");
    document.body.appendChild(container);
    root = createRoot(container);
    (api.getProjectLogbook as any).mockReset().mockResolvedValue(page);
  });
  afterEach(() => { act(() => root.unmount()); container.remove(); });

  it("renders readable timeline data with technical payload collapsed", async () => {
    await act(async () => { root.render(<ProjectLogbookPage />); });
    await settle();

    expect(container.textContent).toContain("Imported Symfony Demo graph");
    expect(container.textContent).toContain("Hades Agent");
    expect(container.textContent).not.toContain("request_sha256");
    expect(container.querySelector("a[href='/projects/project-1/wiki/wiki-architecture']")).toBeTruthy();
    expect(container.querySelector("a[href*='aaaaaaaa']")).toBeNull();
    const evidence = Array.from(container.querySelectorAll("button")).find((button) => button.textContent?.includes("Technical evidence")) as HTMLButtonElement;
    await act(async () => { evidence.click(); });
    expect(container.textContent).toContain("request_sha256");
  });

  it("ignores a late load-more response after the active filters change", async () => {
    const latePage = deferred<typeof page>();
    (api.getProjectLogbook as any)
      .mockReset()
      .mockResolvedValueOnce({ ...page, next_cursor: "cursor-1" })
      .mockImplementationOnce(() => latePage.promise)
      .mockResolvedValueOnce({ ...page, next_cursor: null, items: [entry("log-filtered", "Filtered current result")] });

    await act(async () => { root.render(<ProjectLogbookPage />); });
    await settle();
    await click(button("Load more"));
    await changeInput(container.querySelector("[data-testid='logbook-query']") as HTMLInputElement, "current filter");

    expect(api.getProjectLogbook).toHaveBeenLastCalledWith("project-1", expect.objectContaining({ q: "current filter", limit: 20 }));
    latePage.resolve({ ...page, next_cursor: null, items: [entry("log-stale", "Stale previous result")] });
    await settle();

    expect(container.textContent).toContain("Filtered current result");
    expect(container.textContent).not.toContain("Stale previous result");
  });

  it("ignores a late load-more response after the selected project changes", async () => {
    const latePage = deferred<typeof page>();
    (api.getProjectLogbook as any)
      .mockReset()
      .mockResolvedValueOnce({ ...page, next_cursor: "cursor-1" })
      .mockImplementationOnce(() => latePage.promise)
      .mockResolvedValueOnce({
        ...page,
        project_id: "project-2",
        next_cursor: null,
        items: [{ ...entry("log-project-2", "Project two current result"), project_id: "project-2" }],
      });

    await act(async () => { root.render(<ProjectLogbookPage />); });
    await settle();
    await click(button("Load more"));
    (globalThis as any).__logbookTestProjectId = "project-2";
    await act(async () => { root.render(<ProjectLogbookPage />); });
    await settle();

    expect(api.getProjectLogbook).toHaveBeenLastCalledWith("project-2", expect.objectContaining({ limit: 20 }));
    latePage.resolve({ ...page, next_cursor: null, items: [entry("log-stale", "Stale project one result")] });
    await settle();

    expect(container.textContent).toContain("Project two current result");
    expect(container.textContent).not.toContain("Stale project one result");
  });

  it("reloads the filtered first page after adding a note instead of injecting a non-matching entry", async () => {
    const filtered = { ...page, next_cursor: null, items: [entry("log-filtered", "Existing filtered result")] };
    const created = entry("log-note", "A note outside the filter");
    (api.getProjectLogbook as any).mockReset()
      .mockResolvedValueOnce(page)
      .mockResolvedValueOnce(filtered)
      .mockResolvedValueOnce(filtered);
    (api.createProjectLogbookNote as any).mockReset().mockResolvedValue({ entry: created, replayed: false });

    await act(async () => { root.render(<ProjectLogbookPage />); });
    await settle();
    await changeInput(container.querySelector("[data-testid='logbook-query']") as HTMLInputElement, "filtered");
    await click(button("Add note"));
    await changeInput(container.querySelector("input[placeholder='What should the project remember?']") as HTMLInputElement, "A note outside the filter");
    await changeInput(container.querySelector("textarea[placeholder='Optional Markdown context']") as HTMLTextAreaElement, "Context");
    await click(button("Save note"));

    expect(api.getProjectLogbook).toHaveBeenCalledTimes(3);
    expect(api.getProjectLogbook).toHaveBeenLastCalledWith("project-1", expect.objectContaining({ q: "filtered", limit: 20 }));
    expect(container.textContent).toContain("Existing filtered result");
    expect(container.textContent).not.toContain("A note outside the filter");
  });

  it("keeps note input and exposes an accessible error when POST fails, then permits retry", async () => {
    const created = entry("log-note", "Retryable note");
    (api.createProjectLogbookNote as any).mockReset()
      .mockRejectedValueOnce({ message: "The note could not be saved." })
      .mockResolvedValueOnce({ entry: created, replayed: false });
    (api.getProjectLogbook as any).mockReset()
      .mockResolvedValueOnce(page)
      .mockResolvedValueOnce({ ...page, items: [created, ...page.items] });

    await act(async () => { root.render(<ProjectLogbookPage />); });
    await settle();
    await click(button("Add note"));
    const summary = container.querySelector("input[placeholder='What should the project remember?']") as HTMLInputElement;
    const narrative = container.querySelector("textarea[placeholder='Optional Markdown context']") as HTMLTextAreaElement;
    await changeInput(summary, "Retryable note");
    await changeInput(narrative, "Keep this Markdown");
    await click(button("Save note"));

    expect(container.querySelector("[role='alert']")?.textContent).toContain("The note could not be saved.");
    expect((container.querySelector("#logbook-note-summary") as HTMLInputElement).value).toBe("Retryable note");
    expect((container.querySelector("#logbook-note-narrative") as HTMLTextAreaElement).value).toBe("Keep this Markdown");

    await click(button("Save note"));
    expect(api.createProjectLogbookNote).toHaveBeenCalledTimes(2);
    expect(container.querySelector("#logbook-note-summary")).toBeNull();
    expect(container.textContent).toContain("Retryable note");
  });

  it("provides programmatic labels for search, note summary, and note narrative", async () => {
    await act(async () => { root.render(<ProjectLogbookPage />); });
    await settle();

    expect(container.querySelector("label[for='logbook-search']")?.textContent).toContain("Search");
    expect(container.querySelector("#logbook-search")).toBeTruthy();
    await click(button("Add note"));
    expect(container.querySelector("label[for='logbook-note-summary']")?.textContent).toContain("Summary");
    expect(container.querySelector("label[for='logbook-note-narrative']")?.textContent).toContain("Narrative");
  });

  it("deduplicates cursor pages by immutable entry id", async () => {
    (api.getProjectLogbook as any).mockReset()
      .mockResolvedValueOnce({ ...page, next_cursor: "cursor-1" })
      .mockResolvedValueOnce({ ...page, next_cursor: null, items: [page.items[0], entry("log-2", "Second page entry")] });

    await act(async () => { root.render(<ProjectLogbookPage />); });
    await settle();
    await click(button("Load more"));

    expect(container.querySelectorAll("[data-testid='logbook-entry-log-1']")).toHaveLength(1);
    expect(container.querySelectorAll("[data-testid='logbook-entry-log-2']")).toHaveLength(1);
  });

  it("shows Add note only for dashboard roles allowed by the mutation contract", async () => {
    (globalThis as any).__logbookTestRole = "sysadmin";
    await act(async () => { root.render(<ProjectLogbookPage />); });
    await settle();
    expect(container.textContent).not.toContain("Add note");

    (globalThis as any).__logbookTestRole = "developer";
    await act(async () => { root.render(<ProjectLogbookPage />); });
    expect(container.textContent).toContain("Add note");
  });

  it("renders hostile Markdown without executable HTML or unsafe links", async () => {
    (api.getProjectLogbook as any).mockReset().mockResolvedValue({
      ...page,
      items: [entry("log-hostile", "Untrusted narrative", '<script>alert(1)</script><img src=x onerror=alert(1)> [unsafe](javascript:alert(1)) [safe](https://example.test/docs)')],
    });

    await act(async () => { root.render(<ProjectLogbookPage />); });
    await settle();

    expect(container.querySelector("script")).toBeNull();
    expect(container.querySelector("img")).toBeNull();
    expect(container.querySelector("a[href^='javascript:']")).toBeNull();
    expect(container.querySelector("a[href='https://example.test/docs']")).toBeTruthy();
  });
});
