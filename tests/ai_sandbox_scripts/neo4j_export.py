from pathlib import Path
import importlib.util

SCRIPT = Path(__file__).resolve().parents[2] / "ai-sandbox" / "scripts" / "neo4j_export.py"
spec = importlib.util.spec_from_file_location("neo4j_export_impl", SCRIPT)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)

safe_rel_type = module.safe_rel_type
