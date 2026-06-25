#!/usr/bin/env node

import { authCheck, DevBoardHttpError, linkWorkspace, registerDevice } from "../src/client.js";
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
  process.stdout.write(`${JSON.stringify(value, null, 2)}\n`);
}

function usage() {
  return [
    "Usage:",
    "  devboard-agent auth-check --server URL --token TOKEN",
    "  devboard-agent register-device --server URL --token TOKEN --name NAME",
    "  devboard-agent link-workspace --server URL --token TOKEN --device-id ID --repository-id ID --path PATH"
  ].join("\n");
}

async function main(argv) {
  const { command, options } = parseArgs(argv);

  if (command === "auth-check") {
    return authCheck({
      server: requireOption(options, "server"),
      token: requireOption(options, "token")
    });
  }

  if (command === "register-device") {
    return registerDevice({
      server: requireOption(options, "server"),
      token: requireOption(options, "token"),
      name: requireOption(options, "name")
    });
  }

  if (command === "link-workspace") {
    const workspace = probeGitWorkspace(requireOption(options, "path"));

    return linkWorkspace({
      server: requireOption(options, "server"),
      token: requireOption(options, "token"),
      deviceId: requireOption(options, "device-id"),
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
