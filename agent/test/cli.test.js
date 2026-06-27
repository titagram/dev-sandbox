import assert from "node:assert/strict";
import { spawn } from "node:child_process";
import { execFileSync } from "node:child_process";
import { createServer } from "node:http";
import { mkdtempSync, rmSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import path from "node:path";
import { fileURLToPath } from "node:url";
import test from "node:test";

const agentRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");

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
  const root = mkdtempSync(path.join(tmpdir(), "devboard-agent-cli-"));

  git(root, ["init"]);
  git(root, ["config", "user.email", "agent@example.test"]);
  git(root, ["config", "user.name", "DevBoard Agent Test"]);
  writeFileSync(path.join(root, "README.md"), "cli fixture\n");
  git(root, ["add", "README.md"]);
  git(root, ["commit", "-m", "initial commit"]);

  return root;
}

function listen(server) {
  return new Promise((resolve) => {
    server.listen(0, "127.0.0.1", () => {
      resolve(server.address().port);
    });
  });
}

function runAgent(args) {
  return new Promise((resolve) => {
    const child = spawn(process.execPath, ["bin/devboard-agent.js", ...args], {
      cwd: agentRoot,
      stdio: ["ignore", "pipe", "pipe"]
    });
    let stdout = "";
    let stderr = "";

    child.stdout.on("data", (chunk) => {
      stdout += chunk;
    });
    child.stderr.on("data", (chunk) => {
      stderr += chunk;
    });
    child.on("close", (status) => {
      resolve({ status, stdout, stderr });
    });
  });
}

test("refresh-workspace probes git state and posts to the workspace link endpoint", { skip: !gitAvailable() }, async () => {
  const root = createCommittedRepository();
  const requests = [];
  const server = createServer((request, response) => {
    let body = "";

    request.on("data", (chunk) => {
      body += chunk;
    });
    request.on("end", () => {
      requests.push({
        method: request.method,
        url: request.url,
        headers: request.headers,
        body: JSON.parse(body)
      });
      response.writeHead(200, { "Content-Type": "application/json" });
      response.end(JSON.stringify({ status: "linked" }));
    });
  });

  try {
    const port = await listen(server);
    const result = await runAgent([
      "refresh-workspace",
      "--server",
      `http://127.0.0.1:${port}`,
      "--token",
      "token-1",
      "--device-id",
      "device-1",
      "--repository-id",
      "repo-1",
      "--path",
      root
    ]);

    assert.equal(result.status, 0, result.stderr);
    assert.equal(requests.length, 1);
    assert.equal(requests[0].method, "POST");
    assert.equal(requests[0].url, "/api/plugin/v1/repositories/repo-1/local-workspaces");
    assert.equal(requests[0].headers["x-devboard-device-id"], "device-1");
    assert.equal(requests[0].body.protocol_version, "v1");
    assert.equal(requests[0].body.display_path, path.resolve(root));
    assert.equal(requests[0].body.dirty_status, "clean");
    assert.match(requests[0].body.git_state_observed_at, /^\d{4}-\d{2}-\d{2}T/);
    assert.equal(JSON.parse(result.stdout).status, "linked");
  } finally {
    server.close();
    rmSync(root, { recursive: true, force: true });
  }
});
