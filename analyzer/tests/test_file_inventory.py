from devboard_analyzer.file_inventory import iter_repository_files
from devboard_analyzer.file_hashes import hash_file


def test_file_inventory_excludes_git_and_node_modules(tmp_path):
    (tmp_path / ".git").mkdir()
    (tmp_path / ".git" / "config").write_text("secret")
    (tmp_path / "node_modules").mkdir()
    (tmp_path / "node_modules" / "leftpad.js").write_text("x")
    (tmp_path / "src").mkdir()
    (tmp_path / "src" / "app.py").write_text("print('ok')")

    paths = list(iter_repository_files(tmp_path))

    assert paths == [tmp_path / "src" / "app.py"]


def test_file_hashes_produce_stable_sha256(tmp_path):
    target = tmp_path / "app.py"
    target.write_text("print('ok')\n")

    assert hash_file(target) == hash_file(target)
    assert hash_file(target).startswith("sha256:")
