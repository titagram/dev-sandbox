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
  api: {
    getWiki: require("jest-mock").fn(),
    getProject: require("jest-mock").fn(),
    getWikiRefreshRequests: require("jest-mock").fn(),
    getProjectWorkspaceBindings: require("jest-mock").fn(),
  },
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
          (error: unknown) => active && setState({ data: null, loading: false, error }),
        );
        return () => { active = false; };
      }, dependencies);
      return { ...state, reload: require("jest-mock").fn() };
    },
  };
}, { virtual: true });

jest.mock("@/context/AuthContext", () => ({ useAuth: () => ({ user: { role: "admin" } }) }), { virtual: true });
jest.mock("react-router-dom", () => ({
  Link: ({ children, to, ...props }: any) => <a href={to} {...props}>{children}</a>,
  useNavigate: () => require("jest-mock").fn(),
  useParams: () => ({ projectId: "project-1" }),
}), { virtual: true });
jest.mock("@/components/devboard/DataState", () => ({
  DataState: ({ state, children }: any) => state.data ? children(state.data) : <div>Loading</div>,
}), { virtual: true });
jest.mock("@/components/devboard/Layout", () => ({
  PageHeader: ({ title, subtitle, actions, meta }: any) => <header><h1>{title}</h1>{subtitle}{meta}{actions}</header>,
  Panel: ({ title, children }: any) => <section>{title && <h2>{title}</h2>}{children}</section>,
}), { virtual: true });
jest.mock("@/components/devboard/Badges", () => ({
  SourceStatusBadge: ({ status }: any) => <span>{status}</span>,
  SourceMetaInline: ({ source }: any) => <span>{source.type}</span>,
  Pill: ({ children }: any) => <span>{children}</span>,
}), { virtual: true });
jest.mock("@/components/ui/button", () => ({
  Button: ({ children, ...props }: any) => <button {...props}>{children}</button>,
}), { virtual: true });
jest.mock("@/components/ui/input", () => ({
  Input: (props: any) => <input {...props} />,
}), { virtual: true });
jest.mock("@/components/ui/textarea", () => ({
  Textarea: (props: any) => <textarea {...props} />,
}), { virtual: true });
jest.mock("@/components/ui/label", () => ({ Label: ({ children, ...props }: any) => <label {...props}>{children}</label> }), { virtual: true });
jest.mock("@/components/ui/select", () => ({
  Select: ({ children }: any) => <div>{children}</div>,
  SelectContent: ({ children }: any) => <div>{children}</div>,
  SelectItem: ({ children }: any) => <div>{children}</div>,
  SelectTrigger: ({ children, ...props }: any) => <button {...props}>{children}</button>,
  SelectValue: () => null,
}), { virtual: true });
jest.mock("@/components/ui/dialog", () => {
  const React = require("react");
  const OpenContext = React.createContext(false);
  return {
    Dialog: ({ open, children }: any) => <OpenContext.Provider value={open}>{children}</OpenContext.Provider>,
    DialogContent: ({ children, ...props }: any) => React.useContext(OpenContext) ? <div {...props}>{children}</div> : null,
    DialogDescription: ({ children }: any) => <p>{children}</p>,
    DialogFooter: ({ children }: any) => <footer>{children}</footer>,
    DialogHeader: ({ children }: any) => <header>{children}</header>,
    DialogTitle: ({ children }: any) => <h2>{children}</h2>,
  };
}, { virtual: true });
jest.mock("@/lib/format", () => ({ relativeTime: (value: string) => value }), { virtual: true });
jest.mock("sonner", () => ({ toast: { error: require("jest-mock").fn(), success: require("jest-mock").fn() } }), { virtual: true });
jest.mock("lucide-react", () => new Proxy({}, { get: () => () => null }), { virtual: true });

import { api } from "@/api/devboardApi";
import WikiPage from "./WikiPage";

const pages = [
  {
    id: "wiki-architecture",
    title: "Architecture",
    project_id: "project-1",
    category: "technical",
    page_type: "technical",
    audience: "engineering",
    source_status: "needs_verification",
    has_evidence: false,
    updated_at: "2026-07-15T12:00:00Z",
    source: { type: "user_manual", status: "needs_verification", origin: "manual", generated_at: "2026-07-15T12:00:00Z" },
  },
  {
    id: "wiki-runbook",
    title: "Deploy runbook",
    project_id: "project-1",
    category: "runbook",
    page_type: "runbook",
    audience: "operations",
    source_status: "verified_from_code",
    has_evidence: true,
    updated_at: "2026-07-14T12:00:00Z",
    source: { type: "local_analyzer", status: "verified_from_code", origin: "analyzer", generated_at: "2026-07-14T12:00:00Z" },
  },
];

let container: HTMLDivElement;
let root: Root;

async function settle(): Promise<void> {
  await act(async () => { await new Promise((resolve) => setTimeout(resolve, 0)); });
}

describe("WikiPage index controls", () => {
  beforeEach(() => {
    container = document.createElement("div");
    document.body.appendChild(container);
    root = createRoot(container);
    (api.getWiki as any).mockReset().mockResolvedValue(pages);
    (api.getProject as any).mockReset().mockResolvedValue({ id: "project-1", status: "active" });
    (api.getWikiRefreshRequests as any).mockReset().mockResolvedValue([]);
    (api.getProjectWorkspaceBindings as any).mockReset().mockResolvedValue([]);
  });

  afterEach(() => {
    act(() => root.unmount());
    container.remove();
  });

  it("exposes searchable audience/page-type/source filters and the verification queue", async () => {
    await act(async () => { root.render(<WikiPage />); });
    await settle();
    await settle();

    expect(container.querySelector("[data-testid='wiki-verification-queue']")?.textContent).toContain("1");
    expect(container.querySelector("[data-testid='wiki-search-input']")).toBeTruthy();
    expect(container.querySelector("[data-testid='filter-audience']")).toBeTruthy();
    expect(container.querySelector("[data-testid='filter-page-type']")).toBeTruthy();
    expect(container.querySelector("[data-testid='filter-source']")).toBeTruthy();
    const refreshPanel = container.querySelector("[data-testid='wiki-refresh-panel']") as HTMLDetailsElement;
    expect(refreshPanel).toBeTruthy();
    expect(refreshPanel.open).toBe(false);
    expect(refreshPanel.textContent).toContain("Run a bounded Hades populate job");
    expect(container.textContent).toContain("Architecture");
    expect(container.textContent).toContain("Deploy runbook");

    const createButton = container.querySelector("[data-testid='create-wiki-page-btn']") as HTMLButtonElement;
    await act(async () => { createButton.click(); });
    expect(container.querySelector("[data-testid='wiki-source-status-select']")).toBeNull();
    expect(container.textContent).toContain("verification queue");
  });
});
