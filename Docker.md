# zKillboard Docker Reference

## Prerequisites

- Docker 20.10+
- Port 80 available for local nginx
- Port 9000 available (only if publishing the FastCGI port)
- Redis and MongoDB services (configured in `config.php`)
- redis-websocket service (see below) and nginx proxying `/websocket/` to it

## Build

```bash
docker build -f Dockerfile.www -t zkill-www .
docker build -f Dockerfile.cron -t zkill-cron .
```

## Install MongoDB 7

The killmail processor uses transactions, so local MongoDB must run as a single-member replica set.

Ensure port `27017` is available on the host before starting the container; an existing local MongoDB service may already use it.

```bash
docker run -d --restart unless-stopped --name zkill-mongo \
	-p 27017:27017 \
	-v zkill-mongo-data:/data/db \
	--log-opt max-size=50m --log-opt max-file=3 \
	mongo:7.0 --replSet rs0 --bind_ip_all
```

Docker downloads the image if needed and creates the named volume `zkill-mongo-data` to persist database files across container replacements.

Once MongoDB is listening, initialize the replica set once per data volume:

```bash
docker exec zkill-mongo mongosh --quiet --eval 'rs.initiate({_id: "rs0", members: [{_id: 0, host: "localhost:27017"}]})'
```

Connect using `mongodb://127.0.0.1:27017/?replicaSet=rs0&directConnection=true` in `config.php`. You can replace `127.0.0.1` with the machine hostname; direct connection keeps clients on that address. The web and cron containers below use host networking. This setup uses no authentication and publishes MongoDB on all host interfaces, so restrict access to port `27017` to trusted machines.

Verify MongoDB has elected its primary (returns `true`) before starting the web and cron containers:

```bash
docker exec zkill-mongo mongosh --quiet --eval 'db.hello().isWritablePrimary'
```

