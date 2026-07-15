import { LocalWorkspaceStatus } from "@/types/devboard";

export type WorkspacePresentation = {
  label: string;
  showLegacyDetails: boolean;
  tone: "green" | "amber" | "red" | "slate";
};

export function workspacePresentation(status: LocalWorkspaceStatus | undefined, canonicalLinked: boolean): WorkspacePresentation {
  const normalized = status || "unknown";
  if (canonicalLinked && (normalized === "unknown" || normalized === "missing")) {
    return { label: "Hades workspace linked", showLegacyDetails: false, tone: "green" };
  }

  return {
    label: normalized.replace(/_/g, " ").replace(/\b\w/g, (letter) => letter.toUpperCase()),
    showLegacyDetails: normalized !== "unknown" && normalized !== "missing",
    tone: normalized === "linked" ? "green" : normalized === "stale" ? "red" : normalized === "missing" ? "amber" : "slate",
  };
}
