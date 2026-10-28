#!/usr/bin/env bash

set -euo pipefail

docker stop zkill-cron zkill-nginx zkill-www redis-websocket
docker stop zkill-redis zkill-mongo
