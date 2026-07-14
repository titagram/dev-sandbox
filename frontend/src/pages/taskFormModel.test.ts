declare const describe: any;
declare const expect: any;
declare const it: any;

import {
  canMutateTasksForProject,
  emptyTaskForm,
  intakeToTaskForm,
  normalizeTaskRepositorySelection,
  resolveTaskRepositoryIds,
  taskDetailToTaskForm,
  taskFormToPayload,
} from "./taskFormModel";

describe("task form model", () => {
  it("builds compact task mutation payloads from form state", () => {
    const payload = taskFormToPayload({
      ...emptyTaskForm,
      title: "  Add create task flow  ",
      description: "  PMs can create scoped tasks.  ",
      column: "ready",
      risk: "high",
      repositoryIds: ["repo-api", "repo-web"],
      acceptanceCriteria: "\n- Saves the task\nNavigates to the detail page\n\n",
    });

    expect(payload).toEqual({
      title: "Add create task flow",
      description: "PMs can create scoped tasks.",
      column: "ready",
      risk: "high",
      repository_ids: ["repo-api", "repo-web"],
      acceptance_criteria: ["Saves the task", "Navigates to the detail page"],
    });
  });

  it("normalizes task repository display values back to project repository ids", () => {
    const repositories = [
      { id: "repo-api", name: "API", key: "api" },
      { id: "repo-web", name: "Frontend", key: "frontend" },
    ];

    expect(resolveTaskRepositoryIds(["API", "repo-web", "frontend"], repositories)).toEqual(["repo-api", "repo-web"]);
  });

  it("reports unresolved repository display values instead of dropping them silently", () => {
    const repositories = [
      { id: "repo-api", name: "API", key: "api" },
      { id: "repo-web", name: "Frontend", key: "frontend" },
    ];

    expect(normalizeTaskRepositorySelection(["API", "Missing repository"], repositories)).toEqual({
      repositoryIds: ["repo-api"],
      unresolved: ["Missing repository"],
    });
  });

  it("allows task mutation only for task roles on active projects", () => {
    expect(canMutateTasksForProject({ status: "active" }, "admin")).toBe(true);
    expect(canMutateTasksForProject({ status: "active" }, "pm")).toBe(true);
    expect(canMutateTasksForProject({ status: "active" }, "developer")).toBe(true);
    expect(canMutateTasksForProject({ status: "active" }, "sysadmin")).toBe(false);
    expect(canMutateTasksForProject({ status: "archived" }, "admin")).toBe(false);
    expect(canMutateTasksForProject(null, "admin")).toBe(false);
  });

  it("creates edit form state from task detail and repository options", () => {
    const form = taskDetailToTaskForm(
      {
        title: "Clarify Platon questions",
        description: "Show yes/no PM decisions.",
        column: "in_progress",
        risk: "medium",
        repositories: ["Frontend"],
        acceptance_criteria: ["Questions render", "Flow remains intact"],
      },
      [{ id: "repo-web", name: "Frontend", key: "frontend" }],
    );

    expect(form).toEqual({
      title: "Clarify Platon questions",
      description: "Show yes/no PM decisions.",
      column: "in_progress",
      risk: "medium",
      repositoryIds: ["repo-web"],
      acceptanceCriteria: "Questions render\nFlow remains intact",
    });
  });

  it("preserves unresolved task repository values when hydrating edit form state", () => {
    const form = taskDetailToTaskForm(
      {
        title: "Preserve stale repository names",
        description: "Do not silently remove unresolved repositories.",
        column: "ready",
        risk: "high",
        repositories: ["Frontend", "Old Repo"],
        acceptance_criteria: ["Save remains blocked until repositories resolve"],
      },
      [{ id: "repo-web", name: "Frontend", key: "frontend" }],
    );

    expect(form.repositoryIds).toEqual(["repo-web", "Old Repo"]);
    expect(normalizeTaskRepositorySelection(form.repositoryIds, [{ id: "repo-web", name: "Frontend", key: "frontend" }])).toEqual({
      repositoryIds: ["repo-web"],
      unresolved: ["Old Repo"],
    });
  });

  it("maps intake normalization to a task form draft", () => {
    const form = intakeToTaskForm({
      task_type: "bug",
      suggested_title: "Login crashes on invalid email",
      suggested_description: "500 error when submitting invalid email on /login.",
      clarifying_questions: ["Which browser?"],
      requires_root_cause: false,
      confidence: 0.87,
      execution_mode: "mock_deterministic",
    });

    expect(form).toEqual({
      title: "Login crashes on invalid email",
      description: "500 error when submitting invalid email on /login.",
      column: "backlog",
      risk: "medium",
      repositoryIds: [],
      acceptanceCriteria: "",
    });
  });

  it("maps intake normalization with requires_root_cause to high risk draft", () => {
    const form = intakeToTaskForm({
      task_type: "bug",
      suggested_title: "Diagnose payment failure",
      suggested_description: "Root cause unknown.",
      clarifying_questions: [],
      requires_root_cause: true,
      confidence: 0.65,
      execution_mode: "mock_deterministic",
    });

    expect(form.risk).toBe("high");
  });
});
