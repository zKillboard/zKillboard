#!/usr/bin/env bash

set -euo pipefail

DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
CONFIG="$DIR/config.php"
VERSION=$(git -C "$DIR" rev-parse --short HEAD)

if [ ! -f "$CONFIG" ]; then
    echo "Unable to find config.php" >&2
    exit 1
fi

perl -0pi -e 's/\$version = "[^"]*";/\$version = "'"$VERSION"'";/' "$CONFIG"

echo "Updated config.php version to $VERSION"

