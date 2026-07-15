import React, { act } from "react";
import { createRoot, Root } from "react-dom/client";

declare const jest: any;
declare const beforeEach: any;
declare const afterEach: any;
declare const describe: any;
declare const it: any;
declare const expect: any;

(globalThis as any).IS_REACT_ACT_ENVIRONMENT = true;

const projects = [
  { id: "demo-project", name: "Demo Project" },
  { id: "real-project", name: "Real Project" },
];

jest.mock("@/api/devboardApi", () => ({
  API_BASE_URL: "/api",
  USE_MOCK: false,
  api: { getProjects: require("jest-mock").fn() },
}), { virtual: true });
jest.mock("@/hooks/useApi", () => ({
  useApi: () => ({ data: projects, loading: false, error: null, reload: require("jest-mock").fn() }),
}), { virtual: true });
jest.mock("@/context/AuthContext", () => ({
  useAuth: () => ({ user: { name: "Reviewer", email: "reviewer@example.test", role: "developer", avatar_color: "#123456" }, logout: require("jest-mock").fn() }),
}), { virtual: true });
jest.mock("@/context/ThemeContext", () => ({ useTheme: () => ({ theme: "light", toggle: require("jest-mock").fn() }) }), { virtual: true });
jest.mock("@/components/devboard/CommandPalette", () => ({
  CommandPalette: () => null,
  useCommandPalette: () => ({ open: false, setOpen: require("jest-mock").fn() }),
}), { virtual: true });
jest.mock("@/components/ui/button", () => ({ Button: ({ children, ...props }: any) => <button {...props}>{children}</button> }), { virtual: true });
jest.mock("@/components/ui/sheet", () => ({
  Sheet: ({ children }: any) => <>{children}</>,
  SheetContent: ({ children }: any) => <div>{children}</div>,
  SheetTrigger: ({ children }: any) => <>{children}</>,
}), { virtual: true });
jest.mock("@/components/ui/dropdown-menu", () => ({
  DropdownMenu: ({ children }: any) => <>{children}</>,
  DropdownMenuContent: ({ children }: any) => <div>{children}</div>,
  DropdownMenuItem: ({ children, ...props }: any) => <button {...props}>{children}</button>,
  DropdownMenuLabel: ({ children }: any) => <div>{children}</div>,
  DropdownMenuSeparator: () => <hr />,
  DropdownMenuTrigger: ({ children }: any) => <>{children}</>,
}), { virtual: true });
jest.mock("lucide-react", () => new Proxy({}, { get: () => () => null }), { virtual: true });
jest.mock("react-router-dom", () => ({
  NavLink: ({ children, to, className, ...props }: any) => <a href={to} className={typeof className === "function" ? className({ isActive: false }) : className} {...props}>{children}</a>,
  Outlet: () => <div data-testid="outlet" />,
  useLocation: () => ({ pathname: "/projects/real-project/wiki" }),
  useNavigate: () => require("jest-mock").fn(),
}), { virtual: true });

import AppShell from "./AppShell";

let container: HTMLDivElement;
let root: Root;

describe("AppShell project scope and narrow header", () => {
  beforeEach(() => {
    localStorage.clear();
    localStorage.setItem("devboard.selectedProjectScope.v1", "demo-project");
    container = document.createElement("div");
    document.body.appendChild(container);
    root = createRoot(container);
  });

  afterEach(() => {
    act(() => root.unmount());
    container.remove();
    localStorage.clear();
  });

  it("uses the route project instead of stale Demo Project and keeps breadcrumbs bounded", async () => {
    await act(async () => { root.render(<AppShell />); });

    expect(container.querySelector("[data-testid='scope-label']")?.textContent).toBe("Real Project");
    expect(localStorage.getItem("devboard.selectedProjectScope.v1")).toBe("real-project");
    expect(container.querySelector("[data-testid='breadcrumb']")?.className).toContain("flex-nowrap");
    expect(container.querySelector("header")?.className).toContain("overflow-hidden");
    expect(container.querySelector("[data-testid='scope-label']")?.getAttribute("title")).toBe("Real Project");
  });
});
