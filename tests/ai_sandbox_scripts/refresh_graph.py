from pathlib import Path
import importlib.util

SCRIPT = Path(__file__).resolve().parents[2] / "ai-sandbox" / "scripts" / "refresh_graph.py"
spec = importlib.util.spec_from_file_location("refresh_graph_impl", SCRIPT)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)

configured_ast_root = module.configured_ast_root
copy_graphify_outputs = module.copy_graphify_outputs
graphify_command = module.graphify_command
