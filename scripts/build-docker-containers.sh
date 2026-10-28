#!/usr/bin/env bash

set -euo pipefail

if (( $# > 1 )); then
	echo "Usage: $0 [all|zkill-www|zkill-cron|zkill-mongo|zkill-redis|zkill-nginx|redis-websocket]" >&2
	exit 1
fi

case "${1:-all}" in
	all) containers=(zkill-www zkill-cron zkill-mongo zkill-redis zkill-nginx redis-websocket) ;;
	zkill-www|zkill-cron|zkill-mongo|zkill-redis|zkill-nginx|redis-websocket) containers=("$1") ;;
	*)
		echo "Unknown container: $1" >&2
		exit 1
		;;
esac

ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)

for container in "${containers[@]}"; do
	case "$container" in
		zkill-www|zkill-cron)
			docker build --pull -f "$ROOT/Dockerfile.${container#zkill-}" -t "$container" "$ROOT"
			;;
		zkill-mongo) docker pull mongo:7.0 ;;
		zkill-redis) docker pull redis:7 ;;
		zkill-nginx) docker pull nginx:stable ;;
		redis-websocket)
			docker build --pull -t redis-websocket https://github.com/cvweiss/redis-websocket.git
			;;
	esac
done
