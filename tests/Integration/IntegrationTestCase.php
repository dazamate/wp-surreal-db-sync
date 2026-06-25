<?php

namespace Dazamate\SurrealGraphSync\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Surreal\Surreal;

/**
 * Base class for tests that run against a real SurrealDB instance.
 *
 * Connection details come from the SURREAL_TEST_* environment variables, which
 * docker-compose.yml sets on the `php` service and points at the ephemeral,
 * in-memory `surrealdb` service. Each test gets its own freshly-created
 * database so tests can't see each other's records.
 */
abstract class IntegrationTestCase extends TestCase {
    protected Surreal $db;

    protected function setUp(): void {
        parent::setUp();

        $addr = getenv('SURREAL_TEST_ADDR');
        $user = getenv('SURREAL_TEST_USER');
        $pass = getenv('SURREAL_TEST_PASS');
        $ns   = getenv('SURREAL_TEST_NS') ?: 'test';

        if (!$addr || !$user || !$pass) {
            $this->markTestSkipped('SURREAL_TEST_* env not set; run via bin/test.sh (docker compose).');
        }

        // A unique database per test keeps state isolated without a teardown.
        $db_name = 'test_' . str_replace('.', '_', uniqid('', true));

        $this->db = new Surreal();
        $this->db->connect($addr, [
            'namespace'    => $ns,
            'database'     => $db_name,
            'versionCheck' => false,
        ]);

        $this->db->signin([
            'user' => $user,
            'pass' => $pass,
        ]);

        // signin clears any pre-auth namespace/database selection on some
        // engines, so re-select after authenticating.
        $this->db->use([
            'namespace' => $ns,
            'database'  => $db_name,
        ]);
    }

    protected function tearDown(): void {
        // Intentionally not calling $this->db->close(): the SDK's HTTP engine
        // calls curl_close() there, which is deprecated (and a no-op) on PHP 8.5
        // and would add noise to every run. Connections are per-test and the
        // process reclaims the curl handle on exit.
        parent::tearDown();
    }

    /**
     * Run a SurrealQL string and return the per-statement results, each already
     * unwrapped to its `result` payload (matches the plugin's usage).
     */
    protected function query(string $surql): ?array {
        return $this->db->query($surql);
    }

    /**
     * Run a SurrealQL string and return the raw per-statement responses,
     * including the `status` field (needed to detect failed/rolled-back
     * statements, which query() hides).
     *
     * @return array<int,array{status?:string,result?:mixed,time?:string}>|null
     */
    protected function queryRaw(string $surql): ?array {
        return $this->db->queryRaw($surql);
    }
}
