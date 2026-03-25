#!/usr/bin/env bash
#
# Build a WordPress Plugin Directory-ready zip for ZipTax WooCommerce.
#
# Usage:
#   ./build.sh          # produces ziptax-woocommerce-3.0.0.zip
#
set -euo pipefail

PLUGIN_SLUG="ziptax-woocommerce"

# Extract version from the main plugin file header.
VERSION=$(grep -m1 "^ \* Version:" "${PLUGIN_SLUG}.php" | sed 's/.*Version:[[:space:]]*//')

if [ -z "$VERSION" ]; then
  echo "Error: could not read version from ${PLUGIN_SLUG}.php" >&2
  exit 1
fi

ZIPFILE="${PLUGIN_SLUG}-${VERSION}.zip"
BUILD_DIR=$(mktemp -d)
DEST="${BUILD_DIR}/${PLUGIN_SLUG}"

echo "Building ${ZIPFILE} ..."

mkdir -p "$DEST/inc"

# Plugin files.
cp ziptax-woocommerce.php "$DEST/"
cp readme.txt             "$DEST/"
cp inc/*.php              "$DEST/inc/"

# Optional: languages directory if it exists.
if [ -d "languages" ]; then
  cp -r languages "$DEST/"
fi

# Optional: assets directory (banner/icon for wp.org) if it exists.
if [ -d "assets" ]; then
  cp -r assets "$DEST/"
fi

# Create the zip (from the temp dir so the archive root is the slug folder).
cd "$BUILD_DIR"
zip -rq "$OLDPWD/$ZIPFILE" "$PLUGIN_SLUG"
cd "$OLDPWD"

# Clean up.
rm -rf "$BUILD_DIR"

echo "Done: ${ZIPFILE} ($(du -h "$ZIPFILE" | cut -f1))"
