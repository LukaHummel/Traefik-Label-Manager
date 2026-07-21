#!/bin/bash
set -euo pipefail

repo_dir="$(cd "$(dirname "$0")" && pwd)"
cd "$repo_dir"

find source/docker.dns/scripts -type f -name '*.sh' -print0 | xargs -0 -n1 bash -n
node --check source/docker.dns/javascript/docker-dns-form.js
xmllint --noout ca_profile.xml plugins/docker-dns.xml icon.svg source/docker.dns/icons/icon.svg docker.dns.plg

if grep -R -n -E 'dockerMan/templates|templates-user' source; then
  echo 'Forbidden Docker template path found in runtime source.' >&2
  exit 1
fi
if find . -path './node_modules' -prune -o -path './vendor' -prune -o -type d -name templates -print | grep -q .; then
  echo 'This plugin-only repository must not contain a templates directory.' >&2
  exit 1
fi

grep -Eq '<MD5>[0-9a-f]{32}</MD5>' docker.dns.plg
grep -F -q '**Docker DNS**' source/docker.dns/README.md
grep -F -q 'providers.docker.exposedByDefault=false' source/docker.dns/docker.dns.settings.page
grep -F -q 'providers.docker.useBindPortIP=true' source/docker.dns/docker.dns.settings.page

cleanup_line="$(grep -n 'legacy_root=' docker.dns.plg | cut -d: -f1)"
package_line="$(grep -n '<FILE Name=' docker.dns.plg | cut -d: -f1)"
if [[ -z "$cleanup_line" || -z "$package_line" || "$cleanup_line" -ge "$package_line" ]]; then
  echo 'Legacy cleanup must run before package replacement.' >&2
  exit 1
fi

for retired in include event docker.dns.cron javascript/docker-dns-integration.js javascript/docker-dns-settings.js scripts/cron.sh scripts/install-cron.sh scripts/service.sh scripts/watch.sh; do
  if [[ -e "source/docker.dns/$retired" ]]; then
    echo "Retired runtime component remains: $retired" >&2
    exit 1
  fi
done

if rg -n -i 'adguard|pi-hole|caddy|curl|secrets\.json|overrides\.json|state\.json|docker\.dns\.cron' source/docker.dns --glob '!**/scripts/*.sh'; then
  echo 'Retired DNS/proxy runtime reference remains.' >&2
  exit 1
fi

if command -v shellcheck >/dev/null 2>&1; then
  {
    printf '%s\0' build.sh test.sh source/pkg_build.sh
    find source/docker.dns/scripts -type f -name '*.sh' -print0
  } | xargs -0 shellcheck
fi

if [[ -d node_modules ]]; then npm test; else echo 'node_modules is absent; run npm ci to execute Vitest.' >&2; fi
