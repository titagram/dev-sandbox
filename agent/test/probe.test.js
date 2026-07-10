import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
import { createHash } from "node:crypto";
import { mkdirSync, mkdtempSync, realpathSync, rmSync, symlinkSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import path from "node:path";
import test from "node:test";

import { probeGitWorkspace, sanitizeRemoteUrl } from "../src/probe.js";

function gitAvailable() {
  try {
    execFileSync("git", ["--version"], { stdio: "ignore" });
    return true;
  } catch {
    return false;
  }
}

function git(root, args) {
  return execFileSync("git", ["-C", root, ...args], {
    encoding: "utf8",
    stdio: ["ignore", "pipe", "pipe"]
  }).trim();
}

function gitRaw(args) {
  return execFileSync("git", args, {
    encoding: "utf8",
    stdio: ["ignore", "pipe", "pipe"]
  }).trim();
}

function createCommittedRepository() {
  const root = mkdtempSync(path.join(tmpdir(), "devboard-agent-probe-"));

  git(root, ["init"]);
  git(root, ["config", "user.email", "agent@example.test"]);
  git(root, ["config", "user.name", "DevBoard Agent Test"]);
  writeFileSync(path.join(root, "README.md"), "probe fixture\n");
  git(root, ["add", "README.md"]);
  git(root, ["commit", "-m", "initial commit"]);

  return root;
}

test("probeGitWorkspace reports clean committed workspaces", { skip: !gitAvailable() }, () => {
  const root = createCommittedRepository();

  try {
    const result = probeGitWorkspace(root);
    const resolved = path.resolve(root);

    assert.equal(result.display_path, resolved);
    assert.equal(result.local_root_hash.length, "sha256:".length + 64);
    assert.equal(result.last_head_sha, git(root, ["rev-parse", "HEAD"]));
    assert.equal(result.dirty_status, "clean");
    assert.ok(result.current_branch.length > 0);
  } finally {
    rmSync(root, { recursive: true, force: true });
  }
});

test("sanitizeRemoteUrl keeps host and hash without leaking credentials", () => {
  const sanitized = sanitizeRemoteUrl("https://token@example.test/org/repo.git");

  assert.equal(sanitized.host, "example.test");
  assert.equal(sanitized.hash.startsWith("sha256:"), true);
  assert.equal(JSON.stringify(sanitized).includes("token"), false);
});

test("probeGitWorkspace reports local remote metadata and ahead behind counts", { skip: !gitAvailable() }, () => {
  const root = createCommittedRepository();
  const remote = mkdtempSync(path.join(tmpdir(), "devboard-agent-remote-"));

  try {
    gitRaw(["init", "--bare", remote]);
    git(root, ["remote", "add", "origin", remote]);
    git(root, ["push", "-u", "origin", "HEAD"]);

    writeFileSync(path.join(root, "README.md"), "ahead fixture\n");
    git(root, ["add", "README.md"]);
    git(root, ["commit", "-m", "ahead commit"]);

    const result = probeGitWorkspace(root);
    const branch = git(root, ["branch", "--show-current"]);

    assert.equal(result.remote_name, "origin");
    assert.equal(result.remote_url_host, "local");
    assert.equal(result.remote_url_hash.startsWith("sha256:"), true);
    assert.equal(result.upstream_branch, `origin/${branch}`);
    assert.equal(result.ahead_count, 1);
    assert.equal(result.behind_count, 0);
    assert.match(result.git_state_observed_at, /^\d{4}-\d{2}-\d{2}T/);
    assert.equal("remote_url" in result, false);
  } finally {
    rmSync(root, { recursive: true, force: true });
    rmSync(remote, { recursive: true, force: true });
  }
});

test("probeGitWorkspace reports dirty workspaces after a file changes", { skip: !gitAvailable() }, () => {
  const root = createCommittedRepository();

  try {
    writeFileSync(path.join(root, "README.md"), "changed fixture\n");

    const result = probeGitWorkspace(root);

    assert.equal(result.dirty_status, "dirty");
  } finally {
    rmSync(root, { recursive: true, force: true });
  }
});

test("probeGitWorkspace canonicalizes nested and symlinked paths to the real Git root", { skip: !gitAvailable() }, () => {
  const root = createCommittedRepository();
  const linkRoot = mkdtempSync(path.join(tmpdir(), "devboard-agent-probe-link-"));
  const nested = path.join(root, "nested", "directory");
  const linked = path.join(linkRoot, "workspace");

  try {
    mkdirSync(nested, { recursive: true });
    symlinkSync(root, linked, "dir");

    const result = probeGitWorkspace(path.join(linked, "nested", "directory"));
    const canonicalRoot = realpathSync(root);
    const expectedHash = `sha256:${createHash("sha256").update(canonicalRoot).digest("hex")}`;

    assert.equal(result.display_path, canonicalRoot);
    assert.equal(result.local_root_hash, expectedHash);
  } finally {
    rmSync(root, { recursive: true, force: true });
    rmSync(linkRoot, { recursive: true, force: true });
  }
});
