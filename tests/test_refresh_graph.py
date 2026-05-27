import tempfile
import unittest
from pathlib import Path

from tests.ai_sandbox_scripts.refresh_graph import configured_ast_root, copy_graphify_outputs, graphify_command


class RefreshGraphTest(unittest.TestCase):
    def test_configured_ast_root_uses_graph_config(self):
        with tempfile.TemporaryDirectory() as tmp:
            workspace = Path(tmp)
            (workspace / "project").mkdir()
            config = workspace / "ai-sandbox" / "config" / "project.yaml"
            config.parent.mkdir(parents=True)
            config.write_text(
                "\n".join(
                    [
                        "project:",
                        '  root: "unused"',
                        "graph:",
                        '  ast_root: "project"',
                    ]
                )
                + "\n"
            )

            self.assertEqual("project", configured_ast_root(config, workspace))

    def test_copy_graphify_outputs_copies_graph_and_report(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            graphify_dir = root / "graphify-out"
            graph_dir = root / "ai-sandbox" / "graph"
            graphify_dir.mkdir()
            (graphify_dir / "graph.json").write_text('{"nodes": [], "links": []}\n')
            (graphify_dir / "GRAPH_REPORT.md").write_text("# Report\n")

            copied = copy_graphify_outputs(graphify_dir, graph_dir)

            self.assertEqual([graph_dir / "graph.json", graph_dir / "GRAPH_REPORT.md"], copied)
            self.assertEqual('{"nodes": [], "links": []}\n', (graph_dir / "graph.json").read_text())
            self.assertEqual("# Report\n", (graph_dir / "GRAPH_REPORT.md").read_text())

    def test_graphify_command_prefers_local_venv(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            graphify = root / ".venv" / "bin" / "graphify"
            graphify.parent.mkdir(parents=True)
            graphify.write_text("")

            self.assertEqual(str(graphify), graphify_command(root))


if __name__ == "__main__":
    unittest.main()
