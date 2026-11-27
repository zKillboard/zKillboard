# Docker

Requires Linux, Docker 20.10+, and ports 80, 9000, 15241, 27017, and 6379 available. Run from the repository root with Docker permissions (use `sudo` if needed).

The web and cron images use PHP 8.4 on Debian Bookworm, MongoDB extension 2.5.9, and the MongoDB 2.5 Composer library.

## PHP and MongoDB upgrade checks

Build both application images, then run isolated checks without starting web servers, cron jobs, or database connections:

```bash
./scripts/build-docker-containers.sh zkill-www
./scripts/build-docker-containers.sh zkill-cron

for image in zkill-www zkill-cron; do
    docker run --rm --network none --entrypoint sh "$image" -ec '
        php -v
        composer check-platform-reqs --no-dev
        php tests/pug_smoke.php
        php tests/fitting_export.php
    '
done

docker run --rm --network none --entrypoint php-fpm zkill-www -t
```

These checks do not replace testing application routes and login behavior against the existing local environment. The normal start command below initializes indexes and enables cron; it is not an isolated test command.

## Local development setup

```bash
./scripts/build-docker-containers.sh
```

Build prepares the images. Start creates any missing MongoDB, Redis, and redis-websocket containers and initializes MongoDB's replica set only if it is not already initialized.

MongoDB and Redis persist in named volumes, which start reuses. Both publish without authentication; restrict ports 27017 and 6379 to trusted machines.

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
