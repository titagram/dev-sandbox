import React from "react";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Textarea } from "@/components/ui/textarea";
import { titleCase } from "@/lib/format";
import { TASK_COLUMNS, TASK_RISKS, TaskFormState, TaskRepositoryOption } from "./taskFormModel";

export function TaskFormFields({
  disabled,
  form,
  fieldIdPrefix,
  repositories,
  repositoriesLoading,
  repositoriesError,
  onChange,
}: {
  disabled?: boolean;
  form: TaskFormState;
  fieldIdPrefix: string;
  repositories: TaskRepositoryOption[];
  repositoriesLoading?: boolean;
  repositoriesError?: string | null;
  onChange: (form: TaskFormState) => void;
}) {
  const setField = <K extends keyof TaskFormState>(field: K, value: TaskFormState[K]) => {
    onChange({ ...form, [field]: value });
  };

  const toggleRepository = (repositoryId: string) => {
    const selected = form.repositoryIds.includes(repositoryId);
    setField(
      "repositoryIds",
      selected
        ? form.repositoryIds.filter((id) => id !== repositoryId)
        : [...form.repositoryIds, repositoryId],
    );
  };

  return (
    <div className="space-y-3">
      <div className="space-y-1.5">
        <Label htmlFor={`${fieldIdPrefix}-title`} className="text-xs">Title</Label>
        <Input
          id={`${fieldIdPrefix}-title`}
          value={form.title}
          required
          disabled={disabled}
          onChange={(event) => setField("title", event.target.value)}
          data-testid={`${fieldIdPrefix}-title`}
        />
      </div>

      <div className="space-y-1.5">
        <Label htmlFor={`${fieldIdPrefix}-description`} className="text-xs">Description</Label>
        <Textarea
          id={`${fieldIdPrefix}-description`}
          value={form.description}
          disabled={disabled}
          onChange={(event) => setField("description", event.target.value)}
          className="min-h-20"
          data-testid={`${fieldIdPrefix}-description`}
        />
      </div>

      <div className="grid gap-3 sm:grid-cols-2">
        <div className="space-y-1.5">
          <Label htmlFor={`${fieldIdPrefix}-column`} className="text-xs">Column</Label>
          <Select value={form.column} onValueChange={(value) => setField("column", value as TaskFormState["column"])} disabled={disabled}>
            <SelectTrigger id={`${fieldIdPrefix}-column`} data-testid={`${fieldIdPrefix}-column`}>
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {TASK_COLUMNS.map((column) => (
                <SelectItem key={column} value={column}>{titleCase(column)}</SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>

        <div className="space-y-1.5">
          <Label htmlFor={`${fieldIdPrefix}-risk`} className="text-xs">Risk</Label>
          <Select value={form.risk} onValueChange={(value) => setField("risk", value as TaskFormState["risk"])} disabled={disabled}>
            <SelectTrigger id={`${fieldIdPrefix}-risk`} data-testid={`${fieldIdPrefix}-risk`}>
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {TASK_RISKS.map((risk) => (
                <SelectItem key={risk} value={risk}>{titleCase(risk)}</SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
      </div>

      <div className="space-y-1.5">
        <Label className="text-xs">Repositories</Label>
        <div className="max-h-32 overflow-y-auto rounded-md border border-border p-2" data-testid={`${fieldIdPrefix}-repositories`}>
          {repositoriesLoading ? (
            <p className="px-1 py-2 text-sm text-muted-foreground">Loading repositories...</p>
          ) : repositoriesError ? (
            <p className="px-1 py-2 text-sm text-destructive">{repositoriesError}</p>
          ) : repositories.length ? (
            <div className="grid gap-1.5">
              {repositories.map((repository) => (
                <label key={repository.id} className="flex min-w-0 items-center gap-2 rounded-sm px-1 py-1 text-sm hover:bg-accent/50">
                  <input
                    type="checkbox"
                    checked={form.repositoryIds.includes(repository.id)}
                    disabled={disabled}
                    onChange={() => toggleRepository(repository.id)}
                    className="h-4 w-4 shrink-0 accent-primary"
                  />
                  <span className="min-w-0 flex-1 truncate">{repository.name}</span>
                  {repository.key && <span className="shrink-0 text-xs text-muted-foreground">{repository.key}</span>}
                </label>
              ))}
            </div>
          ) : (
            <p className="px-1 py-2 text-sm text-muted-foreground">No repositories.</p>
          )}
        </div>
      </div>

      <div className="space-y-1.5">
        <Label htmlFor={`${fieldIdPrefix}-acceptance`} className="text-xs">Acceptance criteria</Label>
        <Textarea
          id={`${fieldIdPrefix}-acceptance`}
          value={form.acceptanceCriteria}
          disabled={disabled}
          onChange={(event) => setField("acceptanceCriteria", event.target.value)}
          placeholder="One criterion per line"
          className="min-h-20"
          data-testid={`${fieldIdPrefix}-acceptance`}
        />
      </div>
    </div>
  );
}
