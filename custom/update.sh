#!/bin/bash
# Run on the Coolify server (as root) to pull and restart the image built from this fork.
set -euo pipefail

SOURCE_DIR=/data/coolify/source
cd "$SOURCE_DIR"

if [ ! -f docker-compose.custom.yml ]; then
    echo "Missing $SOURCE_DIR/docker-compose.custom.yml - copy it from custom/ in the fork first."
    exit 1
fi

COMPOSE="docker compose --env-file .env -f docker-compose.yml -f docker-compose.prod.yml -f docker-compose.custom.yml"
$COMPOSE pull coolify
$COMPOSE up -d --wait coolify
$COMPOSE ps coolify
