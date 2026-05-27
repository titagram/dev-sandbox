from pathlib import Path
import importlib.util

SCRIPT = Path(__file__).resolve().parents[2] / "ai-sandbox" / "scripts" / "generate_wiki_seed.py"
spec = importlib.util.spec_from_file_location("generate_wiki_seed_impl", SCRIPT)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)

render_readme = module.render_readme
write_seed = module.write_seed
