import { execFileSync } from "node:child_process";
import { createHash } from "node:crypto";
import path from "node:path";

function runGit(root, args, options = {}) {
  return execFileSync("git", ["-C", root, ...args], {
    encoding: "utf8",
    stdio: ["ignore", "pipe", "pipe"],
    ...options
  }).trim();
}

function tryGit(root, args) {
  try {
    return runGit(root, args);
  } catch {
    return null;
  }
}

export function probeGitWorkspace(root) {
  if (!root) {
    throw new Error("A workspace path is required.");
  }

  const resolved = path.resolve(root);
  const insideWorktree = tryGit(resolved, ["rev-parse", "--is-inside-work-tree"]);

  if (insideWorktree !== "true") {
    throw new Error(`Path is not inside a Git worktree: ${resolved}`);
  }

  const branch = tryGit(resolved, ["branch", "--show-current"]);
  const headSha = tryGit(resolved, ["rev-parse", "--verify", "HEAD"]);
  const status = runGit(resolved, ["status", "--porcelain"]);
  const localRootHash = createHash("sha256").update(resolved).digest("hex");

  return {
    local_root_hash: `sha256:${localRootHash}`,
    display_path: resolved,
    current_branch: branch || "HEAD",
    last_head_sha: headSha || null,
    dirty_status: status.length === 0 ? "clean" : "dirty"
  };
}
