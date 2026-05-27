import tempfile
import unittest
from pathlib import Path

from tests.ai_sandbox_scripts.discover_project import (
    build_discovery,
    configured_project_path,
    detect_stack,
    iter_source_files,
)


class DiscoverProjectTest(unittest.TestCase):
    def test_detect_stack_finds_symfony_node_python(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            (root / "composer.json").write_text('{"require":{"symfony/framework-bundle":"^6.0"}}')
            (root / "package.json").write_text('{"scripts":{"test":"npm test"}}')
            (root / "pyproject.toml").write_text("[project]\nname='demo'\n")

            self.assertEqual(["symfony", "node", "python"], detect_stack(root))

    def test_iter_source_files_excludes_vendor_cache_and_build(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            (root / "src").mkdir()
            (root / "vendor").mkdir()
            (root / "src" / "App.php").write_text("<?php")
            (root / "vendor" / "autoload.php").write_text("<?php")

            self.assertEqual([Path("src/App.php")], list(iter_source_files(root)))

    def test_configured_project_path_uses_project_root(self):
        with tempfile.TemporaryDirectory() as tmp:
            workspace = Path(tmp)
            config = workspace / "ai-sandbox" / "config" / "project.yaml"
            config.parent.mkdir(parents=True)
            config.write_text("project:\n  root: \"client-app\"\n")

            self.assertEqual(workspace / "client-app", configured_project_path(config, workspace))

    def test_build_discovery_uses_supplied_root(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            (root / "src").mkdir()
            (root / "src" / "app.py").write_text("print('ok')\n")

            data = build_discovery(root)

            self.assertEqual(root.as_posix(), data["project_root"])
            self.assertEqual(1, data["file_count"])


if __name__ == "__main__":
    unittest.main()
