import { createHash, createHmac } from "node:crypto";
import os from "node:os";

export const PROTOCOL_VERSION = "v1";
export const PLUGIN_VERSION = "0.1.0";
export const DEFAULT_TIMEOUT_MS = 30_000;

export class DevBoardHttpError extends Error {
  constructor(message, status, body) {
    super(message);
    this.name = "DevBoardHttpError";
    this.status = status;
    this.body = body;
  }
}

export class DevBoardProtocolError extends Error {
  constructor(message, status = null) {
    super(message);
    this.name = "DevBoardProtocolError";
    this.status = status;
  }
}

function isLoopbackHostname(hostname) {
  const normalized = hostname.toLowerCase().replace(/^\[|\]$/g, "");
  return normalized === "localhost" || normalized === "::1" || /^127(?:\.\d{1,3}){3}$/.test(normalized);
}

export function normalizeServerUrl(server) {
  if (!server) {
    throw new Error("Missing --server.");
  }

  let url;

  try {
    url = new URL(server);
  } catch {
    throw new Error("Invalid --server URL.");
  }

  if (url.username || url.password) {
    throw new Error("Server URL must not contain credentials.");
  }

  if (url.search || url.hash) {
    throw new Error("Server URL must not contain a query or fragment.");
  }

  if (url.protocol !== "https:" && !(url.protocol === "http:" && isLoopbackHostname(url.hostname))) {
    throw new Error("DevBoard requires HTTPS; HTTP is allowed only for an explicit loopback host.");
  }

  url.pathname = url.pathname.replace(/\/+$/, "");

  return url.toString().replace(/\/$/, "");
}

function buildUrl(server, pathname) {
  if (typeof pathname !== "string" || !pathname.startsWith("/")) {
    throw new Error("Request path must start with '/'.");
  }

  return new URL(`${normalizeServerUrl(server)}${pathname}`);
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
    if (response.ok) {
      throw new DevBoardProtocolError("DevBoard returned an empty successful response.", response.status);
    }

    return {};
  }

  try {
    return JSON.parse(text);
  } catch {
    if (response.ok) {
      throw new DevBoardProtocolError("DevBoard returned invalid JSON in a successful response.", response.status);
    }

    return { error: { code: "invalid_json_response", message: text } };
  }
}

export function createDeviceSignature({ method, pathWithQuery, timestamp, bodyBytes, deviceSecret }) {
  const contentSha256 = createHash("sha256").update(bodyBytes).digest("hex");
  const signingKey = createHash("sha256").update(deviceSecret).digest("hex");
  const canonical = `${method}\n${pathWithQuery}\n${timestamp}\n${contentSha256}`;
  const signature = `v1=${createHmac("sha256", signingKey).update(canonical).digest("hex")}`;

  return { contentSha256, signature };
}

export async function requestJson(server, pathname, {
  method = "GET",
  token,
  deviceId,
  deviceSecret,
  headers = {},
  body,
  signal,
  timeoutMs = DEFAULT_TIMEOUT_MS,
  now = () => Date.now()
} = {}) {
  const requestUrl = buildUrl(server, pathname);
  const requestMethod = method.toUpperCase();
  const requestHeaders = baseHeaders(token, headers);
  const bodyText = body === undefined ? "" : JSON.stringify(body);
  const bodyBytes = Buffer.from(bodyText);

  if (!Number.isFinite(timeoutMs) || timeoutMs <= 0) {
    throw new Error("Request timeout must be a positive number.");
  }

  const controller = new AbortController();
  const abortFromCaller = () => controller.abort(signal.reason);
  const timer = setTimeout(() => controller.abort(new Error(`DevBoard request timed out after ${timeoutMs}ms.`)), timeoutMs);

  if ((deviceId && !deviceSecret) || (!deviceId && deviceSecret)) {
    clearTimeout(timer);
    throw new Error("Both device ID and device secret are required for signed requests.");
  }

  if (deviceId && deviceSecret) {
    const timestamp = Math.floor(now() / 1000);
    const pathWithQuery = `${requestUrl.pathname}${requestUrl.search}`;
    const signed = createDeviceSignature({
      method: requestMethod,
      pathWithQuery,
      timestamp,
      bodyBytes,
      deviceSecret
    });

    requestHeaders["X-DevBoard-Device-Id"] = deviceId;
    requestHeaders["X-DevBoard-Timestamp"] = String(timestamp);
    requestHeaders["X-DevBoard-Content-SHA256"] = signed.contentSha256;
    requestHeaders["X-DevBoard-Signature"] = signed.signature;
  }

  const options = { method: requestMethod, headers: requestHeaders, signal: controller.signal };

  if (body !== undefined) {
    requestHeaders["Content-Type"] = "application/json";
    options.body = bodyText;
  }

  if (signal?.aborted) {
    abortFromCaller();
  } else {
    signal?.addEventListener("abort", abortFromCaller, { once: true });
  }

  let response;
  let responseBody;

  try {
    response = await fetch(requestUrl.toString(), options);
    responseBody = await parseJsonResponse(response);
  } finally {
    clearTimeout(timer);
    signal?.removeEventListener("abort", abortFromCaller);
  }

  if (!response.ok) {
    throw new DevBoardHttpError(`DevBoard request failed with HTTP ${response.status}.`, response.status, responseBody);
  }

  return responseBody;
}

export function authCheck({ server, token, deviceId, deviceSecret, ...requestOptions }) {
  return requestJson(server, "/api/plugin/v1/auth/check", {
    method: "POST",
    token,
    deviceId,
    deviceSecret,
    ...requestOptions,
    body: {
      protocol_version: PROTOCOL_VERSION
    }
  });
}

export function registerDevice({ server, token, deviceId, deviceSecret, name, ...requestOptions }) {
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
    deviceId,
    deviceSecret,
    ...requestOptions,
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

export function linkWorkspace({ server, token, deviceId, deviceSecret, repositoryId, workspace, ...requestOptions }) {
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
    deviceId,
    deviceSecret,
    ...requestOptions,
    body: {
      protocol_version: PROTOCOL_VERSION,
      ...workspace
    }
  });
}
