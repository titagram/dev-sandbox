from pathlib import Path
import importlib.util

SCRIPT = Path(__file__).resolve().parents[2] / "ai-sandbox" / "scripts" / "audit_sandbox.py"
spec = importlib.util.spec_from_file_location("audit_sandbox_impl", SCRIPT)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)

unexpected_graph_sources = module.unexpected_graph_sources
configured_project_root = module.configured_project_root
