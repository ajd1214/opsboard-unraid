#!/bin/bash
# Build packages/opsboard-<version>.txz from src/ and write its SHA256 into opsboard.plg.
# Usage: ./build.sh   (version taken from opsboard.plg)
set -euo pipefail
cd "$(dirname "$0")"
V=$(grep -oP '<!ENTITY version\s+"\K[^"]+' opsboard.plg)
SRC=src/usr/local/emhttp/plugins/opsboard
for f in "$SRC"/include/*.php; do php -l "$f" >/dev/null; done
for f in "$SRC"/*.page; do sed '1,/^---$/d' "$f" > /tmp/ob-lint.php; php -l /tmp/ob-lint.php >/dev/null; done
find src -type d -exec chmod 755 {} +; find src -type f -exec chmod 644 {} +
mkdir -p packages
rm -f packages/opsboard-*.txz
tar --owner=0 --group=0 --sort=name --mtime='2026-01-01 00:00Z' -cJf "packages/opsboard-$V.txz" -C src .
SHA=$(sha256sum "packages/opsboard-$V.txz" | cut -d' ' -f1)
sed -i -E "s#(<!ENTITY pkgSHA\s+\")[^\"]*#\1$SHA#" opsboard.plg
echo "built packages/opsboard-$V.txz  sha256=$SHA"
