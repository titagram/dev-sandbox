# DevBoard Docker

DevBoard uses Docker Compose for local services and for parity with the Ubuntu x64 deployment target.

The default compose file does not pin `platform`, so Docker Desktop can use the native Linux container architecture on a Mac M4:

```bash
docker info --format '{{.OSType}}/{{.Architecture}}'
docker compose -f docker-compose.devboard.yaml up app node postgres neo4j
```

Use the amd64 override when validating the target Ubuntu x64 platform from Apple Silicon:

```bash
docker compose -f docker-compose.devboard.yaml -f docker-compose.devboard.amd64.yaml config
docker compose -f docker-compose.devboard.yaml -f docker-compose.devboard.amd64.yaml up app node postgres neo4j
```

Services:

- `app`: Laravel backend on `http://localhost:8000`.
- `node`: Vite dev server on `http://localhost:5173`.
- `postgres`: PostgreSQL on `localhost:5432`.
- `neo4j`: Neo4j HTTP on `http://localhost:7474` and Bolt on `localhost:7687`.
