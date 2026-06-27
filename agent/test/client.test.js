import assert from "node:assert/strict";
import test from "node:test";

import { linkWorkspace } from "../src/client.js";

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

  const body = JSON.parse(calls[0].options.body);

  assert.equal(body.protocol_version, "v1");
  assert.equal(body.remote_url_host, "github.com");
  assert.equal(body.upstream_branch, "origin/main");
  assert.equal(body.ahead_count, 1);
  assert.equal(body.behind_count, 0);
});
