# DOCKER.md

Use Docker platform data from:

```bash
docker info --format '{{.OSType}}/{{.Architecture}}'
```

Do not choose Docker images from host architecture alone. Docker Desktop may run Linux containers with an architecture that differs from the host.

Dependency bootstrap order:

1. Check vendored image archives under `ai-sandbox/vendor/docker/images/<docker-os>-<docker-arch>/`.
2. Load vendored archives when available.
3. Use pinned image pulls only when vendored archives are absent.
4. Record the chosen image tag or digest in `ai-sandbox/config/dependencies.lock.yaml`.
