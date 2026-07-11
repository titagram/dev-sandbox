import { randomUUID } from "node:crypto";
import { constants } from "node:fs";
import { lstat, mkdir, open, realpath, rename, rm } from "node:fs/promises";
import os from "node:os";
import path from "node:path";

const NO_FOLLOW = constants.O_NOFOLLOW ?? 0;

function unsafePathError() {
  return new Error("Unsafe credentials path; symbolic links are not allowed.");
}

function isMissing(error) {
  return error?.code === "ENOENT";
}

function assertContained(parent, candidate) {
  const relative = path.relative(parent, candidate);

  if (relative === "" || relative === ".." || relative.startsWith(`..${path.sep}`) || path.isAbsolute(relative)) {
    throw unsafePathError();
  }
}

async function inspectPath(absolutePath) {
  const parsed = path.parse(absolutePath);
  const parts = absolutePath.slice(parsed.root.length).split(path.sep).filter(Boolean);
  let current = parsed.root;

  for (let index = 0; index < parts.length; index += 1) {
    current = path.join(current, parts[index]);
    let metadata;

    try {
      metadata = await lstat(current);
    } catch (error) {
      if (isMissing(error)) {
        return { exists: false };
      }

      throw error;
    }

    if (metadata.isSymbolicLink()) {
      throw unsafePathError();
    }

    if (index < parts.length - 1 && !metadata.isDirectory()) {
      throw unsafePathError();
    }
  }

  return { exists: true };
}

async function ensureSafeDirectory(directory) {
  const absoluteDirectory = path.resolve(directory);
  const parsed = path.parse(absoluteDirectory);
  const parts = absoluteDirectory.slice(parsed.root.length).split(path.sep).filter(Boolean);
  let current = parsed.root;

  for (const part of parts) {
    current = path.join(current, part);
    let metadata;

    try {
      metadata = await lstat(current);
    } catch (error) {
      if (!isMissing(error)) {
        throw error;
      }

      try {
        await mkdir(current, { mode: 0o700 });
      } catch (mkdirError) {
        if (mkdirError?.code !== "EEXIST") {
          throw mkdirError;
        }
      }

      metadata = await lstat(current);
    }

    if (metadata.isSymbolicLink() || !metadata.isDirectory()) {
      throw unsafePathError();
    }
  }

  const canonicalDirectory = await realpath(absoluteDirectory);
  const relative = path.relative(canonicalDirectory, absoluteDirectory);

  if (relative !== "") {
    throw unsafePathError();
  }

  return canonicalDirectory;
}

export function credentialsPath({ override = process.env.DEVBOARD_CREDENTIALS_PATH, home = os.homedir() } = {}) {
  if (!override) {
    return path.join(home, ".config", "devboard", "credentials.json");
  }

  if (override === "~") {
    return home;
  }

  if (override.startsWith("~/")) {
    return path.join(home, override.slice(2));
  }

  return path.resolve(override);
}

function parseCredentials(data, filePath) {
  if (!data || typeof data !== "object" || Array.isArray(data)) {
    throw new Error(`Invalid credentials file at ${filePath}.`);
  }

  if (typeof data.server_url !== "string" || !data.server_url || typeof data.token !== "string" || !data.token) {
    throw new Error(`Invalid credentials file at ${filePath}.`);
  }

  for (const key of ["device_id", "device_secret"]) {
    if (data[key] !== undefined && data[key] !== null && (typeof data[key] !== "string" || !data[key])) {
      throw new Error(`Invalid credentials file at ${filePath}.`);
    }
  }

  return {
    server_url: data.server_url,
    token: data.token,
    device_id: data.device_id ?? null,
    device_secret: data.device_secret ?? null
  };
}

export async function loadCredentials(filePath = credentialsPath()) {
  const absolutePath = path.resolve(filePath);
  let contents;
  let handle;

  try {
    const inspected = await inspectPath(absolutePath);
    if (!inspected.exists) {
      return null;
    }

    const parent = await realpath(path.dirname(absolutePath));
    const canonicalPath = await realpath(absolutePath);
    assertContained(parent, canonicalPath);

    handle = await open(absolutePath, constants.O_RDONLY | NO_FOLLOW);
    const metadata = await handle.stat();
    if (!metadata.isFile()) {
      throw unsafePathError();
    }

    const pathMetadata = await lstat(absolutePath);
    if (
      pathMetadata.isSymbolicLink()
      || !pathMetadata.isFile()
      || pathMetadata.dev !== metadata.dev
      || pathMetadata.ino !== metadata.ino
    ) {
      throw unsafePathError();
    }

    await inspectPath(path.dirname(absolutePath));

    contents = await handle.readFile("utf8");
  } catch (error) {
    if (isMissing(error)) {
      return null;
    }

    if (["ELOOP", "ENOTDIR"].includes(error?.code)) {
      throw unsafePathError();
    }

    throw error;
  } finally {
    await handle?.close().catch(() => {});
  }

  try {
    return parseCredentials(JSON.parse(contents), absolutePath);
  } catch (error) {
    if (error instanceof SyntaxError) {
      throw new Error(`Invalid JSON in credentials file at ${filePath}.`);
    }

    throw error;
  }
}

export async function saveCredentials(credentials, filePath = credentialsPath()) {
  const absolutePath = path.resolve(filePath);
  const validated = parseCredentials(credentials, absolutePath);
  const directory = await ensureSafeDirectory(path.dirname(absolutePath));
  const targetPath = path.join(directory, path.basename(absolutePath));
  const temporaryPath = path.join(directory, `.${path.basename(absolutePath)}.${process.pid}.${randomUUID()}.tmp`);
  const contents = `${JSON.stringify(validated, null, 2)}\n`;
  let handle;

  assertContained(directory, targetPath);
  assertContained(directory, temporaryPath);

  try {
    const inspectedTarget = await inspectPath(targetPath);
    if (inspectedTarget.exists) {
      const targetMetadata = await lstat(targetPath);
      if (!targetMetadata.isFile()) {
        throw unsafePathError();
      }
    }

    handle = await open(
      temporaryPath,
      constants.O_WRONLY | constants.O_CREAT | constants.O_EXCL | NO_FOLLOW,
      0o600
    );
    const temporaryMetadata = await handle.stat();
    if (!temporaryMetadata.isFile()) {
      throw unsafePathError();
    }

    const temporaryPathMetadata = await lstat(temporaryPath);
    if (
      temporaryPathMetadata.isSymbolicLink()
      || temporaryPathMetadata.dev !== temporaryMetadata.dev
      || temporaryPathMetadata.ino !== temporaryMetadata.ino
    ) {
      throw unsafePathError();
    }

    await inspectPath(directory);

    await handle.writeFile(contents, "utf8");
    await handle.chmod(0o600);
    await handle.sync();
    await handle.close();
    handle = null;

    await ensureSafeDirectory(directory);
    const finalTarget = await inspectPath(targetPath);
    if (finalTarget.exists && !(await lstat(targetPath)).isFile()) {
      throw unsafePathError();
    }

    await rename(temporaryPath, targetPath);
  } catch (error) {
    await handle?.close().catch(() => {});
    await rm(temporaryPath, { force: true }).catch(() => {});

    if (["ELOOP", "ENOTDIR"].includes(error?.code)) {
      throw unsafePathError();
    }

    throw error;
  }

  return targetPath;
}
