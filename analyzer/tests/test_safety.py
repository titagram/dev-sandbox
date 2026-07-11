import pytest

from devboard_analyzer.safety import scan_safety


def test_env_file_is_hard_blocked(tmp_path):
    path = tmp_path / ".env"
    path.write_text("APP_KEY=secret")

    report = scan_safety(tmp_path, [path])

    assert report.blocked
    assert report.blocked[0]["path"] == ".env"


def test_private_key_text_is_hard_blocked(tmp_path):
    path = tmp_path / "private.pem"
    path.write_text("-----BEGIN PRIVATE KEY-----\nsecret\n-----END PRIVATE KEY-----")

    report = scan_safety(tmp_path, [path])

    assert report.blocked
    assert report.blocked[0]["reason"] == "private_key"


def test_vendor_cache_and_build_paths_warn(tmp_path):
    paths = []
    for name in ["vendor/pkg.php", "cache/data.bin", "build/app.js"]:
        path = tmp_path / name
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text("generated")
        paths.append(path)

    report = scan_safety(tmp_path, paths)

    assert {warning["reason"] for warning in report.warnings} == {"generated_or_dependency_path"}


def test_credential_filenames_are_hard_blocked(tmp_path):
    paths = []
    for name in [".env.production", "server.key", "client.crt", "credentials.json", "access-token.txt"]:
        path = tmp_path / name
        path.write_text("sensitive")
        paths.append(path)

    report = scan_safety(tmp_path, paths)

    assert {item["path"] for item in report.blocked} == {path.name for path in paths}


@pytest.mark.parametrize(
    "header",
    [
        "-----BEGIN PRIVATE KEY-----",
        "-----BEGIN RSA PRIVATE KEY-----",
        "-----BEGIN EC PRIVATE KEY-----",
        "-----BEGIN OPENSSH PRIVATE KEY-----",
        "-----BEGIN DSA PRIVATE KEY-----",
        "-----BEGIN ENCRYPTED PRIVATE KEY-----",
        "-----BEGIN PGP PRIVATE KEY BLOCK-----",
    ],
)
def test_private_key_pem_headers_are_blocked_with_innocent_filenames(tmp_path, header):
    path = tmp_path / "meeting-notes.txt"
    path.write_text(f"notes\n{header}\nsecret\n")

    report = scan_safety(tmp_path, [path])

    assert report.blocked == [{"path": "meeting-notes.txt", "reason": "private_key"}]
