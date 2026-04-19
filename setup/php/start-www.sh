#!/bin/sh
set -eu

mode="${WWW_MODE:-fpm}"

case "$mode" in
    fpm)
        exec php-fpm -F
        ;;
    http)
        port="${WWW_HTTP_PORT:-8000}"
        exec php -S 0.0.0.0:"$port" -t public
        ;;
    *)
        echo "Unsupported WWW_MODE: $mode (expected: fpm or http)" >&2
        exit 64
        ;;
esac