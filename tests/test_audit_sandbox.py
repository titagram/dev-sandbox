import unittest
import tempfile
from pathlib import Path

from tests.ai_sandbox_scripts.audit_sandbox import configured_project_root, unexpected_graph_sources


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

            self.assertEqual("src", configured_project_root(config))


if __name__ == "__main__":
    unittest.main()
