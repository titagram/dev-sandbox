#!/usr/bin/env python3

from __future__ import annotations

import json
import subprocess
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
OUT = ROOT / "config" / "environment.yaml"
ARCH_ALIASES = {
    "aarch64": "arm64",
    "x86_64": "amd64",
}


def run_command(command: list[str], default: str = "") -> str:
    try:
        result = subprocess.run(command, text=True, capture_output=True, check=False)
    except FileNotFoundError:
        return default
    if result.returncode != 0:
        return default
    return result.stdout.strip()


def parse_docker_platform(value: str) -> tuple[str, str]:
    if "/" not in value:
        return "", ""
    os_name, arch = value.split("/", 1)
    clean_arch = arch.strip()
    return os_name.strip(), ARCH_ALIASES.get(clean_arch, clean_arch)


def snapshot_environment() -> dict[str, str]:
    host_os = run_command(["uname", "-s"])
    host_arch = run_command(["uname", "-m"])
    python = run_command(["python3", "--version"])
    docker_os, docker_arch = parse_docker_platform(
        run_command(["docker", "info", "--format", "{{.OSType}}/{{.Architecture}}"])
    )
    git_root = run_command(["git", "rev-parse", "--show-toplevel"])
    return {
        "host_os": host_os,
        "host_arch": host_arch,
        "python": python,
        "docker_os": docker_os,
        "docker_arch": docker_arch,
        "git_root": git_root,
    }


def write_yaml(data: dict[str, str], path: Path = OUT) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    lines = ["environment:"]
    for key, value in data.items():
        escaped = str(value).replace('"', '\\"')
        lines.append(f'  {key}: "{escaped}"')
    path.write_text("\n".join(lines) + "\n")


def main() -> int:
    data = snapshot_environment()
    write_yaml(data)
    print(json.dumps(data, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
