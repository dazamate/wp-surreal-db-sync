<?php

namespace Dazamate\SurrealGraphSync\Tests\Integration;

use Dazamate\SurrealGraphSync\Migration\InitialMigration;

/**
 * Proves the "schemaless-first" decision against a live SurrealDB: a person
 * record upserts successfully WITHOUT any migration defining the table first
 * (SurrealDB creates the table on the first write), and the now-empty
 * InitialMigration up()/down() run cleanly as no-ops.
 */
class MigrationIntegrationTest extends IntegrationTestCase {
    /** @param string[] $queries */
    private function runQueries(array $queries): void {
        foreach ($queries as $q) {
            if (empty($q)) continue;
            $this->db->query($q);
        }
    }

    private function dbTables(): array {
        $info = $this->db->query('INFO FOR DB;')[0];
        return array_keys($info['tables'] ?? []);
    }

    public function testPersonUpsertsWithoutAnyMigration(): void {
        // No DEFINE TABLE / DEFINE FIELD — go straight to writing a person record.
        // On a schemaless DB this both creates the `person` table and accepts the
        // arbitrary fields, which is exactly why the schema migration is optional.
        $res = $this->db->query(
            "UPSERT person CONTENT { username: <string>'bob', email: <string>'bob@example.com', display_name: <string>'Bob', user_id: <number>5 } WHERE user_id = 5 RETURN id;"
        );
        $this->assertNotEmpty($res[0], 'person upsert should return the created record id');

        $this->assertContains('person', $this->dbTables());

        $rows = $this->db->query('SELECT * FROM person WHERE user_id = 5;')[0];
        $this->assertCount(1, $rows);
        $this->assertSame('bob', $rows[0]['username']);
    }

    public function testInitialMigrationUpAndDownAreNoOps(): void {
        // up()/down() emit no queries today; running them must not throw and must
        // not create any tables.
        $this->runQueries(InitialMigration::up());
        $this->runQueries(InitialMigration::down());

        $tables = $this->dbTables();
        $this->assertNotContains('image', $tables);
        $this->assertNotContains('person', $tables);
    }
}
