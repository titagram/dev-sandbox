import React, { act } from "react";
import { createRoot, Root } from "react-dom/client";

declare const jest: any;
declare const beforeEach: any;
declare const afterEach: any;
declare const describe: any;
declare const it: any;
declare const expect: any;

(globalThis as any).IS_REACT_ACT_ENVIRONMENT = true;

jest.mock("@/context/AuthContext", () => ({
  AuthProvider: ({ children }: any) => <>{children}</>,
  useAuth: () => ({
    user: { id: "7", name: "System Operator", email: "sysadmin@example.test", role: "sysadmin", avatar_color: "#64748b" },
    loading: false,
  }),
}), { virtual: true });
jest.mock("@/context/ThemeContext", () => ({ ThemeProvider: ({ children }: any) => <>{children}</> }), { virtual: true });
jest.mock("react-router-dom", () => ({
  BrowserRouter: ({ children }: any) => <>{children}</>,
  Routes: ({ children }: any) => <>{children}</>,
  Route: ({ path, element, children }: any) => {
    if (!path) return <>{children}</>;
    if (path === "*") return null;
    const pattern = new RegExp("^" + path.replace(/:[^/]+/g, "[^/]+") + "$");
    return pattern.test(globalThis.window.location.pathname) ? element : null;
  },
  Navigate: () => null,
  Link: ({ children, to, ...props }: any) => <a href={to} {...props}>{children}</a>,
  useLocation: () => ({ pathname: globalThis.window.location.pathname, state: null }),
  useNavigate: () => require("jest-mock").fn(),
  useParams: () => ({ projectId: "project-1" }),
}), { virtual: true });
jest.mock("@/api/devboardApi", () => ({
  API_BASE_URL: "",
  USE_MOCK: false,
  api: {
    getProjectLogbook: require("jest-mock").fn().mockResolvedValue({ project_id: "project-1", items: [], next_cursor: null }),
    createProjectLogbookNote: require("jest-mock").fn(),
  },
}), { virtual: true });
jest.mock("sonner", () => ({ Toaster: () => null, toast: { success: require("jest-mock").fn() } }), { virtual: true });
jest.mock("@/components/ui/sonner", () => ({ Toaster: () => null }), { virtual: true });

import App from "./App";

let container: HTMLDivElement;
let root: Root;

async function settle(): Promise<void> {
  await act(async () => { await new Promise((resolve) => setTimeout(resolve, 0)); });
}

describe("App logbook route authorization", () => {
  beforeEach(() => {
    window.history.replaceState({}, "", "/projects/project-1/logbook");
    container = document.createElement("div");
    document.body.appendChild(container);
    root = createRoot(container);
  });

  afterEach(() => {
    act(() => root.unmount());
    container.remove();
  });

  it("lets a Sysadmin open the real logbook route read-only", async () => {
    await act(async () => { root.render(<App />); });
    await settle();

    expect(container.textContent).toContain("Project logbook");
    expect(container.textContent).not.toContain("Not authorized");
    expect(container.textContent).not.toContain("Add note");
  });
});
