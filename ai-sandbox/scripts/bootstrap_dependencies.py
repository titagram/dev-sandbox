#!/usr/bin/env python3

from __future__ import annotations

import subprocess
import shlex
import shutil
import sys
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
LOCK_FILE = ROOT / "config" / "dependencies.lock.yaml"
ENV_FILE = ROOT / "config" / "environment.yaml"
WHEEL_DIR = ROOT / "vendor" / "python" / "wheels"
VENV_DIR = ROOT / ".venv"
GRAPHIFY_PACKAGE = "graphifyy==0.8.19"
ARCH_ALIASES = {
    "aarch64": "arm64",
    "x86_64": "amd64",
}


def docker_image_dir(docker_os: str, docker_arch: str) -> Path:
    return Path("ai-sandbox/vendor/docker/images") / f"{docker_os}-{docker_arch}"


def normalize_arch(value: str) -> str:
    return ARCH_ALIASES.get(value.strip(), value.strip())


def read_environment(path: Path = ENV_FILE) -> dict[str, str]:
    if not path.exists():
        return {}

    values = {}
    in_environment = False
    for raw_line in path.read_text().splitlines():
        if not raw_line.strip() or raw_line.lstrip().startswith("#"):
            continue
        indent = len(raw_line) - len(raw_line.lstrip())
        stripped = raw_line.strip()

        if indent == 0:
            in_environment = stripped == "environment:"
            continue

        if in_environment and ":" in stripped:
            key, raw_value = stripped.split(":", 1)
            value = raw_value.split("#", 1)[0].strip()
            if len(value) >= 2 and value[0] == value[-1] and value[0] in {"'", '"'}:
                value = value[1:-1]
            values[key.strip()] = value

    if "docker_arch" in values:
        values["docker_arch"] = normalize_arch(values["docker_arch"])
    return values


def vendored_docker_archives(docker_os: str, docker_arch: str, root: Path = ROOT.parent) -> list[Path]:
    image_dir = root / docker_image_dir(docker_os, docker_arch)
    if not image_dir.exists():
        return []
    return sorted(
        path
        for path in image_dir.iterdir()
        if path.is_file() and path.suffix in {".tar", ".tgz", ".gz", ".zst"}
    )


def load_docker_archive(path: Path) -> None:
    suffixes = path.suffixes
    quoted_path = shlex.quote(str(path))
    if suffixes[-1:] == [".zst"]:
        subprocess.run(["sh", "-lc", f"zstd -dc {quoted_path} | docker load"], check=True)
    elif suffixes[-1:] in ([".gz"], [".tgz"]):
        subprocess.run(["sh", "-lc", f"gzip -dc {quoted_path} | docker load"], check=True)
    else:
        subprocess.run(["docker", "load", "-i", str(path)], check=True)


def venv_executable(venv_dir: Path, name: str) -> Path:
    unix_path = venv_dir / "bin" / name
    if unix_path.exists():
        return unix_path
    return venv_dir / "Scripts" / f"{name}.exe"


def ensure_graphify(wheel_dir: Path = WHEEL_DIR, venv_dir: Path = VENV_DIR) -> str:
    existing = shutil.which("graphify")
    if existing:
        return f"system:{existing}"

    if not list(wheel_dir.glob("graphifyy-*.whl")):
        return "missing"

    subprocess.run([sys.executable, "-m", "venv", str(venv_dir)], check=True)
    pip = venv_executable(venv_dir, "pip")
    subprocess.run(
        [
            str(pip),
            "install",
            "--no-index",
            "--find-links",
            str(wheel_dir),
            GRAPHIFY_PACKAGE,
        ],
        check=True,
    )
    return f"venv:{venv_executable(venv_dir, 'graphify')}"


def write_dependency_lock(lines: list[str], path: Path = LOCK_FILE) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text("\n".join(lines) + "\n")


def main() -> int:
    environment = read_environment()
    docker_os = environment.get("docker_os", "")
    docker_arch = environment.get("docker_arch", "")

    if not docker_os or not docker_arch:
        print("Missing Docker platform. Run python3 ai-sandbox/scripts/detect_environment.py first.")
        return 1

    graphify_source = ensure_graphify()
    archives = vendored_docker_archives(docker_os, docker_arch)
    if archives:
        for archive in archives:
            load_docker_archive(archive)
        archive_lines = [f'    - "{archive.as_posix()}"' for archive in archives]
    else:
        image_dir = docker_image_dir(docker_os, docker_arch)
        print(f"No vendored Docker archives found under {image_dir}.")
        print("Use pinned compose images only after confirming this is acceptable for the workspace.")
        archive_lines = ['    - ""']

    write_dependency_lock(
        [
            "dependencies:",
            "  graphify:",
            f'    source: "{graphify_source}"',
            '    package: "graphifyy==0.8.19"',
            "  neo4j:",
            "    required: true",
            '    image: "neo4j:5-community"',
            f'    docker_os: "{docker_os}"',
            f'    docker_arch: "{docker_arch}"',
            "    vendored_archives:",
            *archive_lines,
        ]
    )
    print(f"Wrote {LOCK_FILE}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
