# zKillboard Docker Reference

## Prerequisites

- Docker 20.10+
- Port 8000 available
- Redis and MongoDB services (configured in `config.php`)

## Build

```bash
docker build -f Dockerfile.www -t zkill-www .
docker build -f Dockerfile.cron -t zkill-cron .
```

## Run Web Server

```bash
docker run -d --network host --name zkill-www zkill-www
```

Access at `http://localhost:8000`

### Development with volume

```bash
docker run -d --network host -v $(pwd):/app --name zkill-www zkill-www
```

## Run Cron Worker

```bash
docker run -d --network host --name zkill-cron zkill-cron
```

Runs `./cron/cron.sh` continuously.

## Commands

**Web Server:**
```bash
# View logs
docker logs zkill-www
docker logs -f zkill-www

# Stop/Start
docker stop zkill-www
docker start zkill-www

# Access shell
docker exec -it zkill-www bash

# Update dependencies
docker exec zkill-www composer install

# Remove
docker rm zkill-www
```

**Cron Worker:**
```bash
# View logs
docker logs zkill-cron
docker logs -f zkill-cron

# Stop/Start
docker stop zkill-cron
docker start zkill-cron

# Remove
docker rm zkill-cron
```