# Vendored Dependencies

This directory can store dependency artifacts for offline or repeatable sandbox setup.

## Python Wheels

Place wheels under:

```text
ai-sandbox/vendor/python/wheels/
```

The framework includes platform-specific wheels for `graphifyy==0.8.19` and its required dependencies. `bootstrap_dependencies.py` selects compatible wheels from this directory and creates `ai-sandbox/.venv` without accessing a package index when a global `graphify` command is not available.

The current wheelhouse supports macOS arm64 CPython 3.14 and Linux x86_64 CPython 3.13. Add all platform-specific transitive dependencies together; a compatible `graphifyy` wheel alone is not sufficient for an offline install.

## Docker Images

Place Docker image archives under:

```text
ai-sandbox/vendor/docker/images/<docker-os>-<docker-arch>/
```

Example:

```text
ai-sandbox/vendor/docker/images/linux-arm64/neo4j-5-community.tar.zst
```

Use `docker info --format '{{.OSType}}/{{.Architecture}}'` to choose the directory.
