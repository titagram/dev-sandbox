from pathlib import Path
import importlib.util

SCRIPT = Path(__file__).resolve().parents[2] / "ai-sandbox" / "scripts" / "init_sandbox.py"
spec = importlib.util.spec_from_file_location("init_sandbox_impl", SCRIPT)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)

is_initialized = module.is_initialized
required_next_step = module.required_next_step
