from pathlib import Path

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


def test_file_inventory_does_not_follow_symlinks_outside_repo(tmp_path):
    repo = tmp_path / "repo"
    outside = tmp_path / "outside"
    repo.mkdir()
    outside.mkdir()
    (outside / "secret.txt").write_text("secret")
    (repo / "linked-dir").symlink_to(outside, target_is_directory=True)
    (repo / "linked-file.txt").symlink_to(outside / "secret.txt")

    assert list(iter_repository_files(repo)) == []


def test_file_inventory_respects_gitignore_and_policy_exclusions(tmp_path):
    (tmp_path / ".gitignore").write_text("*.log\nignored-dir/\n")
    (tmp_path / "debug.log").write_text("ignored")
    (tmp_path / "ignored-dir").mkdir()
    (tmp_path / "ignored-dir" / "inside.py").write_text("ignored")
    (tmp_path / "storage" / "uploads").mkdir(parents=True)
    (tmp_path / "storage" / "uploads" / "asset.bin").write_bytes(b"ignored")
    (tmp_path / "src").mkdir()
    (tmp_path / "src" / "app.py").write_text("ok")

    paths = list(iter_repository_files(tmp_path, excluded_paths=["storage/uploads/"]))

    assert [path.relative_to(tmp_path).as_posix() for path in paths] == [".gitignore", "src/app.py"]


def test_gitignore_negation_reincludes_root_pattern_match(tmp_path):
    (tmp_path / ".gitignore").write_text("backend/.env*\n!backend/.env.example\n")
    (tmp_path / "backend").mkdir()
    (tmp_path / "backend" / ".env.production").write_text("SECRET=value")
    (tmp_path / "backend" / ".env.example").write_text("SECRET=placeholder")

    paths = list(iter_repository_files(tmp_path))

    assert [path.relative_to(tmp_path).as_posix() for path in paths] == [
        ".gitignore",
        "backend/.env.example",
    ]


def test_ignored_directory_is_pruned_when_no_negation_can_reinclude_descendants(monkeypatch, tmp_path):
    (tmp_path / ".gitignore").write_text("ignored-dir/\n")
    (tmp_path / "ignored-dir").mkdir()
    (tmp_path / "ignored-dir" / "should-not-visit.txt").write_text("ignored")
    original_is_file = Path.is_file

    def guarded_is_file(path):
        if path.name == "should-not-visit.txt":
            raise AssertionError("ignored directory was traversed")
        return original_is_file(path)

    monkeypatch.setattr(Path, "is_file", guarded_is_file)

    assert [path.name for path in iter_repository_files(tmp_path)] == [".gitignore"]


def test_ignored_directory_is_not_pruned_when_negation_reincludes_descendant(tmp_path):
    (tmp_path / ".gitignore").write_text("ignored-dir/\n!ignored-dir/keep.txt\n")
    (tmp_path / "ignored-dir").mkdir()
    (tmp_path / "ignored-dir" / "drop.txt").write_text("ignored")
    (tmp_path / "ignored-dir" / "keep.txt").write_text("included")

    paths = list(iter_repository_files(tmp_path))

    assert [path.relative_to(tmp_path).as_posix() for path in paths] == [
        ".gitignore",
        "ignored-dir/keep.txt",
    ]
