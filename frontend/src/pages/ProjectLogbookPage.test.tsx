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
jest.mock("@/context/AuthContext", () => ({ useAuth: () => ({ user: { role: "developer" } }) }), { virtual: true });
jest.mock("react-router-dom", () => ({
  Link: ({ children, to, ...props }: any) => <a href={to} {...props}>{children}</a>,
  useParams: () => ({ projectId: "project-1" }),
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

describe("ProjectLogbookPage", () => {
  beforeEach(() => {
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
});
