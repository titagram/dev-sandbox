#!/usr/bin/env node

import { authCheck, DevBoardHttpError, linkWorkspace, normalizeServerUrl, registerDevice } from "../src/client.js";
import { credentialsPath, loadCredentials, saveCredentials } from "../src/credentials.js";
import { probeGitWorkspace } from "../src/probe.js";

function parseArgs(argv) {
  const [command, ...rest] = argv;
  const options = {};

  for (let index = 0; index < rest.length; index += 1) {
    const arg = rest[index];

    if (!arg.startsWith("--")) {
      throw new Error("Unexpected positional argument.");
    }

    const key = arg.slice(2);

    if (!/^[a-z][a-z0-9-]*$/.test(key)) {
      throw new Error("Invalid option syntax. Use --name value arguments.");
    }

    const value = rest[index + 1];

    if (!value || value.startsWith("--")) {
      throw new Error(`Missing value for --${key}.`);
    }

    options[key] = value;
    index += 1;
  }

  return { command, options };
}

function requireOption(options, key) {
  const value = options[key];

  if (!value) {
    throw new Error(`Missing --${key}.`);
  }

  return value;
}

function printJson(value) {
  process.stdout.write(`${JSON.stringify(redactCredentials(value), null, 2)}\n`);
}

function redactCredentials(value) {
  if (Array.isArray(value)) {
    return value.map(redactCredentials);
  }

  if (!value || typeof value !== "object") {
    return value;
  }

  return Object.fromEntries(Object.entries(value).map(([key, entry]) => {
    if (["server", "server_url", "device_id", "token", "device_secret", "authorization"].includes(key.toLowerCase())) {
      return [key, "[redacted]"];
    }

    return [key, redactCredentials(entry)];
  }));
}

function usage() {
  return [
    "Usage:",
    "  devboard-agent auth-check [--server URL] [--token TOKEN] [--credentials-path PATH]",
    "  devboard-agent register-device --name NAME [--server URL] [--token TOKEN] [--credentials-path PATH]",
    "  devboard-agent link-workspace --repository-id ID --path PATH [--server URL] [--token TOKEN] [--device-id ID] [--credentials-path PATH]",
    "  devboard-agent refresh-workspace --repository-id ID --path PATH [--server URL] [--token TOKEN] [--device-id ID] [--credentials-path PATH]"
  ].join("\n");
}

async function resolveCredentials(options, { requireDevice = false } = {}) {
  const filePath = credentialsPath({ override: options["credentials-path"] });
  const loaded = await loadCredentials(filePath);
  const explicitServer = options.server;
  const explicitToken = options.token;
  const server = explicitServer || loaded?.server_url;

  if (!server) {
    throw new Error("Missing --server and no stored credentials were found.");
  }

  const normalizedServer = normalizeServerUrl(server);
  const loadedServer = loaded ? normalizeServerUrl(loaded.server_url) : null;
  const sameServer = loadedServer === normalizedServer;

  if (!explicitToken && loaded && !sameServer) {
    throw new Error("Stored credentials belong to a different server; provide --token explicitly.");
  }

  const token = explicitToken || (sameServer ? loaded?.token : null);
  if (!token) {
    throw new Error("Missing --token and no matching stored token was found.");
  }

  const canReuseDevice = Boolean(loaded && sameServer && (!explicitToken || explicitToken === loaded.token));
  const storedDeviceId = canReuseDevice ? loaded.device_id : null;
  const deviceId = options["device-id"] || storedDeviceId;
  let deviceSecret = canReuseDevice ? loaded.device_secret : null;

  if (options["device-id"] && options["device-id"] !== storedDeviceId) {
    deviceSecret = null;
  }

  if ((deviceId && !deviceSecret) || (!deviceId && deviceSecret)) {
    throw new Error("Device credentials are incomplete. Register this device to obtain its one-time secret.");
  }

  if (requireDevice && (!deviceId || !deviceSecret)) {
    throw new Error("No paired device credentials found. Run register-device first.");
  }

  return {
    filePath,
    credentials: {
      server_url: normalizedServer,
      token,
      device_id: deviceId || null,
      device_secret: deviceSecret || null
    }
  };
}

function clientOptions(credentials) {
  return {
    server: credentials.server_url,
    token: credentials.token,
    deviceId: credentials.device_id,
    deviceSecret: credentials.device_secret
  };
}

async function main(argv) {
  const { command, options } = parseArgs(argv);

  if (command === "auth-check") {
    const resolved = await resolveCredentials(options);
    const response = await authCheck(clientOptions(resolved.credentials));
    await saveCredentials(resolved.credentials, resolved.filePath);

    return {
      protocol_version: response.protocol_version,
      authenticated: response.authenticated,
      server_time: response.server_time,
      credentials_saved: true
    };
  }

  if (command === "register-device") {
    const resolved = await resolveCredentials(options);
    const response = await registerDevice({
      ...clientOptions(resolved.credentials),
      name: requireOption(options, "name")
    });
    const deviceId = response.device_id;
    const returnedSecret = response.device_secret;

    if (typeof deviceId !== "string" || !deviceId) {
      throw new Error("Device registration response did not include a valid device ID.");
    }

    if (returnedSecret !== undefined && (typeof returnedSecret !== "string" || !/^[a-f0-9]{64}$/i.test(returnedSecret))) {
      throw new Error("Device registration response included an invalid device secret.");
    }

    const deviceSecret = returnedSecret || (
      resolved.credentials.device_id === deviceId ? resolved.credentials.device_secret : null
    );

    if (!deviceSecret) {
      throw new Error("The server did not return the one-time secret for this existing device; local pairing cannot be recovered securely.");
    }

    await saveCredentials({
      ...resolved.credentials,
      device_id: deviceId,
      device_secret: deviceSecret
    }, resolved.filePath);

    return {
      status: response.status,
      server_time: response.server_time,
      credentials_saved: true
    };
  }

  if (command === "link-workspace") {
    const resolved = await resolveCredentials(options, { requireDevice: true });
    const workspace = probeGitWorkspace(requireOption(options, "path"));

    return linkWorkspace({
      ...clientOptions(resolved.credentials),
      repositoryId: requireOption(options, "repository-id"),
      workspace
    });
  }

  if (command === "refresh-workspace") {
    const resolved = await resolveCredentials(options, { requireDevice: true });
    const workspace = probeGitWorkspace(requireOption(options, "path"));

    return linkWorkspace({
      ...clientOptions(resolved.credentials),
      repositoryId: requireOption(options, "repository-id"),
      workspace
    });
  }

  throw new Error(command ? "Unknown command." : "Missing command.");
}

main(process.argv.slice(2))
  .then(printJson)
  .catch((error) => {
    if (error instanceof DevBoardHttpError) {
      printJson({
        error: {
          code: "http_error",
          message: error.message,
          status: error.status,
          response: error.body
        }
      });
    } else {
      process.stderr.write(`${error.message}\n\n${usage()}\n`);
    }

    process.exitCode = 1;
  });
