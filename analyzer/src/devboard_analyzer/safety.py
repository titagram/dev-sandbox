from __future__ import annotations

from dataclasses import dataclass
import fnmatch
from pathlib import Path
import re


PRIVATE_KEY_PEM_HEADER = re.compile(
    r"-----BEGIN (?:(?:[A-Z0-9][A-Z0-9 ._-]* )?PRIVATE KEY(?: BLOCK)?)-----"
)


@dataclass(frozen=True)
class SafetyReport:
    blocked: list[dict[str, str]]
    warnings: list[dict[str, str]]


def scan_safety(root: Path | str, paths: list[Path]) -> SafetyReport:
    base = Path(root)
    blocked: list[dict[str, str]] = []
    warnings: list[dict[str, str]] = []

    for path in paths:
        relative = path.relative_to(base).as_posix()
        parts = set(path.relative_to(base).parts)

        filename = path.name.lower()
        if filename == ".env" or filename.startswith(".env."):
            blocked.append({"path": relative, "reason": "env_file"})
            continue

        credential_reason = _blocked_filename_reason(filename)
        if credential_reason:
            blocked.append({"path": relative, "reason": credential_reason})
            continue

        try:
            content = path.read_text(errors="ignore")
        except OSError:
            content = ""

        if PRIVATE_KEY_PEM_HEADER.search(content):
            blocked.append({"path": relative, "reason": "private_key"})
            continue

        if parts.intersection({"vendor", "cache", "build", "dist", "generated"}):
            warnings.append({"path": relative, "reason": "generated_or_dependency_path"})

    return SafetyReport(blocked=blocked, warnings=warnings)


def _blocked_filename_reason(filename: str) -> str | None:
    if filename.endswith((".key", ".pem", ".p12", ".pfx", ".jks")) or filename in {
        "id_rsa",
        "id_dsa",
        "id_ecdsa",
        "id_ed25519",
    }:
        return "private_key"
    if filename.endswith((".crt", ".cer", ".der")):
        return "certificate"
    credential_patterns = (
        "credentials.*",
        "credential.*",
        "*-credentials.*",
        "*-credential.*",
        "*token*.*",
        "service-account*.json",
        "service_account*.json",
        ".npmrc",
        ".pypirc",
        ".netrc",
    )
    if any(fnmatch.fnmatch(filename, pattern) for pattern in credential_patterns):
        return "credential_file"
    return None
