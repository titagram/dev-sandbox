import unittest
import tempfile
from pathlib import Path

from tests.ai_sandbox_scripts.bootstrap_dependencies import docker_image_dir, normalize_arch, read_environment


class BootstrapDependenciesTest(unittest.TestCase):
    def test_docker_image_dir_uses_docker_platform(self):
        self.assertEqual(
            Path("ai-sandbox/vendor/docker/images/linux-arm64"),
            docker_image_dir("linux", "arm64"),
        )

    def test_normalize_arch(self):
        self.assertEqual("arm64", normalize_arch("aarch64"))
        self.assertEqual("amd64", normalize_arch("x86_64"))

    def test_read_environment_normalizes_docker_arch(self):
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / "environment.yaml"
            path.write_text(
                "\n".join(
                    [
                        "environment:",
                        '  docker_os: "linux"',
                        '  docker_arch: "aarch64"',
                    ]
                )
                + "\n"
            )

            self.assertEqual({"docker_os": "linux", "docker_arch": "arm64"}, read_environment(path))


if __name__ == "__main__":
    unittest.main()
