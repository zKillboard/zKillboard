#!/usr/bin/env bash

set -euo pipefail

ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)

if ! docker info >/dev/null; then
	echo "Cannot access Docker. Make sure Docker is running and try running this script with sudo." >&2
	exit 1
fi

for image in zkill-www zkill-cron mongo:7.0 redis:7 nginx:stable redis-websocket; do
	docker image inspect "$image" >/dev/null
done

for container in zkill-mongo zkill-redis redis-websocket; do
	if docker container inspect "$container" >/dev/null 2>&1; then
		continue
	fi
	case "$container" in
		zkill-mongo)
			docker create --restart unless-stopped --name zkill-mongo \
				-p 27017:27017 -v zkill-mongo-data:/data/db \
				--log-opt max-size=50m --log-opt max-file=3 \
				mongo:7.0 --replSet rs0 --bind_ip_all
			;;
		zkill-redis)
			docker create --restart unless-stopped --name zkill-redis \
				-p 6379:6379 -v zkill-redis-data:/data \
				--log-opt max-size=50m --log-opt max-file=3 \
				redis:7 redis-server --appendonly yes
			;;
		redis-websocket)
			docker create --restart unless-stopped --network host --name redis-websocket \
				-e SERVERS=127.0.0.1 -e PORT=15241 \
				--log-opt max-size=10m --log-opt max-file=5 \
				redis-websocket
			;;
	esac
done

docker start zkill-mongo zkill-redis

for ((attempt = 0; attempt < 30; attempt++)); do
	if docker exec zkill-mongo mongosh --quiet --eval 'quit(db.adminCommand({ping: 1}).ok ? 0 : 1)' >/dev/null 2>&1; then
		break
	fi
	sleep 1
done

if (( attempt == 30 )); then
	echo "MongoDB is not listening; application containers were not started." >&2
	exit 1
fi

docker exec zkill-mongo mongosh --quiet --eval '
	try {
		db.adminCommand({replSetGetStatus: 1});
	} catch (error) {
		if (error.code !== 94) throw error;
		const result = rs.initiate({_id: "rs0", members: [{_id: 0, host: "localhost:27017"}]});
		if (!result.ok) throw new Error(result.errmsg || "Failed to initialize MongoDB replica set");
	}
'

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
