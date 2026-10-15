#!/usr/bin/env bash

set -euo pipefail

if (( EUID != 0 )); then
	echo "Run this script with sudo." >&2
	exit 1
fi

if ! command -v docker >/dev/null 2>&1; then
	echo "Docker is not installed or not in PATH." >&2
	exit 1
fi

ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
cd "$ROOT"

docker build --pull -f Dockerfile.www -t zkill-www . &
www_build_pid=$!
docker build --pull -f Dockerfile.cron -t zkill-cron . &
cron_build_pid=$!

build_failed=0
wait "$www_build_pid" || build_failed=1
wait "$cron_build_pid" || build_failed=1

if (( build_failed )); then
	echo "One or more Docker builds failed; existing containers were not stopped." >&2
	exit 1
fi

for container in zkill-www zkill-cron; do
	if docker container inspect "$container" >/dev/null 2>&1; then
		docker stop "$container"
		docker rm "$container"
	fi
done

docker run -d --restart unless-stopped --network host \
	-v "$ROOT:/app" -v /app/vendor \
	--name zkill-www \
	--log-opt max-size=50m --log-opt max-file=3 \
	zkill-www

docker run -d --restart unless-stopped --network host \
	-v "$ROOT:/app" -v /app/vendor \
	--name zkill-cron \
	--log-opt max-size=50m --log-opt max-file=3 \
	zkill-cron

docker ps --filter name=zkill-www --filter name=zkill-cron
docker image prune -f
