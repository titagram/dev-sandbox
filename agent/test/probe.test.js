import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
import { mkdtempSync, rmSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import path from "node:path";
import test from "node:test";

import { probeGitWorkspace } from "../src/probe.js";

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
