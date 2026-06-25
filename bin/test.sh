#!/usr/bin/env bash
#
# Run the PHPUnit suite inside the PHP 8.5 container.
# Any arguments are forwarded to phpunit, e.g.:
#   bin/test.sh --filter QueryBuilder
#
set -euo pipefail

cd "$(dirname "$0")/.."

# Tear down the ephemeral SurrealDB (and any leftover containers) on exit so a
# stale/unhealthy DB never lingers between runs.
cleanup() { docker compose down --remove-orphans >/dev/null 2>&1 || true; }
trap cleanup EXIT

docker compose build php
docker compose run --rm php composer install --no-interaction
# `run` honours depends_on, so this waits for the surrealdb healthcheck first.
docker compose run --rm php vendor/bin/phpunit "$@"
