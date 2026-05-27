import unittest

from tests.ai_sandbox_scripts.neo4j_export import safe_rel_type


class Neo4jExportTest(unittest.TestCase):
    def test_safe_rel_type(self):
        self.assertEqual("ROUTE_HANDLED_BY", safe_rel_type("route handled by"))
        self.assertEqual("RELATED", safe_rel_type(""))


if __name__ == "__main__":
    unittest.main()
