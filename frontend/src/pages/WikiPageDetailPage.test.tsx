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
    getWikiPage: require("jest-mock").fn(),
    getProject: require("jest-mock").fn(),
    updateWikiPage: require("jest-mock").fn(),
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
          (data) => active && setState({ data, loading: false, error: null }),
          (error) => active && setState({ data: null, loading: false, error }),
        );
        return () => { active = false; };
      }, dependencies);
      return { ...state, reload: require("jest-mock").fn() };
    },
  };
}, { virtual: true });

jest.mock("@/context/AuthContext", () => ({
  useAuth: () => ({ user: { role: "admin" } }),
}), { virtual: true });

jest.mock("react-router-dom", () => ({
  Link: ({ children, to }: any) => <a href={to}>{children}</a>,
  useNavigate: () => require("jest-mock").fn(),
  useParams: () => ({ projectId: "project-1", pageId: "wiki-1" }),
}), { virtual: true });

jest.mock("@/components/devboard/DataState", () => ({
  DataState: ({ state, children }: any) => state.data ? children(state.data) : <div>Loading</div>,
}), { virtual: true });

jest.mock("@/components/devboard/Layout", () => ({
  PageHeader: ({ title, meta, actions }: any) => <header><h1>{title}</h1>{meta}{actions}</header>,
  Panel: ({ title, children }: any) => <section><h2>{title}</h2>{children}</section>,
}), { virtual: true });

jest.mock("@/components/devboard/Badges", () => ({
  SourceStatusBadge: ({ status }: any) => <span>{status}</span>,
  SourceMetaInline: () => <span>Source</span>,
  Pill: ({ children }: any) => <span>{children}</span>,
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

jest.mock("@/components/ui/select", () => ({
  Select: ({ children }: any) => <div>{children}</div>,
  SelectContent: ({ children }: any) => <div>{children}</div>,
  SelectItem: ({ children }: any) => <div>{children}</div>,
  SelectTrigger: ({ children, ...props }: any) => <button {...props}>{children}</button>,
  SelectValue: () => null,
}), { virtual: true });

jest.mock("@/lib/format", () => ({
  relativeTime: (value: string) => value,
  titleCase: (value: string) => value,
}), { virtual: true });

jest.mock("lucide-react", () => new Proxy({}, { get: () => () => null }), { virtual: true });

import { api } from "@/api/devboardApi";
import WikiPageDetailPage from "./WikiPageDetailPage";

const hostileMarkdown = [
  "## Safe heading",
  "- A **safe bold item**",
  "Normal **safe bold text**.",
  "> Safe quote",
  "<img src=x onerror=\"globalThis.__wikiXss = true\">",
  "<svg onload=\"globalThis.__wikiXss = true\"><circle /></svg>",
  "<scr<script>ipt>globalThis.__wikiXss = true</scr</script>ipt>",
  "<a href=\"javascript:globalThis.__wikiXss = true\">javascript URL</a>",
  "<a href=\"data:text/html,<script>globalThis.__wikiXss = true</script>\">data URL</a>",
].join("\n");

const page = {
  id: "wiki-1",
  project_id: "project-1",
  title: "Security notes",
  category: "technical",
  source_status: "needs_verification",
  updated_at: "2026-07-15T12:00:00Z",
  source: { type: "hades", status: "needs_verification", origin: "hades" },
  body_markdown: hostileMarkdown,
  evidence: [],
  related_run_ids: [],
  related_node_ids: [],
};

let container: HTMLDivElement;
let root: Root;

async function settle(): Promise<void> {
  await act(async () => { await new Promise((resolve) => setTimeout(resolve, 0)); });
}

describe("WikiPageDetailPage Markdown rendering", () => {
  beforeEach(() => {
    container = document.createElement("div");
    document.body.appendChild(container);
    root = createRoot(container);
    (api.getWikiPage as any).mockReset().mockResolvedValue(page);
    (api.getProject as any).mockReset().mockResolvedValue({ id: "project-1", status: "active" });
    (api.updateWikiPage as any).mockReset().mockResolvedValue(page);
    delete (globalThis as any).__wikiXss;
  });

  afterEach(() => {
    act(() => root.unmount());
    container.remove();
    delete (globalThis as any).__wikiXss;
  });

  it("renders hostile HTML and URL schemes as inert text", async () => {
    await act(async () => { root.render(<WikiPageDetailPage />); });
    await settle();
    await settle();

    const content = Array.from(container.querySelectorAll("section"))
      .find((section) => section.querySelector("h2")?.textContent === "Content");

    expect(content).toBeTruthy();
    expect(content?.querySelector("img, svg, script, a")).toBeNull();
    expect(content?.textContent).toContain("<img src=x onerror=");
    expect(content?.textContent).toContain("<svg onload=");
    expect(content?.textContent).toContain("<scr<script>ipt>");
    expect(content?.textContent).toContain("javascript:globalThis.__wikiXss");
    expect(content?.textContent).toContain("data:text/html,<script>");
    expect((globalThis as any).__wikiXss).toBeUndefined();
  });

  it("preserves the supported Markdown formatting and raw edit value", async () => {
    await act(async () => { root.render(<WikiPageDetailPage />); });
    await settle();
    await settle();

    expect(container.querySelector("h3")?.textContent).toBe("Safe heading");
    expect(container.querySelector("blockquote")?.textContent).toBe("Safe quote");
    expect(Array.from(container.querySelectorAll("strong")).map((node) => node.textContent)).toEqual([
      "safe bold item",
      "safe bold text",
    ]);

    const editButton = Array.from(container.querySelectorAll("button"))
      .find((button) => button.textContent?.includes("Edit"));
    await act(async () => { editButton?.click(); });

    const textarea = container.querySelector("[data-testid='wiki-edit-markdown']") as HTMLTextAreaElement;
    expect(textarea.value).toBe(hostileMarkdown);
  });
});
