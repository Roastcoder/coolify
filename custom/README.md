# Roastcoder Coolify fork

This fork tracks [coollabsio/coolify](https://github.com/coollabsio/coolify) and adds our own changes on `main`.

## How updates flow

1. **Custom - Sync Upstream Coolify** (`.github/workflows/custom-sync-upstream.yml`) runs daily at 03:00 UTC
   (or manually from the Actions tab). It merges upstream `main` into our `main`. Our changes are kept.
   If upstream touches the same lines we changed, it opens an issue named "Upstream sync conflict" instead.
2. **Custom - Build Coolify Image** (`.github/workflows/custom-build.yml`) runs on every push to `main`
   and after each successful sync. It publishes `ghcr.io/roastcoder/coolify:latest` (amd64 + arm64).
3. The Coolify server pulls that image with `custom/update.sh`.

## One-time setup

### GitHub

- Create a classic personal access token with `repo` and `workflow` scopes and save it as the
  repository secret `SYNC_TOKEN` (Settings → Secrets and variables → Actions). Without it, syncs that
  include upstream workflow changes are rejected by GitHub.
- After the first build, open the `coolify` package (Profile → Packages) and set it to **Public**,
  or log the server in to ghcr.io with `docker login ghcr.io`.

### Coolify server

```bash
sudo curl -fsSL https://raw.githubusercontent.com/Roastcoder/coolify/main/custom/docker-compose.custom.yml \
  -o /data/coolify/source/docker-compose.custom.yml
sudo curl -fsSL https://raw.githubusercontent.com/Roastcoder/coolify/main/custom/update.sh \
  -o /data/coolify/source/update-custom.sh
sudo bash /data/coolify/source/update-custom.sh
```

Then in the Coolify UI go to **Settings → Updates** and turn **Auto Update** off, so the
official updater does not replace the compose files on its own schedule. (The override file
survives official upgrades, but updating through `update-custom.sh` keeps things predictable.)

## Customizing

Edit the code on `main`, push, and wait for the build to finish, then run
`sudo bash /data/coolify/source/update-custom.sh` on the server.

Keep changes small and in as few upstream files as possible; new files (like this folder) never
conflict with upstream. Common places:

- UI views: `resources/views/`
- Livewire components: `app/Livewire/`
- Logos / static assets: `public/`
- Config: `config/`
