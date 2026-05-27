#!/usr/bin/env python3

from __future__ import annotations

from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]


def render_readme(context: dict) -> str:
    project_name = context.get("project_name", "Project")
    stack = ", ".join(context.get("stack", ["needs_verification"]))
    return f"""# {project_name} Knowledge Base

## Status

- Project facts: `needs_verification`
- Stack: `{stack}`
- Source status legend: `verified_from_code`, `developer_provided`, `inferred`, `needs_verification`

## Entry Points

- `AUDIT.md`
- `LLM_INSTRUCTIONS.md`
- `ROUTE_INDEX.md`
- `ENTITY_INDEX.md`
- `SIDE_EFFECTS_INDEX.md`
"""


def write_seed(root: Path = ROOT, context: dict | None = None) -> None:
    context = context or {"project_name": "Project", "stack": ["needs_verification"]}
    wiki = root / "wiki"
    logbooks = root / "logbooks"
    wiki.mkdir(parents=True, exist_ok=True)
    logbooks.mkdir(parents=True, exist_ok=True)
    seed_files = {
        wiki / "README.md": render_readme(context),
        wiki / "AUDIT.md": "# Audit\n\nInitial status: `needs_verification`\n",
        wiki / "LLM_INSTRUCTIONS.md": "# LLM Instructions\n\nRead `AGENTS.md` and `ai-sandbox/INIT.md` first.\n",
        wiki / "ROUTE_INDEX.md": "# Route Index\n\nNo verified routes yet.\n",
        wiki / "ENTITY_INDEX.md": "# Entity Index\n\nNo verified entities yet.\n",
        wiki / "SIDE_EFFECTS_INDEX.md": "# Side Effects Index\n\nNo verified side effects yet.\n",
        logbooks / "LOGBOOK_PROJECT.md": "# Project Logbook\n\n",
        logbooks / "LOGBOOK_SANDBOX_IA.md": "# Sandbox IA Logbook\n\n",
    }
    for path, content in seed_files.items():
        if not path.exists():
            path.write_text(content)


def main() -> int:
    write_seed()
    print("Wrote wiki seed and logbooks.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
