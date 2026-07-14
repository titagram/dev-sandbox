import { ProjectDetail, RiskLevel, Role, TaskColumn, TaskCreateInput, TaskMutationInput } from "@/types/devboard";
import type { IntakeNormalization } from "@/types/devboard";

export const TASK_COLUMNS: TaskColumn[] = ["backlog", "ready", "in_progress", "blocked", "review", "done"];
export const TASK_RISKS: RiskLevel[] = ["low", "medium", "high", "critical"];

export type TaskRepositoryOption = Pick<ProjectDetail["repositories"][number], "id" | "name" | "key">;

export type TaskFormState = {
  title: string;
  description: string;
  column: TaskColumn;
  risk: RiskLevel;
  repositoryIds: string[];
  acceptanceCriteria: string;
};

export const emptyTaskForm: TaskFormState = {
  title: "",
  description: "",
  column: "backlog",
  risk: "medium",
  repositoryIds: [],
  acceptanceCriteria: "",
};

export function splitAcceptanceCriteria(value: string): string[] {
  return value
    .split(/\r?\n/)
    .map((line) => line.trim().replace(/^[-*]\s+/, "").trim())
    .filter(Boolean);
}

export function taskFormToPayload(form: TaskFormState): TaskCreateInput {
  return {
    title: form.title.trim(),
    description: form.description.trim() || null,
    column: form.column,
    risk: form.risk,
    repository_ids: [...form.repositoryIds],
    acceptance_criteria: splitAcceptanceCriteria(form.acceptanceCriteria),
  };
}

export function taskFormToMutationPayload(form: TaskFormState): TaskMutationInput {
  return taskFormToPayload(form);
}

export function normalizeTaskRepositorySelection(taskRepositories: string[], repositories: TaskRepositoryOption[]): { repositoryIds: string[]; unresolved: string[] } {
  const byDisplayValue = new Map<string, string>();
  const validRepositoryIds = new Set(repositories.map((repository) => repository.id));
  const repositoryIds: string[] = [];
  const unresolved: string[] = [];

  repositories.forEach((repository) => {
    [repository.id, repository.name, repository.key].forEach((value) => {
      if (value) byDisplayValue.set(value, repository.id);
    });
  });

  taskRepositories.forEach((repository) => {
    const repositoryId = byDisplayValue.get(repository) || repository;
    if (!validRepositoryIds.has(repositoryId)) {
      unresolved.push(repository);
      return;
    }

    if (!repositoryIds.includes(repositoryId)) repositoryIds.push(repositoryId);
  });

  return { repositoryIds, unresolved };
}

export function resolveTaskRepositoryIds(taskRepositories: string[], repositories: TaskRepositoryOption[]): string[] {
  return normalizeTaskRepositorySelection(taskRepositories, repositories).repositoryIds;
}

export function taskDetailToTaskForm(
  task: Pick<
    TaskFormState,
    "title" | "description" | "column" | "risk"
  > & { repositories: string[]; acceptance_criteria: string[] },
  repositories: TaskRepositoryOption[] = [],
): TaskFormState {
  const repositorySelection = repositories.length
    ? normalizeTaskRepositorySelection(task.repositories, repositories)
    : null;

  return {
    title: task.title,
    description: task.description || "",
    column: task.column,
    risk: task.risk,
    repositoryIds: repositorySelection
      ? [...repositorySelection.repositoryIds, ...repositorySelection.unresolved]
      : [...task.repositories],
    acceptanceCriteria: task.acceptance_criteria.join("\n"),
  };
}

export function canMutateTasksForProject(project: Pick<ProjectDetail, "status"> | null | undefined, role: Role | null | undefined): boolean {
  return project?.status === "active" && (role === "admin" || role === "pm" || role === "developer");
}

/** Map an intake normalization result to a task form draft. */
export function intakeToTaskForm(normalization: IntakeNormalization): TaskFormState {
  return {
    title: normalization.suggested_title,
    description: normalization.suggested_description,
    column: "backlog",
    risk: normalization.requires_root_cause ? "high" : "medium",
    repositoryIds: [],
    acceptanceCriteria: "",
  };
}
