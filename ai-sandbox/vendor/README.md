# Vendored Dependencies

This directory can store dependency artifacts for offline or repeatable sandbox setup.

## Python Wheels

Place wheels under:

```text
ai-sandbox/vendor/python/wheels/
```

The framework includes a wheel cache for `graphifyy==0.8.19` on the development platform used to prepare this sandbox. `bootstrap_dependencies.py` creates `ai-sandbox/.venv` from these wheels when a global `graphify` command is not available.

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
