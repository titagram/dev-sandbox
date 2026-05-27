from pathlib import Path
import importlib.util

SCRIPT = Path(__file__).resolve().parents[2] / "ai-sandbox" / "scripts" / "bootstrap_dependencies.py"
spec = importlib.util.spec_from_file_location("bootstrap_dependencies_impl", SCRIPT)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)

docker_image_dir = module.docker_image_dir
read_environment = module.read_environment
normalize_arch = module.normalize_arch
