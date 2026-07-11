import unittest
import tempfile
from pathlib import Path

from tests.ai_sandbox_scripts.audit_sandbox import configured_project_root, unexpected_graph_sources


WORKSPACE = Path(__file__).resolve().parents[1]


class AuditSandboxTest(unittest.TestCase):
    def test_unexpected_graph_sources_reports_paths_outside_project(self):
        graph = {
            "nodes": [
                {"source_file": "project/src/App.php"},
                {"source_file": "ai-sandbox/scripts/tool.py"},
            ],
            "links": [
                {"source_file": "project/templates/app.html"},
            ],
        }

        self.assertEqual(["ai-sandbox/scripts/tool.py"], unexpected_graph_sources(graph, "project"))

    def test_configured_project_root_uses_ast_root(self):
        with tempfile.TemporaryDirectory() as tmp:
            workspace = Path(tmp)
            (workspace / "src").mkdir()
            config = Path(tmp) / "project.yaml"
            config.write_text(
                "\n".join(
                    [
                        "project:",
                        '  root: "project"',
                        "graph:",
                        '  ast_root: "src"',
                    ]
                )
                + "\n"
            )

            self.assertEqual("src", configured_project_root(config, workspace))

    def test_configured_project_root_accepts_workspace_root(self):
        with tempfile.TemporaryDirectory() as tmp:
            workspace = Path(tmp)
            config = workspace / "project.yaml"
            config.write_text(
                "\n".join(
                    [
                        "project:",
                        '  root: "."',
                        "graph:",
                        '  ast_root: "."',
                    ]
                )
                + "\n"
            )

            self.assertEqual(".", configured_project_root(config, workspace))

    def test_workspace_root_accepts_project_files_and_rejects_sandbox_outputs(self):
        graph = {
            "nodes": [
                {"source_file": "backend/app/Foo.php"},
                {"source_file": "ai-sandbox/scripts/tool.py"},
            ],
            "links": [
                {"target_file": "graphify-out/graph.json"},
                {"source_file": "docs/ai-devboard/05_GENESIS_IMPORT.md"},
            ],
        }

        self.assertEqual(
            ["ai-sandbox/scripts/tool.py", "graphify-out/graph.json"],
            unexpected_graph_sources(graph, "."),
        )

    def test_configured_project_root_rejects_missing_root(self):
        with tempfile.TemporaryDirectory() as tmp:
            workspace = Path(tmp)
            config = workspace / "project.yaml"
            config.write_text(
                "\n".join(
                    [
                        "project:",
                        '  root: "."',
                        "graph:",
                        '  ast_root: "missing"',
                    ]
                )
                + "\n"
            )

            with self.assertRaises(FileNotFoundError):
                configured_project_root(config, workspace)

    def test_graphifyignore_excludes_root_generated_dependency_and_cache_dirs(self):
        ignored = set((WORKSPACE / ".graphifyignore").read_text().splitlines())

        self.assertTrue(
            {
                ".git/",
                "ai-sandbox/",
                "graphify-out/",
                "backend/vendor/",
                "backend/node_modules/",
                "backend/storage/",
                ".venv/",
                "backend/.pytest_cache/",
                "backend/public/build/",
                "backend/coverage/",
            }.issubset(ignored)
        )
        self.assertNotIn("project/vendor/", ignored)


if __name__ == "__main__":
    unittest.main()
