#!/usr/bin/env bash
#
# Build an installable, production-only plugin zip.
#
#   - bundles ONLY runtime files (no docker/, bin/, tests/, phpstan/phpunit, …)
#   - installs production composer deps only (--no-dev)
#   - namespace-prefixes every bundled dependency with php-scoper so the plugin
#     can't clash with other plugins that ship the same libraries (see
#     scoper.inc.php)
#
# Everything runs inside the PHP 8.5 container; the host PHP version is
# irrelevant. Output: build/surreal-graph-sync/ and build/surreal-graph-sync.zip
#
set -euo pipefail

cd "$(dirname "$0")/.."

# Re-exec inside the toolchain container when invoked from the host.
if [ ! -f /.dockerenv ]; then
    docker compose build php
    exec docker compose run --rm php bash bin/build.sh "$@"
fi

# ---------------------------------------------------------------------------
# From here down we are inside the container.
# ---------------------------------------------------------------------------

SLUG="surreal-graph-sync"
BUILD_DIR="build"
DIST="${BUILD_DIR}/${SLUG}"

echo "==> Cleaning ${BUILD_DIR}/"
rm -rf "${BUILD_DIR}"
mkdir -p "${DIST}"

echo "==> Installing production dependencies (--no-dev)"
composer install --no-dev --optimize-autoloader --classmap-authoritative --no-interaction

echo "==> Scoping namespaces into ${DIST}/"
# Prefixes vendor namespaces and rewrites our source's references to match.
php-scoper add-prefix \
    --config=scoper.inc.php \
    --output-dir="${DIST}" \
    --force \
    --no-interaction \
    --quiet

echo "==> Regenerating the scoped autoloader"
# composer.json carries the PSR-4 map for our (un-prefixed) source + the
# Migration classmap. It is removed from the dist afterwards so a later
# `composer install` on the target site can't overwrite the scoped vendor/.
cp composer.json "${DIST}/composer.json"
composer dump-autoload \
    --working-dir="${DIST}" \
    --classmap-authoritative \
    --no-dev \
    --no-interaction
rm -f "${DIST}/composer.json"

echo "==> Packaging ${BUILD_DIR}/${SLUG}.zip"
( cd "${BUILD_DIR}" && zip -qr "${SLUG}.zip" "${SLUG}" )

# Restore the full dev dependency set for the local working tree, since the
# build step replaced vendor/ with a production-only install. Skipped in CI
# (SKIP_DEV_RESTORE=1), where the working tree is throwaway and we don't want
# to pull dev dependencies after a production build.
if [ "${SKIP_DEV_RESTORE:-0}" = "1" ]; then
    echo "==> Skipping dev dependency restore (SKIP_DEV_RESTORE=1)"
else
    echo "==> Restoring dev dependencies in the working tree"
    composer install --no-interaction >/dev/null
fi

echo ""
echo "Done. Installable plugin: ${BUILD_DIR}/${SLUG}.zip"
echo "Unzipped tree:            ${DIST}/"
