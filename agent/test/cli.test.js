import assert from "node:assert/strict";
import { spawn } from "node:child_process";
import { execFileSync } from "node:child_process";
import { createHash, createHmac } from "node:crypto";
import { createServer } from "node:http";
import { mkdtempSync, readFileSync, rmSync, statSync, writeFileSync } from "node:fs";
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
  const credentialRoot = mkdtempSync(path.join(tmpdir(), "devboard-agent-cli-credentials-"));
  const credentialFile = path.join(credentialRoot, "credentials.json");
  const deviceSecret = "c".repeat(64);
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
    writeFileSync(credentialFile, JSON.stringify({
      server_url: `http://127.0.0.1:${port}`,
      token: "token-1",
      device_id: "device-1",
      device_secret: deviceSecret
    }));
    const result = await runAgent([
      "refresh-workspace",
      "--credentials-path",
      credentialFile,
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
    assert.match(requests[0].headers["x-devboard-signature"], /^v1=[a-f0-9]{64}$/);
    assert.equal(requests[0].body.protocol_version, "v1");
    assert.equal(requests[0].body.display_path, path.resolve(root));
    assert.equal(requests[0].body.dirty_status, "clean");
    assert.match(requests[0].body.git_state_observed_at, /^\d{4}-\d{2}-\d{2}T/);
    assert.equal(JSON.parse(result.stdout).status, "linked");
  } finally {
    server.close();
    rmSync(root, { recursive: true, force: true });
    rmSync(credentialRoot, { recursive: true, force: true });
  }
});

test("register-device stores the one-shot secret and auth-check reuses signed credentials without token argv", async () => {
  const credentialRoot = mkdtempSync(path.join(tmpdir(), "devboard-agent-pairing-"));
  const credentialFile = path.join(credentialRoot, "credentials.json");
  const token = "devb_live_token|pairing-secret";
  const deviceId = "device-paired-01";
  const deviceSecret = "d".repeat(64);
  const requests = [];
  let registrations = 0;
  const server = createServer((request, response) => {
    let body = "";
    request.on("data", (chunk) => {
      body += chunk;
    });
    request.on("end", () => {
      requests.push({ url: request.url, headers: request.headers, body });
      response.writeHead(200, { "Content-Type": "application/json" });

      if (request.url === "/api/plugin/v1/devices/register") {
        registrations += 1;
        const registrationResponse = {
          device_id: deviceId,
          status: "active",
          server_time: "2026-07-09T12:00:00Z"
        };

        if (registrations === 1) {
          registrationResponse.device_secret = deviceSecret;
        }

        response.end(JSON.stringify(registrationResponse));
      } else {
        response.end(JSON.stringify({
          protocol_version: "v1",
          authenticated: true,
          token: { device_id: deviceId },
          server_time: "2026-07-09T12:00:01Z"
        }));
      }
    });
  });

  try {
    const port = await listen(server);
    const registerResult = await runAgent([
      "register-device",
      "--server",
      `http://127.0.0.1:${port}`,
      "--token",
      token,
      "--name",
      "Pairing Test",
      "--credentials-path",
      credentialFile
    ]);

    assert.equal(registerResult.status, 0, registerResult.stderr);
    assert.doesNotMatch(registerResult.stdout, new RegExp(token.replace(/[|]/g, "\\|")));
    assert.doesNotMatch(registerResult.stdout, new RegExp(deviceSecret));
    assert.doesNotMatch(registerResult.stdout, new RegExp(deviceId));

    const stored = JSON.parse(readFileSync(credentialFile, "utf8"));
    assert.equal(stored.server_url, `http://127.0.0.1:${port}`);
    assert.equal(stored.token, token);
    assert.equal(stored.device_id, deviceId);
    assert.equal(stored.device_secret, deviceSecret);
    assert.equal(statSync(credentialFile).mode & 0o777, 0o600);

    const authResult = await runAgent(["auth-check", "--credentials-path", credentialFile]);
    assert.equal(authResult.status, 0, authResult.stderr);
    assert.equal(JSON.parse(authResult.stdout).authenticated, true);
    assert.doesNotMatch(authResult.stdout, /pairing-secret/);
    assert.equal(requests.length, 2);
    assert.equal(requests[0].headers.authorization, `Bearer ${token}`);
    assert.equal(requests[0].headers["x-devboard-signature"], undefined);

    const signedRequest = requests[1];
    const bodyHash = createHash("sha256").update(signedRequest.body).digest("hex");
    const signingKey = createHash("sha256").update(deviceSecret).digest("hex");
    const canonical = `POST\n${signedRequest.url}\n${signedRequest.headers["x-devboard-timestamp"]}\n${bodyHash}`;
    const expectedSignature = `v1=${createHmac("sha256", signingKey).update(canonical).digest("hex")}`;

    assert.equal(signedRequest.headers["x-devboard-device-id"], deviceId);
    assert.equal(signedRequest.headers["x-devboard-content-sha256"], bodyHash);
    assert.equal(signedRequest.headers["x-devboard-signature"], expectedSignature);

    const repeatResult = await runAgent([
      "register-device",
      "--name",
      "Pairing Test Renamed",
      "--credentials-path",
      credentialFile
    ]);
    assert.equal(repeatResult.status, 0, repeatResult.stderr);
    assert.equal(requests.length, 3);
    assert.match(requests[2].headers["x-devboard-signature"], /^v1=[a-f0-9]{64}$/);
    assert.equal(JSON.parse(readFileSync(credentialFile, "utf8")).device_secret, deviceSecret);
  } finally {
    server.close();
    rmSync(credentialRoot, { recursive: true, force: true });
  }
});

test("register-device refuses an existing device response when the one-shot secret is unavailable", async () => {
  const credentialRoot = mkdtempSync(path.join(tmpdir(), "devboard-agent-pairing-missing-"));
  const credentialFile = path.join(credentialRoot, "credentials.json");
  const server = createServer((request, response) => {
    request.resume();
    request.on("end", () => {
      response.writeHead(200, { "Content-Type": "application/json" });
      response.end(JSON.stringify({
        device_id: "existing-device",
        status: "active",
        server_time: "2026-07-09T12:00:00Z"
      }));
    });
  });

  try {
    const port = await listen(server);
    const result = await runAgent([
      "register-device",
      "--server",
      `http://127.0.0.1:${port}`,
      "--token",
      "token-without-local-pairing",
      "--name",
      "Existing Device",
      "--credentials-path",
      credentialFile
    ]);

    assert.equal(result.status, 1);
    assert.match(result.stderr, /one-time secret/);
    assert.doesNotMatch(result.stderr, /token-without-local-pairing/);
  } finally {
    server.close();
    rmSync(credentialRoot, { recursive: true, force: true });
  }
});
