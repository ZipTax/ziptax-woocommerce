#!/usr/bin/env bash
#
# Build a WordPress Plugin Directory-ready zip for ZipTax Sales Tax.
#
# Usage:
#   ./build.sh          # produces ziptax-sales-tax-3.1.0.zip
#
set -euo pipefail

PLUGIN_SLUG="ziptax-sales-tax"
MAIN_FILE="ziptax-woocommerce.php"

# Extract version from the main plugin file header.
VERSION=$(grep -m1 "^ \* Version:" "$MAIN_FILE" | sed 's/.*Version:[[:space:]]*//')

if [ -z "$VERSION" ]; then
  echo "Error: could not read version from ${MAIN_FILE}" >&2
  exit 1
fi

ZIPFILE="${PLUGIN_SLUG}-${VERSION}.zip"
BUILD_DIR=$(mktemp -d)
DEST="${BUILD_DIR}/${PLUGIN_SLUG}"

echo "Building ${ZIPFILE} ..."

mkdir -p "$DEST/inc"

# Plugin files.
cp "$MAIN_FILE"  "$DEST/"
cp readme.txt    "$DEST/"
cp inc/*.php     "$DEST/inc/"

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
