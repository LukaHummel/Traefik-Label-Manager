#!/bin/bash
set -euo pipefail

repo_dir="$(cd "$(dirname "$0")" && pwd)"
cd "$repo_dir"

find source/docker.dns -type f \( -name '*.sh' -o -path '*/event/*' \) -print0 | xargs -0 -n1 bash -n
node --check source/docker.dns/javascript/docker-dns-integration.js
node --check source/docker.dns/javascript/docker-dns-form.js
node --check source/docker.dns/javascript/docker-dns-settings.js
if [[ -d node_modules ]]; then npm run php:parse; fi
xmllint --noout ca_profile.xml plugins/docker-dns.xml icon.svg source/docker.dns/icons/icon.svg docker.dns.plg phpunit.xml

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
grep -F -q 'sleep 3' source/docker.dns/scripts/watch.sh
grep -F -q 'flock(' source/docker.dns/include/SyncEngine.php

if command -v shellcheck >/dev/null 2>&1; then
  {
    printf '%s\0' build.sh test.sh source/pkg_build.sh
    find source/docker.dns -type f \( -name '*.sh' -o -path '*/event/*' \) -print0
  } | xargs -0 shellcheck
fi

if command -v php >/dev/null 2>&1; then
  find source tests -type f -name '*.php' -print0 | xargs -0 -n1 php -l
  if [[ -x vendor/bin/phpunit ]]; then vendor/bin/phpunit; fi
else
  echo 'php is unavailable; PHP lint and PHPUnit were not run locally.' >&2
fi

if [[ -d node_modules ]]; then npm test; else echo 'node_modules is absent; run npm ci to execute Vitest.' >&2; fi
