# Development

Notes for working on the plugin itself: building an installable release and running the
test/static-analysis tooling.

## Building a release

```bash
bash bin/build.sh
```

This produces an installable, production-only package — `build/surreal-graph-sync.zip`
(and the unzipped `build/surreal-graph-sync/` tree) — containing only the runtime files
(`surreal-graph-sync.php`, `includes/`, `vendor/`). All dev tooling (Docker files, `bin/`,
`tests/`, `docs/`, PHPStan/PHPUnit config, `composer.json`/`composer.lock`) is excluded.

Crucially, the bundled dependencies are **namespace-prefixed** with
[php-scoper](https://github.com/humbug/php-scoper) — every vendor class is rewritten under
`Dazamate\SurrealGraphSync\Vendor\…`. WordPress loads all active plugins in one PHP
process, so without this prefixing a different plugin shipping a different version of a
shared library (`psr/log`, `ramsey/uuid`, `brick/math`, …) could shadow ours and break the
sync. Prefixing makes our copies collision-proof. See `scoper.inc.php` for the config.

The build runs entirely inside the PHP 8.5 container (the script re-execs itself there), so
the host PHP version is irrelevant, and your local `vendor/` is restored to the full dev
set afterwards.

## Running tests and static analysis

Both helper scripts run inside the same PHP 8.5 Docker container (see `docker-compose.yml`),
so you don't need PHP or the dependencies installed on the host. Any extra arguments are
forwarded straight to the underlying tool.

```bash
# PHPUnit suite — spins up an ephemeral SurrealDB for the integration tests and
# tears it down afterwards. Forward args to phpunit, e.g. filter a single test:
bash bin/test.sh
bash bin/test.sh --filter QueryBuilder

# PHPStan static analysis (no database needed). Forward args to phpstan, e.g.:
bash bin/stan.sh
bash bin/stan.sh --generate-baseline
```
