from pathlib import Path
import importlib.util

SCRIPT = Path(__file__).resolve().parents[2] / "ai-sandbox" / "scripts" / "discover_project.py"
spec = importlib.util.spec_from_file_location("discover_project_impl", SCRIPT)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)

detect_stack = module.detect_stack
iter_source_files = module.iter_source_files
configured_project_path = module.configured_project_path
build_discovery = module.build_discovery
