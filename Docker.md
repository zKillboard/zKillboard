# zKillboard Docker Reference

## Prerequisites

- Docker 20.10+
- Port 9000 available (only if publishing the FastCGI port)
- Redis and MongoDB services (configured in `config.php`)

## Build

```bash
docker build -f Dockerfile.www -t zkill-www .
docker build -f Dockerfile.cron -t zkill-cron .
```

## Run Web Server

```bash
docker run -d --network host --name zkill-www \
	--log-opt max-size=50m --log-opt max-file=3 \
	zkill-www
```

Default mode is `php-fpm` on port `9000` for nginx to connect to.

If nginx runs in another container, either:

```bash
# Option A: same host network
docker run -d --network host --name zkill-www zkill-www
```

or

```bash
# Option B: publish FastCGI port on the host
docker run -d -p 9000:9000 --name zkill-www zkill-www
```

`localhost:9000` is FastCGI, not HTTP. Use nginx `fastcgi_pass` to reach it.

To serve HTTP directly from this same image:

```bash
docker run -d --network host -e WWW_MODE=http -e WWW_HTTP_PORT=8000 --name zkill-www zkill-www
```

Then access `http://localhost:8000`.

### Development with volume

```bash
docker run -d --network host -v $(pwd):/app --name zkill-www \
	--log-opt max-size=50m --log-opt max-file=3 \
	zkill-www
```

## Run Cron Worker

```bash
docker run -d --network host --name zkill-cron \
	--log-opt max-size=50m --log-opt max-file=3 \
	zkill-cron
```

Runs `./cron/cron.sh` continuously.

Logs are emitted to container stdout/stderr and rotated by Docker with the options above.

## Commands

**Web Server:**
```bash
# View logs
docker logs zkill-www
docker logs -f zkill-www

# Check container state
docker ps -a --filter name=zkill-www

# Inspect FPM health endpoints from inside the container
docker exec zkill-www sh -lc 'SCRIPT_NAME=/ping SCRIPT_FILENAME=/ping REQUEST_METHOD=GET cgi-fcgi -bind -connect 127.0.0.1:9000'

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