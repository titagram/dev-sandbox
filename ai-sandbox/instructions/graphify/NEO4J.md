# Neo4j

Neo4j is mandatory for graph projection in this sandbox framework.

## Platform Detection

Choose Docker images from Docker platform:

```bash
docker info --format '{{.OSType}}/{{.Architecture}}'
```

Do not choose images from host platform alone.

## Vendored Images

Vendored archives belong under:

```text
ai-sandbox/vendor/docker/images/<docker-os>-<docker-arch>/
```

Load vendored images before using pinned pulls.

## Access

Start Neo4j from the workspace root:

```bash
docker compose -f docker-compose.graph.yaml up -d neo4j
```

Open:

```text
http://localhost:7474
```

Default credentials:

```text
neo4j / graphify-sandbox
```
