import assert from "node:assert/strict";
import { createHash, createHmac } from "node:crypto";
import test from "node:test";

import {
  createDeviceSignature,
  DevBoardProtocolError,
  linkWorkspace,
  normalizeServerUrl,
  requestJson
} from "../src/client.js";

const deviceSecret = "a".repeat(64);

test("linkWorkspace forwards git metadata to the plugin workspace endpoint", async () => {
  const originalFetch = globalThis.fetch;
  const calls = [];

  globalThis.fetch = async (url, options) => {
    calls.push({ url, options });

    return {
      ok: true,
      status: 200,
      async text() {
        return JSON.stringify({ status: "linked" });
      }
    };
  };

  try {
    await linkWorkspace({
      server: "https://devboard.test/",
      token: "token-1",
      deviceId: "device-1",
      deviceSecret,
      repositoryId: "repo-1",
      workspace: {
        local_root_hash: "sha256:root",
        display_path: "/tmp/repo",
        current_branch: "main",
        last_head_sha: "abc123",
        dirty_status: "clean",
        remote_name: "origin",
        remote_url_host: "github.com",
        remote_url_hash: `sha256:${"a".repeat(64)}`,
        upstream_branch: "origin/main",
        ahead_count: 1,
        behind_count: 0,
        git_state_observed_at: "2026-06-25T16:30:00Z"
      }
    });
  } finally {
    globalThis.fetch = originalFetch;
  }

  assert.equal(calls.length, 1);
  assert.equal(calls[0].url, "https://devboard.test/api/plugin/v1/repositories/repo-1/local-workspaces");
  assert.equal(calls[0].options.headers["X-DevBoard-Device-Id"], "device-1");
  assert.match(calls[0].options.headers["X-DevBoard-Signature"], /^v1=[a-f0-9]{64}$/);

  const body = JSON.parse(calls[0].options.body);

  assert.equal(body.protocol_version, "v1");
  assert.equal(body.remote_url_host, "github.com");
  assert.equal(body.upstream_branch, "origin/main");
  assert.equal(body.ahead_count, 1);
  assert.equal(body.behind_count, 0);
});

test("createDeviceSignature matches the backend and Python canonical format", () => {
  const bodyBytes = Buffer.from('{"protocol_version":"v1","name":"café"}');
  const method = "POST";
  const pathWithQuery = "/api/plugin/v1/projects?after=a%2Fb&limit=10";
  const timestamp = 1_720_000_000;
  const contentSha256 = createHash("sha256").update(bodyBytes).digest("hex");
  const signingKey = createHash("sha256").update(deviceSecret).digest("hex");
  const canonical = `${method}\n${pathWithQuery}\n${timestamp}\n${contentSha256}`;
  const expectedSignature = `v1=${createHmac("sha256", signingKey).update(canonical).digest("hex")}`;

  assert.deepEqual(createDeviceSignature({ method, pathWithQuery, timestamp, bodyBytes, deviceSecret }), {
    contentSha256,
    signature: expectedSignature
  });
});

test("requestJson signs the exact request URI including base path and query", async () => {
  const originalFetch = globalThis.fetch;
  let captured;

  globalThis.fetch = async (url, options) => {
    captured = { url, options };
    return { ok: true, status: 200, text: async () => '{"ok":true}' };
  };

  try {
    await requestJson("https://devboard.test/base", "/api/plugin/v1/items?cursor=a%2Fb", {
      method: "GET",
      token: "token-1",
      deviceId: "device-1",
      deviceSecret,
      now: () => 1_720_000_000_000
    });
  } finally {
    globalThis.fetch = originalFetch;
  }

  const expected = createDeviceSignature({
    method: "GET",
    pathWithQuery: "/base/api/plugin/v1/items?cursor=a%2Fb",
    timestamp: 1_720_000_000,
    bodyBytes: Buffer.alloc(0),
    deviceSecret
  });

  assert.equal(captured.url, "https://devboard.test/base/api/plugin/v1/items?cursor=a%2Fb");
  assert.equal(captured.options.headers["X-DevBoard-Content-SHA256"], expected.contentSha256);
  assert.equal(captured.options.headers["X-DevBoard-Signature"], expected.signature);
});

test("normalizeServerUrl requires HTTPS except for explicit loopback hosts", () => {
  assert.equal(normalizeServerUrl("http://127.0.0.1:8000/"), "http://127.0.0.1:8000");
  assert.equal(normalizeServerUrl("http://[::1]:8000"), "http://[::1]:8000");
  assert.equal(normalizeServerUrl("https://devboard.test/"), "https://devboard.test");
  assert.throws(() => normalizeServerUrl("http://devboard.test"), /requires HTTPS/);
  assert.throws(() => normalizeServerUrl("http://0.0.0.0:8000"), /requires HTTPS/);
  assert.throws(() => normalizeServerUrl("https://token@devboard.test"), /must not contain credentials/);
});

test("requestJson rejects invalid and empty JSON on successful responses", async () => {
  const originalFetch = globalThis.fetch;

  try {
    for (const responseText of ["not-json", ""]) {
      globalThis.fetch = async () => ({ ok: true, status: 200, text: async () => responseText });
      await assert.rejects(
        requestJson("https://devboard.test", "/api/plugin/v1/test", { token: "token-1" }),
        DevBoardProtocolError
      );
    }
  } finally {
    globalThis.fetch = originalFetch;
  }
});

test("requestJson enforces timeout and caller AbortSignal", async () => {
  const originalFetch = globalThis.fetch;
  globalThis.fetch = async (_url, options) => new Promise((_resolve, reject) => {
    options.signal.addEventListener("abort", () => reject(options.signal.reason), { once: true });
  });

  try {
    await assert.rejects(
      requestJson("https://devboard.test", "/api/plugin/v1/test", { token: "token-1", timeoutMs: 5 }),
      /timed out/
    );

    const controller = new AbortController();
    const pending = requestJson("https://devboard.test", "/api/plugin/v1/test", {
      token: "token-1",
      signal: controller.signal
    });
    controller.abort(new Error("caller cancelled"));
    await assert.rejects(pending, /caller cancelled/);
  } finally {
    globalThis.fetch = originalFetch;
  }
});
