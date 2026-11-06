#!/usr/bin/env bash
# Builds the installable archive: dist/injellik-recommendations-<version>.zip
# The archive contains a single "injellik-recommendations/" folder, as expected by Plugins > Add New > Upload Plugin.
set -euo pipefail
cd "$(dirname "$0")"

PLUGIN=injellik-recommendations
VERSION=$(sed -n "s/^ \* Version: *\([0-9.]*\).*/\1/p" "$PLUGIN/$PLUGIN.php")
CONSTANT=$(sed -n "s/.*define( 'INJELLIK_VERSION', '\([^']*\)' ).*/\1/p" "$PLUGIN/$PLUGIN.php")
if [ "$VERSION" != "$CONSTANT" ]; then
    echo "Version mismatch: header $VERSION, INJELLIK_VERSION $CONSTANT" >&2
    exit 1
fi
OUT="dist/$PLUGIN-$VERSION.zip"

mkdir -p dist
rm -f "$OUT"
zip -qr "$OUT" "$PLUGIN" \
    -x "*/.DS_Store" \
    -x "*/Thumbs.db"

echo "$OUT"
