#!/usr/bin/env python3

from __future__ import annotations

from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
PROJECT_CONFIG = ROOT / "config" / "project.yaml"


def is_initialized(config_path: Path = PROJECT_CONFIG) -> bool:
    if not config_path.exists():
        return False
    text = config_path.read_text()
    return "initialized: true" in text


def required_next_step(config_path: Path = PROJECT_CONFIG) -> str:
    return "discovery" if is_initialized(config_path) else "interview"


def main() -> int:
    step = required_next_step()
    if step == "interview":
        print("Sandbox is not initialized. Start with ai-sandbox/instructions/interview/INTERVIEW.md")
    else:
        print("Sandbox is initialized. Continue with discovery or refresh workflow.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
