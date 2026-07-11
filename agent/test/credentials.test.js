import assert from "node:assert/strict";
import { mkdir, mkdtemp, readFile, readdir, rm, stat, symlink, writeFile } from "node:fs/promises";
import os from "node:os";
import path from "node:path";
import test from "node:test";

import { credentialsPath, loadCredentials, saveCredentials } from "../src/credentials.js";

const credentials = {
  server_url: "https://devboard.test",
  token: "devb_live_token|secret",
  device_id: "device-01",
  device_secret: "b".repeat(64)
};

test("credentialsPath uses the shared default and supports an override", () => {
  assert.equal(
    credentialsPath({ home: "/home/tester", override: undefined }),
    "/home/tester/.config/devboard/credentials.json"
  );
  assert.equal(
    credentialsPath({ home: "/home/tester", override: "~/.secrets/devboard.json" }),
    "/home/tester/.secrets/devboard.json"
  );
});

test("saveCredentials atomically writes a 0600 file and preserves all pairing fields", async () => {
  const root = await mkdtemp(path.join(os.tmpdir(), "devboard-agent-credentials-"));
  const filePath = path.join(root, "config", "credentials.json");

  try {
    await saveCredentials(credentials, filePath);
    const loaded = await loadCredentials(filePath);
    const metadata = await stat(filePath);
    const entries = await readdir(path.dirname(filePath));

    assert.deepEqual(loaded, credentials);
    assert.equal(metadata.mode & 0o777, 0o600);
    assert.deepEqual(entries, ["credentials.json"]);

    const serialized = JSON.parse(await readFile(filePath, "utf8"));
    assert.equal(serialized.token, credentials.token);
    assert.equal(serialized.device_secret, credentials.device_secret);
  } finally {
    await rm(root, { recursive: true, force: true });
  }
});

test("loadCredentials distinguishes missing and malformed files without exposing contents", async () => {
  const root = await mkdtemp(path.join(os.tmpdir(), "devboard-agent-credentials-invalid-"));
  const filePath = path.join(root, "credentials.json");

  try {
    assert.equal(await loadCredentials(filePath), null);
    await writeFile(filePath, '{"token":"secret-value"');
    await assert.rejects(loadCredentials(filePath), (error) => {
      assert.match(error.message, /Invalid JSON/);
      assert.doesNotMatch(error.message, /secret-value/);
      return true;
    });
  } finally {
    await rm(root, { recursive: true, force: true });
  }
});

test("loadCredentials and saveCredentials reject a credential file symlink", async () => {
  const root = await mkdtemp(path.join(os.tmpdir(), "devboard-agent-credentials-file-link-"));
  const realFile = path.join(root, "real-credentials.json");
  const linkedFile = path.join(root, "credentials.json");

  try {
    await writeFile(realFile, `${JSON.stringify(credentials)}\n`, { mode: 0o600 });
    await symlink(realFile, linkedFile, "file");

    await assert.rejects(loadCredentials(linkedFile), /Unsafe credentials path/);
    await assert.rejects(saveCredentials(credentials, linkedFile), /Unsafe credentials path/);
    assert.equal(JSON.parse(await readFile(realFile, "utf8")).token, credentials.token);
  } finally {
    await rm(root, { recursive: true, force: true });
  }
});

test("loadCredentials and saveCredentials reject symlinks in parent directories", async () => {
  const root = await mkdtemp(path.join(os.tmpdir(), "devboard-agent-credentials-parent-link-"));
  const realDirectory = path.join(root, "real-config");
  const linkedDirectory = path.join(root, "linked-config");
  const realFile = path.join(realDirectory, "credentials.json");
  const linkedFile = path.join(linkedDirectory, "credentials.json");

  try {
    await mkdir(realDirectory, { mode: 0o700 });
    await writeFile(realFile, `${JSON.stringify(credentials)}\n`, { mode: 0o600 });
    await symlink(realDirectory, linkedDirectory, "dir");

    await assert.rejects(loadCredentials(linkedFile), /Unsafe credentials path/);
    await assert.rejects(saveCredentials(credentials, linkedFile), /Unsafe credentials path/);
    assert.equal(JSON.parse(await readFile(realFile, "utf8")).device_secret, credentials.device_secret);
  } finally {
    await rm(root, { recursive: true, force: true });
  }
});
