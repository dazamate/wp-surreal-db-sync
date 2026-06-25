<?php

namespace Dazamate\SurrealGraphSync\Tests\Migration;

use PHPUnit\Framework\TestCase;
use Dazamate\SurrealGraphSync\Migration\InitialMigration;

/**
 * Unit coverage for InitialMigration. The migration is deliberately a no-op
 * "schemaless-first" example (SurrealDB auto-creates tables on UPSERT, and we
 * avoid SCHEMAFULL so plugins can extend tables freely), so up()/down() return
 * no queries. These tests pin that contract and confirm the migration still
 * registers itself on the filter with the expected data shape.
 */
class InitialMigrationTest extends TestCase {
    public function testUpDefinesNoSchemaByDefault(): void {
        // Schemaless-first: no DEFINE TABLE/FIELD statements are emitted.
        $this->assertSame([], InitialMigration::up());
    }

    public function testDownIsEmpty(): void {
        // Nothing to roll back while up() defines nothing.
        $this->assertSame([], InitialMigration::down());
    }

    public function testBuildMigrationDataRegistersUnderGroup(): void {
        $migrations = InitialMigration::build_migration_data([]);

        $this->assertArrayHasKey('generic_wordpress_types', $migrations);
        $this->assertArrayHasKey('initial_migration', $migrations['generic_wordpress_types']);

        $entry = $migrations['generic_wordpress_types']['initial_migration'];
        $this->assertSame('initial_migration', $entry['name']);
        $this->assertSame('2025-01-01', $entry['datetime']);
        $this->assertIsArray($entry['up']);
        $this->assertIsArray($entry['down']);
    }
}
