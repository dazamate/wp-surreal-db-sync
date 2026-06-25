<?php

namespace Dazamate\SurrealGraphSync\Tests\Integration;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Dazamate\SurrealGraphSync\Service\TermSyncService;
use Dazamate\SurrealGraphSync\Data\MappedData;
use Dazamate\SurrealGraphSync\Data\RelationData;
use Dazamate\SurrealGraphSync\Field\StringField;
use Dazamate\SurrealGraphSync\Field\NumberField;

/**
 * Drives TermSyncService::sync_term end-to-end against a live SurrealDB with
 * WordPress mocked. Confirms term records land in their mapped table, the
 * Surreal id is written to term meta, and a child_of hierarchy edge to an
 * already-synced parent commits in the same atomic transaction.
 */
class TermSyncIntegrationTest extends IntegrationTestCase {
    /** @var array<string,mixed> captured term_meta writes */
    private array $meta = [];

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        $this->meta = [];

        Functions\when('apply_filters')->alias(fn(string $hook, $value = null) => $hook === 'get_surreal_db_conn' ? $this->db : $value);
        Functions\when('delete_term_meta')->justReturn(true);
        Functions\when('get_term_meta')->justReturn(false);
        Functions\when('update_term_meta')->alias(function ($id, $key, $value) {
            $this->meta["term:{$id}:{$key}"] = $value;
            return true;
        });
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function term(int $id): \WP_Term {
        $t = new \WP_Term();
        $t->term_id = $id;
        $t->taxonomy = 'category';
        return $t;
    }

    private function makeRecord(string $table, int $localId): string {
        $res = $this->db->query("UPSERT {$table} CONTENT { local_id: {$localId} } WHERE local_id = {$localId} RETURN id;");
        return $res[0][0]['id']->toString();
    }

    public function testSyncTermPersistsRecordAndWritesMeta(): void {
        $mapped = (new MappedData())
            ->set('term_id', new NumberField(7))
            ->set('name', new StringField('News'))
            ->set('taxonomy', new StringField('category'));

        TermSyncService::sync_term($this->term(7), 'category', $mapped, []);

        $this->assertArrayHasKey('term:7:surreal_id', $this->meta);
        $this->assertStringStartsWith('category:', $this->meta['term:7:surreal_id']);

        $rows = $this->db->query('SELECT * FROM category WHERE term_id = 7;')[0];
        $this->assertCount(1, $rows);
        $this->assertSame('News', $rows[0]['name']);
    }

    public function testSyncTermCommitsChildOfEdgeToSyncedParent(): void {
        $parent = $this->makeRecord('category', 99);

        $relations = [
            // Mirrors what TermMapper::map_hierarchy produces: self -> child_of -> parent.
            new RelationData(7, $parent, 'child_of', null, true),
        ];

        $mapped = (new MappedData())
            ->set('term_id', new NumberField(7))
            ->set('name', new StringField('Sub'));

        TermSyncService::sync_term($this->term(7), 'category', $mapped, $relations);

        $childId = $this->meta['term:7:surreal_id'] ?? null;
        $this->assertNotNull($childId);

        $edges = $this->db->query('SELECT * FROM child_of;')[0];
        $this->assertCount(1, $edges);
        $this->assertSame($childId, $edges[0]['in']->toString());
        $this->assertSame($parent, $edges[0]['out']->toString());
    }
}
