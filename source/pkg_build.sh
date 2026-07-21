#!/bin/bash
set -euo pipefail

: "${PKG_VERSION:?PKG_VERSION is required}"
: "${PKG_BUILD:?PKG_BUILD is required}"

stage="$(mktemp -d /tmp/traefik.label.manager.pkg.XXXXXX)"
trap 'rm -rf "$stage"' EXIT
mkdir -p "$stage/usr/local/emhttp/plugins/traefik.label.manager" "$stage/install" /work/dist
cp -a /work/source/traefik.label.manager/. "$stage/usr/local/emhttp/plugins/traefik.label.manager/"
cat > "$stage/install/slack-desc" <<'EOF'
traefik.label.manager: traefik.label.manager
traefik.label.manager:
traefik.label.manager: View and edit Traefik labels for Unraid containers.
traefik.label.manager: Saves labels in Unraid templates and applies them on demand.
traefik.label.manager:
traefik.label.manager: https://github.com/LukaHummel/Traefik-Label-Manager
traefik.label.manager:
traefik.label.manager:
traefik.label.manager:
EOF
cd "$stage"
makepkg -l y -c y "/work/dist/traefik.label.manager-${PKG_VERSION}-noarch-${PKG_BUILD}.txz" <<<'y'
