import tempfile
import unittest
from pathlib import Path

from tests.ai_sandbox_scripts.init_sandbox import is_initialized, required_next_step


class InitSandboxTest(unittest.TestCase):
    def test_uninitialized_project_requires_interview(self):
        with tempfile.TemporaryDirectory() as tmp:
            config = Path(tmp) / "project.yaml"
            config.write_text("project:\n  initialized: false\n")

            self.assertFalse(is_initialized(config))
            self.assertEqual("interview", required_next_step(config))

    def test_initialized_project_allows_discovery(self):
        with tempfile.TemporaryDirectory() as tmp:
            config = Path(tmp) / "project.yaml"
            config.write_text("project:\n  initialized: true\n")

            self.assertTrue(is_initialized(config))
            self.assertEqual("discovery", required_next_step(config))


if __name__ == "__main__":
    unittest.main()
