import { execFileSync } from "node:child_process";
import { createHash } from "node:crypto";
import { realpathSync } from "node:fs";
import path from "node:path";

const GIT_TIMEOUT_MS = 10_000;
const GIT_MAX_BUFFER = 1024 * 1024;

function runGit(root, args) {
  return execFileSync("git", ["-C", root, ...args], {
    encoding: "utf8",
    stdio: ["ignore", "pipe", "pipe"],
    timeout: GIT_TIMEOUT_MS,
    maxBuffer: GIT_MAX_BUFFER
  }).trim();
}

function tryGit(root, args) {
  try {
    return runGit(root, args);
  } catch {
    return null;
  }
}

export function sanitizeRemoteUrl(remoteUrl) {
  if (!remoteUrl) {
    return { host: null, hash: null };
  }

  let host = "local";

  try {
    const parsed = new URL(remoteUrl);
    host = parsed.hostname || "local";
  } catch {
    const scpLike = remoteUrl.match(/^[^@]+@([^:]+):/);
    host = scpLike?.[1] || "local";
  }

  return {
    host,
    hash: `sha256:${createHash("sha256").update(remoteUrl).digest("hex")}`
  };
}

function aheadBehind(root) {
  const value = tryGit(root, ["rev-list", "--left-right", "--count", "HEAD...@{u}"]);

  if (!value) {
    return { ahead_count: null, behind_count: null };
  }

  const [ahead, behind] = value.split(/\s+/).map((part) => Number.parseInt(part, 10));

  return {
    ahead_count: Number.isFinite(ahead) ? ahead : null,
    behind_count: Number.isFinite(behind) ? behind : null
  };
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

  const reportedRoot = runGit(resolved, ["rev-parse", "--show-toplevel"]);
  const canonicalRoot = realpathSync(reportedRoot);
  const branch = tryGit(canonicalRoot, ["branch", "--show-current"]);
  const headSha = tryGit(canonicalRoot, ["rev-parse", "--verify", "HEAD"]);
  const status = runGit(canonicalRoot, ["status", "--porcelain"]);
  const localRootHash = createHash("sha256").update(canonicalRoot).digest("hex");
  const remoteName = tryGit(canonicalRoot, ["remote"])?.split(/\s+/).filter(Boolean)[0] || null;
  const remoteUrl = remoteName ? tryGit(canonicalRoot, ["remote", "get-url", remoteName]) : null;
  const sanitizedRemote = sanitizeRemoteUrl(remoteUrl);
  const upstreamBranch = tryGit(canonicalRoot, ["rev-parse", "--abbrev-ref", "--symbolic-full-name", "@{u}"]);
  const counts = aheadBehind(canonicalRoot);

  return {
    local_root_hash: `sha256:${localRootHash}`,
    display_path: canonicalRoot,
    current_branch: branch || "HEAD",
    last_head_sha: headSha || null,
    dirty_status: status.length === 0 ? "clean" : "dirty",
    remote_name: remoteName,
    remote_url_host: sanitizedRemote.host,
    remote_url_hash: sanitizedRemote.hash,
    upstream_branch: upstreamBranch,
    ...counts,
    git_state_observed_at: new Date().toISOString()
  };
}