See the [official MongoDB Docker image documentation](https://hub.docker.com/_/mongo) for image and storage details.

## Install Redis 7

Ensure port `6379` is available on the host before starting the container; an existing local Redis service may already use it.

```bash
docker run -d --restart unless-stopped --name zkill-redis \
	-p 6379:6379 \
	-v zkill-redis-data:/data \
	--log-opt max-size=50m --log-opt max-file=3 \
	redis:7 redis-server --appendonly yes
```

Docker downloads the image if needed and creates the named volume `zkill-redis-data`. Append-only persistence stores Redis data in this volume across container restarts and replacements.

Connect from the host using `127.0.0.1` or the machine hostname on port `6379`. The web and cron containers below using `--network host` can use either address. Publishing on all host interfaces lets cron scripts connect using `gethostname()`. This setup uses no authentication, so restrict access to port `6379` to trusted machines.

Verify Redis is running (returns `PONG`):

```bash
docker exec zkill-redis redis-cli ping
```

See the [official Redis Docker image documentation](https://hub.docker.com/_/redis) for image and storage details.

## Install redis-websocket

Clone redis-websocket from GitHub and build the Docker image (requires Git):

```bash
git clone https://github.com/cvweiss/redis-websocket.git
docker build -t redis-websocket ./redis-websocket
```

Start Redis first and ensure port `15241` is available on the host. Run with Linux host networking so the service can connect to Redis at `127.0.0.1:6379`:

```bash
docker run -d --restart unless-stopped --network host --name redis-websocket \
	-e SERVERS=127.0.0.1 -e PORT=15241 \
	--log-opt max-size=10m --log-opt max-file=5 \
	redis-websocket
```

`SERVERS` is required and accepts a Redis host or comma-separated hosts to try in order. `PORT` defaults to `15241`. These environment variables are passed directly, so no `.env` file is needed. The service subscribes to Redis channels and forwards messages to subscribed WebSocket clients; it writes a temporary Redis key at startup to check write access.

The browser connects to `/websocket/` on the website's hostname, using `ws://` for `localhost` and `wss://` otherwise. The local nginx configuration below already proxies this path to `http://127.0.0.1:15241` with the WebSocket upgrade headers. nginx must run on the host or use host networking to reach that address.

Verify startup in the logs; look for `Connected successfully to 127.0.0.1` and `Server started for 127.0.0.1 on port 15241`:

```bash
docker logs redis-websocket
```

## Run Local nginx

Use `http://localhost/` for local development. [setup/nginx/localhost.conf](setup/nginx/localhost.conf) serves static files, forwards application requests to PHP-FPM on port `9000`, and proxies `/websocket/` to redis-websocket on port `15241`.

Start redis-websocket first. Run these commands from the zKillboard repository root. nginx and PHP must use the same application files at `/app`; use the web container's bind mount example below for live development. Application requests will work once `zkill-www` is started in its default FPM mode below.

Validate the nginx configuration:

```bash
docker run --rm --network host \
	-v "$(pwd)/setup/nginx/localhost.conf:/etc/nginx/conf.d/default.conf:ro" \
	-v "$(pwd)/public:/app/public:ro" \
	nginx:stable nginx -t
```

Start nginx:

```bash
docker run -d --restart unless-stopped --network host --name zkill-nginx \
	-v "$(pwd)/setup/nginx/localhost.conf:/etc/nginx/conf.d/default.conf:ro" \
	-v "$(pwd)/public:/app/public:ro" \
	--log-opt max-size=50m --log-opt max-file=3 \
	nginx:stable
```

This uses Linux host networking and listens on loopback port `80`, with HTTP and `ws://` WebSockets. The local configuration does not enable TLS or proxy caching. Keep `$fullAddr` set to `http://localhost` and `$cookie_ssl` set to `false` in `config.php`.

After editing the nginx configuration, validate and reload it:

```bash
docker exec zkill-nginx nginx -t
docker exec zkill-nginx nginx -s reload
```

See nginx's [FastCGI](https://nginx.org/en/docs/http/ngx_http_fastcgi_module.html) and [WebSocket proxying](https://nginx.org/en/docs/http/websocket.html) documentation for the upstream settings.

## Run Cron Worker

On startup, the container runs `php setup/addIndexes.php` before starting `cron -f`.

```bash
docker run -d --restart unless-stopped --network host --name zkill-cron \
	--log-opt max-size=50m --log-opt max-file=3 \
	zkill-cron
```

Runs `./cron/cron.sh` every minute.

For cron with live code changes:

```bash
docker run -d --restart unless-stopped --network host -v "$(pwd):/app" -v /app/vendor --name zkill-cron \
	--log-opt max-size=50m --log-opt max-file=3 \
	zkill-cron
```

PHP cron jobs have a `memory_limit` of `4G` per process. Concurrent jobs can collectively use more than 4 GB; this is not a container-wide memory cap.

To apply image changes to the local cron container, rebuild and recreate it from the repository root:

```bash
docker build -f Dockerfile.cron -t zkill-cron . &&
docker rm -f zkill-cron &&
docker run -d --restart unless-stopped --network host --name zkill-cron \
	-v "$(pwd):/app" -v /app/vendor \
	--log-opt max-size=50m --log-opt max-file=3 \
	zkill-cron
```

Verify the PHP memory limit (prints `4G`):

```bash
docker exec zkill-cron php -r 'echo ini_get("memory_limit"), PHP_EOL;'
```

The cron image includes `mongosh` for the SDE importer. After changing `Dockerfile.cron`, rebuild the image and recreate the container; restarting an existing container does not install new image dependencies.

Individual job logs are written to `/app/cron/logs/`. With the live code bind mount above, they appear in the host's `cron/logs/` directory too. Without that mount, they stay inside the container. `docker logs zkill-cron` shows startup output and output not redirected by `cron.sh`; Docker's log rotation options apply to that output, while `cron/rotate.sh` handles the job log files.

## Run Web Server

On startup, the container runs `php setup/addIndexes.php` before starting `php-fpm` (or HTTP mode).

```bash
docker run -d --restart unless-stopped --network host --name zkill-www \
	--log-opt max-size=50m --log-opt max-file=3 \
	zkill-www
```

Default mode is `php-fpm` on port `9000` for nginx to connect to.

Check the site and logs:

```bash
curl -I http://localhost/
docker logs zkill-nginx
```

PHP has a `memory_limit` of `4G` per process in both the web and cron images. Concurrent processes can collectively exceed 4 GB. Rebuild and recreate each container to apply PHP configuration changes.

If nginx runs in another container, either:

```bash
# Option A: same host network
docker run -d --restart unless-stopped --network host --name zkill-www zkill-www
```

or

```bash
# Option B: publish FastCGI port on the host
docker run -d --restart unless-stopped -p 9000:9000 --name zkill-www zkill-www
```

`localhost:9000` is FastCGI, not HTTP. Use nginx `fastcgi_pass` to reach it.

To serve HTTP directly from this same image:

```bash
docker run -d --restart unless-stopped --network host -e WWW_MODE=http -e WWW_HTTP_PORT=8000 --name zkill-www zkill-www
```

Then access `http://localhost:8000`.

### Development with volume

```bash
docker run -d --restart unless-stopped --network host -v "$(pwd):/app" -v /app/vendor --name zkill-www \
	--log-opt max-size=50m --log-opt max-file=3 \
	zkill-www
```

### Live Code Changes (Bind Mount)

Use a bind mount so file edits on the host are reflected immediately in the container:

Set `$pugCache = false;` in `config.php` to see template edits immediately.

```bash
# php-fpm mode (default)
docker run -d --restart unless-stopped --network host -v "$(pwd):/app" -v /app/vendor --name zkill-www \
	--log-opt max-size=50m --log-opt max-file=3 \
	zkill-www

# direct HTTP mode
docker run -d --restart unless-stopped --network host -e WWW_MODE=http -e WWW_HTTP_PORT=8000 -v "$(pwd):/app" -v /app/vendor --name zkill-www \
	--log-opt max-size=50m --log-opt max-file=3 \
	zkill-www
```

The separate `/app/vendor` volume is populated from the image's Composer dependencies. It keeps the `/app` bind mount from hiding those dependencies when the host checkout has no `vendor/` directory. Recreate the container with a fresh anonymous volume after rebuilding the image with changed dependencies.

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
