# Docker

Requires Linux, Docker 20.10+, and ports 80, 9000, 15241, 27017, and 6379 available. Run from the repository root with Docker permissions (use `sudo` if needed).

## Local development setup

```bash
./scripts/build-docker-containers.sh

docker run -d --restart unless-stopped --name zkill-mongo \
    -p 27017:27017 -v zkill-mongo-data:/data/db \
    --log-opt max-size=50m --log-opt max-file=3 \
    mongo:7.0 --replSet rs0 --bind_ip_all
```

Once MongoDB is listening, initialize its replica set once per data volume:

```bash
docker exec zkill-mongo mongosh --quiet --eval 'rs.initiate({_id: "rs0", members: [{_id: 0, host: "localhost:27017"}]})'

docker run -d --restart unless-stopped --name zkill-redis \
    -p 6379:6379 -v zkill-redis-data:/data \
    --log-opt max-size=50m --log-opt max-file=3 \
    redis:7 redis-server --appendonly yes

docker run -d --restart unless-stopped --network host --name redis-websocket \
    -e SERVERS=127.0.0.1 -e PORT=15241 \
    --log-opt max-size=10m --log-opt max-file=5 \
    redis-websocket
```

MongoDB and Redis persist in named volumes. Both publish without authentication; restrict ports 27017 and 6379 to trusted machines.

Configure `config.php`:

- MongoDB: `mongodb://127.0.0.1:27017/?replicaSet=rs0&directConnection=true`
- Redis: `127.0.0.1:6379`
- `$fullAddr = 'http://localhost';`
- `$cookie_ssl = false;`
- `$pugCache = false;` for live template edits.

## Usage

```bash
./scripts/start-docker-containers.sh
# Open http://localhost/
./scripts/stop-docker-containers.sh
```

Start waits for MongoDB and Redis, then recreates web, cron, and nginx with live checkout mounts and fresh Composer dependency volumes. It enables scheduled cron jobs; job logs are in `cron/logs/`. Stop preserves database volumes.

Edit files in this checkout to develop locally; changes are available immediately at `http://localhost/` without rebuilding.

After image or dependency changes, rebuild and run start again. Build accepts one container name (`zkill-www`, `zkill-cron`, `zkill-mongo`, `zkill-redis`, `zkill-nginx`, or `redis-websocket`); omit it to build/pull all images.

```bash
docker logs zkill-www
docker exec -it zkill-www bash
```
