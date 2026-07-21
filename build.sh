#!/bin/bash
set -euo pipefail

version="${1:-2026.07.21.1}"
build="${2:-1}"
repo_dir="$(cd "$(dirname "$0")" && pwd)"
mkdir -p "$repo_dir/dist"

docker build -t docker-dns-plugin-builder "$repo_dir/build"
docker run --rm \
  -e PKG_VERSION="$version" \
  -e PKG_BUILD="$build" \
  -v "$repo_dir:/work" \
  docker-dns-plugin-builder \
  /bin/bash /work/source/pkg_build.sh

package="docker.dns-${version}-noarch-${build}.txz"
md5="$(md5sum "$repo_dir/dist/$package" | awk '{print $1}')"
sed -e 's/<!ENTITY version "[^"]*">/<!ENTITY version "'"$version"'">/' \
    -e 's/<!ENTITY build "[^"]*">/<!ENTITY build "'"$build"'">/' \
    -e "s#<MD5>[0-9a-f]*</MD5>#<MD5>$md5</MD5>#" \
    "$repo_dir/docker.dns.plg" > "$repo_dir/dist/docker.dns.plg"
cp "$repo_dir/dist/docker.dns.plg" "$repo_dir/docker.dns.plg"
echo "Built dist/$package and dist/docker.dns.plg"
