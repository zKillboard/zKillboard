#!/usr/bin/env bash

set -euo pipefail

ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)

# Build the application images and set up MongoDB, Redis, and redis-websocket
# as described in Docker.md before using this script.
for image in zkill-www zkill-cron nginx:stable; do
	docker image inspect "$image" >/dev/null
done

docker start zkill-mongo zkill-redis

for ((attempt = 0; attempt < 30; attempt++)); do
	if docker exec zkill-mongo mongosh --quiet --eval 'quit(db.hello().isWritablePrimary ? 0 : 1)' >/dev/null 2>&1 &&
		[[ $(docker exec zkill-redis redis-cli ping) == PONG ]]; then
		break
	fi
	sleep 1
done

if (( attempt == 30 )); then
	echo "MongoDB or Redis is not ready; application containers were not started." >&2
	exit 1
fi

docker start redis-websocket

# Recreate application containers so they always use this checkout's files.
for container in zkill-cron zkill-nginx zkill-www; do
	if docker container inspect "$container" >/dev/null 2>&1; then
		docker stop "$container"
		docker rm -v "$container"
	fi
done

for container in zkill-www zkill-cron; do
	docker run -d --restart unless-stopped --network host \
		-v "$ROOT:/app" -v /app/vendor \
		--name "$container" \
		--log-opt max-size=50m --log-opt max-file=3 \
		"$container"
done

docker run -d --restart unless-stopped --network host --name zkill-nginx \
	-v "$ROOT/setup/nginx/localhost.conf:/etc/nginx/conf.d/default.conf:ro" \
	-v "$ROOT/public:/app/public:ro" \
	--log-opt max-size=50m --log-opt max-file=3 \
	nginx:stable
