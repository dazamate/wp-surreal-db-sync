<?php

namespace Dazamate\SurrealGraphSync\Tests\Settings;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Dazamate\SurrealGraphSync\Settings\MigrationsPage;
use Dazamate\SurrealGraphSync\Enum\MigrationDirection;

/**
 * Unit coverage for MigrationsPage::get_migration_queries — the lookup that
 * resolves a single migration (by name) to its ordered up/down query list. This
 * is the path the per-migration "Migrate up/down" buttons now use instead of the
 * old (broken) class-based runner.
 */
class MigrationsPageTest extends TestCase {
    /** @var array<string,mixed> */
    private array $registered = [];

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        $this->registered = [
            'generic_wordpress_types' => [
                'initial_migration' => [
                    'up'       => ['DEFINE TABLE IF NOT EXISTS image SCHEMAFULL;', 'UPSERT migration:state SET x = 1;'],
                    'down'     => ['REMOVE TABLE IF EXISTS image;'],
                    'datetime' => '2025-01-01',
                    'name'     => 'initial_migration',
                ],
            ],
        ];

        Functions\when('apply_filters')->alias(
            fn(string $hook, $value = null) => $hook === 'get_surreal_graph_migrations' ? $this->registered : $value
        );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function getMigrationQueries(string $name, MigrationDirection $dir): array {
        $m = new \ReflectionMethod(MigrationsPage::class, 'get_migration_queries');
        return $m->invoke(null, $name, $dir);
    }

    public function testResolvesUpQueriesByName(): void {
        $queries = $this->getMigrationQueries('initial_migration', MigrationDirection::UP);

        $this->assertSame([
            'DEFINE TABLE IF NOT EXISTS image SCHEMAFULL;',
            'UPSERT migration:state SET x = 1;',
        ], $queries);
    }

    public function testResolvesDownQueriesByName(): void {
        $queries = $this->getMigrationQueries('initial_migration', MigrationDirection::DOWN);

        $this->assertSame(['REMOVE TABLE IF EXISTS image;'], $queries);
    }

    public function testReturnsEmptyForUnknownMigration(): void {
        $this->assertSame([], $this->getMigrationQueries('does_not_exist', MigrationDirection::UP));
    }
}
