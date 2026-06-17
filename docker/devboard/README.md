# DevBoard Docker

Questa cartella documenta solo il runtime Docker locale.
Per la guida completa usa prima `README.md` alla root.

## Compose files

- base: `docker-compose.devboard.yaml`
- override target x64: `docker-compose.devboard.amd64.yaml`

## Servizi

### app

- container port: `8000`
- host port default: `8000`
- URL: [http://127.0.0.1:8000](http://127.0.0.1:8000)

Environment principali nel compose:

- `APP_ENV=local`
- `DB_CONNECTION=pgsql`
- `DB_HOST=postgres`
- `DB_PORT=5432`
- `DB_DATABASE=devboard`
- `DB_USERNAME=devboard`
- `DB_PASSWORD=devboard`
- `QUEUE_CONNECTION=sync`
- `DEVBOARD_GRAPH_IMPORT_MODE=neo4j`
- `NEO4J_URI=bolt://neo4j:7687`
- `NEO4J_USER=neo4j`
- `NEO4J_PASSWORD=graphify-sandbox`

### node

- container port: `5173`
- host port default: `5173`
- URL: [http://127.0.0.1:5173](http://127.0.0.1:5173)

### postgres

- container port: `5432`
- host port default: `5432`
- database: `devboard`
- user: `devboard`
- password: `devboard`

### neo4j

- HTTP host port default: `7474`
- Bolt host port default: `7687`
- browser: [http://127.0.0.1:7474](http://127.0.0.1:7474)
- user: `neo4j`
- password: `graphify-sandbox`

## Avvio

```bash
docker compose -f docker-compose.devboard.yaml up -d app node postgres neo4j
```

## Override porte host

```bash
DEVBOARD_APP_PORT=18000 \
DEVBOARD_VITE_PORT=15173 \
DEVBOARD_POSTGRES_PORT=15432 \
DEVBOARD_NEO4J_HTTP_PORT=17474 \
DEVBOARD_NEO4J_BOLT_PORT=17687 \
docker compose -f docker-compose.devboard.yaml up -d app node postgres neo4j
```

## Verifiche rapide

```bash
docker compose -f docker-compose.devboard.yaml ps
docker compose -f docker-compose.devboard.yaml logs --tail=80 app
docker compose -f docker-compose.devboard.yaml exec -T neo4j cypher-shell -u neo4j -p graphify-sandbox 'RETURN 1 AS ok'
docker compose -f docker-compose.devboard.yaml exec -T postgres psql -U devboard -d devboard -c 'select 1;'
```

## Seed locale

```bash
docker compose -f docker-compose.devboard.yaml exec -T app php artisan migrate:fresh --seed --seeder=DevBoardSeeder --force
```

Questo crea almeno:

- dashboard login `admin@example.com / password`
- project `demo-project`
- repository `demo-repository`

Il plugin token non e` seedato: va creato dalla dashboard Admin.
