#!/bin/bash
set -euo pipefail

repo_dir="$(cd "$(dirname "$0")" && pwd)"
cd "$repo_dir"

node --check source/traefik.label.manager/javascript/traefik-label-form.js
node --check source/traefik.label.manager/javascript/traefik-label-settings.js
xmllint --noout ca_profile.xml plugins/traefik-label-manager.xml icon.svg source/traefik.label.manager/icons/icon.svg traefik.label.manager.plg

if find . -path './node_modules' -prune -o -path './vendor' -prune -o -type d -name templates -print | grep -q .; then
  echo 'This plugin-only repository must not contain a templates directory.' >&2
  exit 1
fi

grep -Eq '<MD5>[0-9a-f]{32}</MD5>' traefik.label.manager.plg
grep -F -q '**Traefik Label Manager**' source/traefik.label.manager/README.md
grep -F -q 'providers.docker.exposedByDefault=false' source/traefik.label.manager/README.md
grep -F -q 'providers.docker.useBindPortIP=true' source/traefik.label.manager/README.md
grep -F -q 'exposedByDefault: false' README.md
grep -F -q 'useBindPortIP: true' README.md
grep -F -q 'exposedByDefault=false' plugins/traefik-label-manager.xml
grep -F -q 'useBindPortIP=true' plugins/traefik-label-manager.xml
grep -F -q 'Point *.home.arpa at Traefik' plugins/traefik-label-manager.xml
grep -F -q 'Menu="NetworkServices:50"' source/traefik.label.manager/traefik.label.manager.settings.page
if grep -F -q 'Type="xmenu"' source/traefik.label.manager/traefik.label.manager.settings.page; then
  echo 'The settings page must render as a normal Network Services child page.' >&2
  exit 1
fi
grep -F -q 'launch="Settings/traefik.label.manager.settings"' traefik.label.manager.plg
grep -F -q '/boot/config/plugins/dockerMan/templates-user' source/traefik.label.manager/include/bootstrap.php

expected_source_files="$(printf '%s\n' \
  source/traefik.label.manager/README.md \
  source/traefik.label.manager/TraefikLabelManagerIntegration.page \
  source/traefik.label.manager/icons/icon.svg \
  source/traefik.label.manager/include/Api.php \
  source/traefik.label.manager/include/CsrfException.php \
  source/traefik.label.manager/include/LabelManager.php \
  source/traefik.label.manager/include/bootstrap.php \
  source/traefik.label.manager/javascript/traefik-label-form.js \
  source/traefik.label.manager/javascript/traefik-label-settings.js \
  source/traefik.label.manager/styles/traefik-label-manager.css \
  source/traefik.label.manager/traefik.label.manager.settings.page)"
actual_source_files="$(find source/traefik.label.manager -type f | sort)"
if [[ "$actual_source_files" != "$expected_source_files" ]]; then
  echo 'Runtime source contains files outside the expected allowlist.' >&2
  diff -u <(printf '%s\n' "$expected_source_files") <(printf '%s\n' "$actual_source_files") || true
  exit 1
fi

if command -v shellcheck >/dev/null 2>&1; then
  printf '%s\0' build.sh test.sh source/pkg_build.sh | xargs -0 shellcheck
fi

if command -v php >/dev/null 2>&1; then
  find source tests -type f -name '*.php' -print0 | xargs -0 -n1 php -l
  php tests/php/run.php
else
  echo 'php is unavailable; PHP checks were not run.' >&2
fi

if [[ -d node_modules ]]; then npm test; else echo 'node_modules is absent; run npm ci to execute Vitest.' >&2; fi
