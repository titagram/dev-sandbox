declare const describe: any;
declare const expect: any;
declare const it: any;

import { canAccess, chooseProjectScope, navForRole, navPathForItem } from "./nav";

const labelsFor = (role: Parameters<typeof navForRole>[0]) =>
  navForRole(role).map((item) => item.label);

describe("workspace primary navigation", () => {
  it("shows only project workspace primary items for dashboard roles", () => {
    expect(labelsFor("pm")).toEqual(["Projects", "Work", "Ask", "Chat", "Memory", "Wiki", "Graph"]);
    expect(labelsFor("developer")).toEqual(["Projects", "Work", "Ask", "Chat", "Memory", "Wiki", "Graph", "Engineering"]);
    expect(labelsFor("admin")).toEqual(["Projects", "Work", "Ask", "Chat", "Memory", "Wiki", "Graph", "Engineering", "Settings", "Hades"]);
    expect(labelsFor("sysadmin")).toEqual(["Projects", "Chat", "Engineering", "Settings"]);
    expect(labelsFor("agent")).toEqual([]);
  });

  it("keeps hidden legacy route guard keys authorized", () => {
    expect(canAccess("pm", "wiki")).toEqual({ ok: true, readOnly: false });
    expect(canAccess("developer", "quality")).toEqual({ ok: true, readOnly: true });
    expect(canAccess("sysadmin", "system")).toEqual({ ok: true, readOnly: false });
    expect(canAccess("admin", "admin")).toEqual({ ok: true, readOnly: false });
  });

  it("allows sysadmin read-only access to Memory, Agent Work, and Chat", () => {
    expect(canAccess("sysadmin", "memory")).toEqual({ ok: true, readOnly: true });
    expect(canAccess("sysadmin", "agent-work")).toEqual({ ok: true, readOnly: true });
    expect(canAccess("sysadmin", "agent-chat")).toEqual({ ok: true, readOnly: true });
  });

  it("routes project-scoped primary Work to the Kanban board", () => {
    const work = navForRole("developer").find((item) => item.key === "kanban");

    expect(work).toBeTruthy();
    expect(navPathForItem(work!, "developer", "proj-core")).toBe("/projects/proj-core/kanban");
  });

  it("routes primary Wiki to the active project when one exists", () => {
    const wiki = navForRole("developer").find((item) => item.key === "wiki");

    expect(wiki).toBeTruthy();
    expect(navPathForItem(wiki!, "developer")).toBe("/wiki");
    expect(navPathForItem(wiki!, "developer", "proj-core")).toBe("/projects/proj-core/wiki");
  });

  it("routes primary Chat to the active project when one exists", () => {
    const chat = navForRole("developer").find((item) => item.key === "agent-chat");

    expect(chat).toBeTruthy();
    expect(navPathForItem(chat!, "developer")).toBe("/agent-chat");
    expect(navPathForItem(chat!, "developer", "proj-core")).toBe("/projects/proj-core/agent-chat");
  });

  it("encodes project ids when building project-scoped routes", () => {
    const work = navForRole("developer").find((item) => item.key === "kanban");

    expect(work).toBeTruthy();
    expect(navPathForItem(work!, "developer", "proj/core")).toBe("/projects/proj%2Fcore/kanban");
  });

  it("prefers the project encoded by the current route over stale stored scope", () => {
    expect(chooseProjectScope("real-project", "demo-project", [{ id: "demo-project" }, { id: "real-project" }])).toBe("real-project");
  });

  it("falls back to the first active project when stored scope is stale", () => {
    expect(chooseProjectScope(undefined, "demo-project", [{ id: "real-project" }, { id: "another-project" }])).toBe("real-project");
  });
});
