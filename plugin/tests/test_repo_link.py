import json

from devboard_plugin.git_local import ensure_devboard_excluded
from devboard_plugin.state import write_repo_link_state


def test_repo_link_creates_devboard_state_with_link_ids(tmp_path):
    state_path = write_repo_link_state(
        tmp_path,
        project_id="proj_123",
        repository_id="repo_123",
        local_workspace_id="lw_123",
    )

    state = json.loads(state_path.read_text())

    assert state_path == tmp_path / ".devboard" / "state.json"
    assert state["project_id"] == "proj_123"
    assert state["repository_id"] == "repo_123"
    assert state["local_workspace_id"] == "lw_123"


def test_devboard_directory_is_added_to_git_info_exclude(tmp_path):
    info_dir = tmp_path / ".git" / "info"
    info_dir.mkdir(parents=True)
    exclude_path = info_dir / "exclude"
    exclude_path.write_text("# existing\n")

    ensure_devboard_excluded(tmp_path)
    ensure_devboard_excluded(tmp_path)

    lines = exclude_path.read_text().splitlines()

    assert lines.count(".devboard/") == 1
