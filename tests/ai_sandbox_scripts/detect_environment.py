from pathlib import Path
import importlib.util

SCRIPT = Path(__file__).resolve().parents[2] / "ai-sandbox" / "scripts" / "detect_environment.py"
spec = importlib.util.spec_from_file_location("detect_environment_impl", SCRIPT)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)

parse_docker_platform = module.parse_docker_platform
snapshot_environment = module.snapshot_environment
run_command = module.run_command
