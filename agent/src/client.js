import { createHash } from "node:crypto";
import os from "node:os";

export const PROTOCOL_VERSION = "v1";
export const PLUGIN_VERSION = "0.1.0";

export class DevBoardHttpError extends Error {
  constructor(message, status, body) {
    super(message);
    this.name = "DevBoardHttpError";
    this.status = status;
    this.body = body;
  }
}

export function normalizeServerUrl(server) {
  if (!server) {
    throw new Error("Missing --server.");
  }

  const url = new URL(server);
  url.hash = "";
  url.search = "";
  url.pathname = url.pathname.replace(/\/+$/, "");

  return url.toString().replace(/\/$/, "");
}

function buildUrl(server, pathname) {
  return `${normalizeServerUrl(server)}${pathname}`;
}

function baseHeaders(token, extraHeaders = {}) {
  if (!token) {
    throw new Error("Missing --token.");
  }

  return {
    Accept: "application/json",
    Authorization: `Bearer ${token}`,
    "X-DevBoard-Protocol": PROTOCOL_VERSION,
    "X-DevBoard-Plugin-Version": PLUGIN_VERSION,
    ...extraHeaders
  };
}

async function parseJsonResponse(response) {
  const text = await response.text();

  if (text.length === 0) {
    return {};
  }

  try {
    return JSON.parse(text);
  } catch {
    return { error: { code: "invalid_json_response", message: text } };
  }
}

export async function requestJson(server, pathname, { method, token, headers = {}, body } = {}) {
  const requestHeaders = baseHeaders(token, headers);
  const options = { method, headers: requestHeaders };

  if (body !== undefined) {
    requestHeaders["Content-Type"] = "application/json";
    options.body = JSON.stringify(body);
  }

  const response = await fetch(buildUrl(server, pathname), options);
  const responseBody = await parseJsonResponse(response);

  if (!response.ok) {
    throw new DevBoardHttpError(`DevBoard request failed with HTTP ${response.status}.`, response.status, responseBody);
  }

  return responseBody;
}

export function authCheck({ server, token }) {
  return requestJson(server, "/api/plugin/v1/auth/check", {
    method: "POST",
    token,
    body: {
      protocol_version: PROTOCOL_VERSION
    }
  });
}

export function registerDevice({ server, token, name }) {
  if (!name) {
    throw new Error("Missing --name.");
  }

  const platformOs = os.platform();
  const platformArch = os.arch();
  const user = os.userInfo().username;
  const fingerprintHash = createHash("sha256")
    .update(`${os.hostname()}:${user}:${platformOs}:${platformArch}`)
    .digest("hex");

  return requestJson(server, "/api/plugin/v1/devices/register", {
    method: "POST",
    token,
    body: {
      protocol_version: PROTOCOL_VERSION,
      name,
      fingerprint_hash: `sha256:${fingerprintHash}`,
      platform_os: platformOs,
      platform_arch: platformArch,
      plugin_version: PLUGIN_VERSION
    }
  });
}

export function linkWorkspace({ server, token, deviceId, repositoryId, workspace }) {
  if (!deviceId) {
    throw new Error("Missing --device-id.");
  }

  if (!repositoryId) {
    throw new Error("Missing --repository-id.");
  }

  if (!workspace) {
    throw new Error("Missing workspace probe result.");
  }

  return requestJson(server, `/api/plugin/v1/repositories/${encodeURIComponent(repositoryId)}/local-workspaces`, {
    method: "POST",
    token,
    headers: {
      "X-DevBoard-Device-Id": deviceId
    },
    body: {
      protocol_version: PROTOCOL_VERSION,
      ...workspace
    }
  });
}
