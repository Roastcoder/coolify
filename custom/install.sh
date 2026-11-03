#!/bin/bash
# Installs StandaloneCoders Cloud on a fresh server (run as root):
#   curl -fsSL https://raw.githubusercontent.com/Roastcoder/standalonecoders-cloud/main/custom/install.sh | sudo bash
# Coolify's official installer sets up Docker, the database and the compose files; this then
# switches the app image to ours. Running it on an existing Coolify server switches that server.
set -euo pipefail

RAW="https://raw.githubusercontent.com/Roastcoder/standalonecoders-cloud/main/custom"
SOURCE_DIR=/data/coolify/source

if [ "$(id -u)" != 0 ]; then
    echo "Run as root, for example: curl -fsSL $RAW/install.sh | sudo bash"
    exit 1
fi

if [ ! -f "$SOURCE_DIR/docker-compose.yml" ]; then
    echo "Installing the Coolify base..."
    curl -fsSL https://cdn.coollabs.io/coolify/install.sh | bash
fi

echo "Switching to StandaloneCoders Cloud..."
curl -fsSL "$RAW/docker-compose.custom.yml" -o "$SOURCE_DIR/docker-compose.custom.yml"
curl -fsSL "$RAW/update.sh" -o "$SOURCE_DIR/update-custom.sh"
chmod +x "$SOURCE_DIR/update-custom.sh"
bash "$SOURCE_DIR/update-custom.sh"

echo "StandaloneCoders Cloud is running. Open http://$(curl -fsS4 https://ifconfig.me 2>/dev/null || hostname -I | awk '{print $1}'):8000 to create the admin account."
echo "Updates: Settings > Updates shows new versions and installs them with one click."
