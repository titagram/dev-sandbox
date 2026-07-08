from __future__ import annotations

from dataclasses import dataclass
from pathlib import Path


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

        if path.name == ".env" or relative.endswith("/.env"):
            blocked.append({"path": relative, "reason": "env_file"})
            continue

        try:
            content = path.read_text(errors="ignore")
        except OSError:
            content = ""

        if "-----BEGIN PRIVATE KEY-----" in content:
            blocked.append({"path": relative, "reason": "private_key"})
            continue

        if parts.intersection({"vendor", "cache", "build", "dist", "generated"}):
            warnings.append({"path": relative, "reason": "generated_or_dependency_path"})

    return SafetyReport(blocked=blocked, warnings=warnings)
