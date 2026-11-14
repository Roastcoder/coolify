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

# Write update-custom.sh self-contained (same as custom/update.sh)
cat <<'EOF' > "$SOURCE_DIR/update-custom.sh"
#!/bin/bash
# Run on the Coolify server (as root) to pull and restart the image built from this fork.
# Writes its progress to .custom-update-status.json, which the panel shows while it updates.
set -euo pipefail

SOURCE_DIR=/data/coolify/source
STATUS="$SOURCE_DIR/.custom-update-status.json"
cd "$SOURCE_DIR"

status() {
    printf '{"stage":"%s","percent":%s,"message":"%s","at":"%s"}\n' "$1" "$2" "$3" "$(date -u +%Y-%m-%dT%H:%M:%SZ)" > "$STATUS.tmp"
    mv "$STATUS.tmp" "$STATUS"
}
trap 'status failed 100 "The update failed. The current version keeps running."' ERR

if [ ! -f docker-compose.custom.yml ]; then
    echo "Missing $SOURCE_DIR/docker-compose.custom.yml - copy it from custom/ in the fork first."
    status failed 100 "Update files are missing on the server."
    exit 1
fi

COMPOSE="docker compose --env-file .env -f docker-compose.yml -f docker-compose.prod.yml -f docker-compose.custom.yml"
status starting 5 "Starting the update"
IMAGE=$($COMPOSE config --images coolify | head -n 1)

# Download: 10% to 70%, by image layers finished.
status downloading 10 "Downloading the new version"
docker pull "$IMAGE" 2>&1 | {
    declare -A layers=() finished=()
    while IFS= read -r line; do
        echo "$line"
        id=${line%%:*}
        case "$line" in
            *": Pulling fs layer"* | *": Waiting"*) layers[$id]=1 ;;
            *": Already exists"*) layers[$id]=1 finished[$id]=1 ;;
            *": Pull complete"*) layers[$id]=1 finished[$id]=1 ;;
            *) continue ;;
        esac
        # Report on finished downloads only: by then docker has listed every layer.
        [[ "$line" == *": Pull complete"* ]] || continue
        status downloading $((10 + 60 * ${#finished[@]} / ${#layers[@]})) "Downloading the new version"
    done
}

# Restart: the panel goes down here and comes back on the new version.
status restarting 75 "Restarting the panel"
$COMPOSE up -d --wait coolify
$COMPOSE ps coolify
status done 100 "Updated"
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
