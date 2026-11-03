#!/bin/bash
# Installs StandaloneCoders Cloud on a fresh server or switches an existing Coolify server.
# Run as root:
#   curl -fsSL https://raw.githubusercontent.com/Roastcoder/coolify/main/custom/install.sh | sudo bash
#
# If the GitHub Container Registry package is private, provide credentials:
#   GH_TOKEN="<your-pat>" GH_USER="<your-username>" curl -fsSL https://raw.githubusercontent.com/Roastcoder/coolify/main/custom/install.sh | sudo -E bash
set -euo pipefail

SOURCE_DIR=/data/coolify/source

if [ "$(id -u)" != 0 ]; then
    echo "Please run as root (e.g. using sudo bash)."
    exit 1
fi

# Authenticate with GHCR if a token is provided (for private packages)
if [ -n "${GH_TOKEN:-${GITHUB_TOKEN:-}}" ]; then
    TOKEN="${GH_TOKEN:-${GITHUB_TOKEN}}"
    USER="${GH_USER:-${GITHUB_USER:-Roastcoder}}"
    echo "Authenticating Docker with ghcr.io as $USER..."
    echo "$TOKEN" | docker login ghcr.io -u "$USER" --password-stdin || {
        echo "Warning: docker login to ghcr.io failed. Continuing with public pull..."
    }
fi

if [ ! -f "$SOURCE_DIR/docker-compose.yml" ]; then
    echo "Installing base Coolify infrastructure..."
    curl -fsSL https://cdn.coollabs.io/coolify/install.sh | bash
fi

echo "Configuring StandaloneCoders Cloud..."
mkdir -p "$SOURCE_DIR"

# Write docker-compose.custom.yml self-contained
cat <<'EOF' > "$SOURCE_DIR/docker-compose.custom.yml"
services:
  coolify:
    image: "ghcr.io/roastcoder/standalonecoders-cloud:latest"
    pull_policy: always
EOF

# Write update-custom.sh self-contained
cat <<'EOF' > "$SOURCE_DIR/update-custom.sh"
#!/bin/bash
set -euo pipefail
SOURCE_DIR=/data/coolify/source
cd "$SOURCE_DIR"
COMPOSE="docker compose --env-file .env -f docker-compose.yml -f docker-compose.prod.yml -f docker-compose.custom.yml"
$COMPOSE pull coolify
$COMPOSE up -d --wait coolify
$COMPOSE ps coolify
EOF

chmod +x "$SOURCE_DIR/update-custom.sh"
bash "$SOURCE_DIR/update-custom.sh"

SERVER_IP=$(curl -fsS4 https://ifconfig.me 2>/dev/null || hostname -I | awk '{print $1}')
echo ""
echo "================================================================="
echo " StandaloneCoders Cloud is running!"
echo " Open http://${SERVER_IP}:8000 in your browser."
echo " Manage updates anytime in Settings > Updates."
echo "================================================================="
