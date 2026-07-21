#!/bin/bash
set -euo pipefail

: "${PKG_VERSION:?PKG_VERSION is required}"
: "${PKG_BUILD:?PKG_BUILD is required}"

stage="$(mktemp -d /tmp/docker.dns.pkg.XXXXXX)"
trap 'rm -rf "$stage"' EXIT
mkdir -p "$stage/usr/local/emhttp/plugins/docker.dns" "$stage/install" /work/dist
cp -a /work/source/docker.dns/. "$stage/usr/local/emhttp/plugins/docker.dns/"
find "$stage/usr/local/emhttp/plugins/docker.dns/scripts" -type f -exec chmod 755 {} +
cat > "$stage/install/slack-desc" <<'EOF'
docker.dns: docker.dns
docker.dns:
docker.dns: Add opt-in Traefik routing labels through Unraid container forms.
docker.dns: Stores no credentials and does not edit Docker template files.
docker.dns:
docker.dns: https://github.com/LukaHummel/Unraid-Docker-DNS-Plugin
docker.dns:
docker.dns:
docker.dns:
EOF
cd "$stage"
makepkg -l y -c y "/work/dist/docker.dns-${PKG_VERSION}-noarch-${PKG_BUILD}.txz" <<<'y'
