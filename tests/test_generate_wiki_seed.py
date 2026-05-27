import tempfile
import unittest
from pathlib import Path

from tests.ai_sandbox_scripts.generate_wiki_seed import render_readme, write_seed


class GenerateWikiSeedTest(unittest.TestCase):
    def test_render_readme_marks_unknowns(self):
        text = render_readme({"project_name": "Demo", "stack": ["generic"]})
        self.assertIn("# Demo Knowledge Base", text)
        self.assertIn("needs_verification", text)

    def test_write_seed_creates_wiki_and_logbooks(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            write_seed(root, {"project_name": "Demo", "stack": ["generic"]})

            self.assertTrue((root / "wiki" / "README.md").exists())
            self.assertTrue((root / "logbooks" / "LOGBOOK_PROJECT.md").exists())

    def test_write_seed_does_not_overwrite_existing_logbook(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            logbooks = root / "logbooks"
            logbooks.mkdir()
            logbook = logbooks / "LOGBOOK_PROJECT.md"
            logbook.write_text("# Existing\n\nEntry\n")

            write_seed(root, {"project_name": "Demo", "stack": ["generic"]})

            self.assertEqual("# Existing\n\nEntry\n", logbook.read_text())


if __name__ == "__main__":
    unittest.main()
