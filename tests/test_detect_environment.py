import unittest
from unittest.mock import patch

from tests.ai_sandbox_scripts.detect_environment import parse_docker_platform, snapshot_environment


class DetectEnvironmentTest(unittest.TestCase):
    def test_parse_docker_platform(self):
        self.assertEqual(("linux", "arm64"), parse_docker_platform("linux/arm64"))
        self.assertEqual(("linux", "arm64"), parse_docker_platform("linux/aarch64"))
        self.assertEqual(("linux", "amd64"), parse_docker_platform("linux/x86_64"))
        self.assertEqual(("", ""), parse_docker_platform(""))

    @patch("tests.ai_sandbox_scripts.detect_environment.module.run_command")
    def test_snapshot_environment_records_host_and_docker(self, run_command):
        run_command.side_effect = ["Darwin", "arm64", "Python 3.14.5", "linux/arm64", "/workspace"]

        data = snapshot_environment()

        self.assertEqual("Darwin", data["host_os"])
        self.assertEqual("arm64", data["host_arch"])
        self.assertEqual("linux", data["docker_os"])
        self.assertEqual("arm64", data["docker_arch"])
        self.assertEqual("/workspace", data["git_root"])


if __name__ == "__main__":
    unittest.main()
