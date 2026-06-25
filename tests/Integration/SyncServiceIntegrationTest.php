<?php

namespace Dazamate\SurrealGraphSync\Tests\Integration;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Dazamate\SurrealGraphSync\Service\PostSyncService;
use Dazamate\SurrealGraphSync\Service\UserSyncService;
use Dazamate\SurrealGraphSync\Data\MappedData;
use Dazamate\SurrealGraphSync\Data\RelationData;
use Dazamate\SurrealGraphSync\Field\StringField;
use Dazamate\SurrealGraphSync\Field\NumberField;

/**
 * Drives the real sync entrypoints (sync_post / sync_user) end-to-end against a
 * live SurrealDB, with WordPress functions mocked via Brain\Monkey. Asserts that
 * the record + its edges actually land, that the Surreal id is written back to
 * meta, and that a relation that can't be resolved aborts the whole sync without
 * persisting anything.
 */
class SyncServiceIntegrationTest extends IntegrationTestCase {
    /** @var array<string,mixed> captured *_meta writes */
    private array $meta = [];

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        $this->meta = [];

        // Sync code pulls the connection from this filter.
        $db = $this->db;
        Functions\when('apply_filters')->alias(function (string $hook, $value = null) use ($db) {
            return $hook === 'get_surreal_db_conn' ? $db : $value;
        });

        // Error managers + the success path read/write/clear meta.
        Functions\when('delete_post_meta')->justReturn(true);
        Functions\when('delete_user_meta')->justReturn(true);
        Functions\when('get_post_meta')->justReturn(false);
        Functions\when('get_user_meta')->justReturn(false);
        Functions\when('update_post_meta')->alias(function ($id, $key, $value) {
            $this->meta["post:{$id}:{$key}"] = $value;
            return true;
        });
        Functions\when('update_user_meta')->alias(function ($id, $key, $value) {
            $this->meta["user:{$id}:{$key}"] = $value;
            return true;
        });
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function makeRecord(string $table, int $localId): string {
        $res = $this->db->query("UPSERT {$table} CONTENT { local_id: {$localId} } WHERE local_id = {$localId} RETURN id;");
        return $res[0][0]['id']->toString();
    }

    public function testSyncPostPersistsRecordAndSelfEdgeAndWritesMeta(): void {
        $person = $this->makeRecord('person', 1);

        // Relation FROM the post being saved (its WP id) TO an existing person.
        $relations = [
            new RelationData(123, $person, 'wrote', (new MappedData())->set('role', new StringField('author')), true),
        ];
        Functions\when('apply_filters')->alias(function (string $hook, $value = null) use ($relations) {
            return match ($hook) {
                'get_surreal_db_conn'        => $this->db,
                'surreal_graph_map_related'  => $relations,
                default                      => $value,
            };
        });

        $post = new \WP_Post();
        $post->ID = 123;
        $post->post_type = 'post';

        $mapped = (new MappedData())
            ->set('post_id', new NumberField(123))
            ->set('title', new StringField('Atomic'));

        PostSyncService::sync_post($post, 'post', $mapped);

        // Surreal id written back to post meta.
        $this->assertArrayHasKey('post:123:surreal_id', $this->meta);
        $this->assertStringStartsWith('post:', $this->meta['post:123:surreal_id']);

        // Record landed.
        $posts = $this->db->query('SELECT * FROM post WHERE post_id = 123;')[0];
        $this->assertCount(1, $posts);
        $this->assertSame('Atomic', $posts[0]['title']);

        // Self-referencing edge landed, pointing out of the new post record.
        $edges = $this->db->query('SELECT * FROM wrote;')[0];
        $this->assertCount(1, $edges);
        $this->assertSame('author', $edges[0]['role']);
        $this->assertSame($this->meta['post:123:surreal_id'], $edges[0]['in']->toString());
    }

    public function testSyncPostAbortsAndPersistsNothingWhenRelationUnresolvable(): void {
        // Relation to an unknown WP id (999) that has no surreal_id meta — can't
        // be resolved, so the whole sync must abort before writing anything.
        $relations = [
            new RelationData(123, 999, 'wrote', null, true),
        ];
        Functions\when('apply_filters')->alias(function (string $hook, $value = null) use ($relations) {
            return match ($hook) {
                'get_surreal_db_conn'        => $this->db,
                'surreal_graph_map_related'  => $relations,
                default                      => $value,
            };
        });

        $post = new \WP_Post();
        $post->ID = 123;
        $post->post_type = 'post';

        PostSyncService::sync_post($post, 'post', (new MappedData())->set('post_id', new NumberField(123)));

        // No surreal id written, and the record was never created.
        $this->assertArrayNotHasKey('post:123:surreal_id', $this->meta);
        $posts = $this->db->query('SELECT * FROM post WHERE post_id = 123;')[0];
        $this->assertCount(0, $posts);
    }

    public function testSyncUserPersistsRecordAndWritesMeta(): void {
        $org = $this->makeRecord('org', 1);

        $user = new \WP_User();
        $user->ID = 55;

        $mapped = (new MappedData())
            ->set('user_id', new NumberField(55))
            ->set('username', new StringField('dale'));

        $relations = [
            new RelationData(55, $org, 'member_of', (new MappedData())->set('since', new NumberField(2020)), true),
        ];

        UserSyncService::sync_user($user, 'person', $mapped, $relations);

        $this->assertArrayHasKey('user:55:surreal_id', $this->meta);
        $this->assertStringStartsWith('person:', $this->meta['user:55:surreal_id']);

        $people = $this->db->query('SELECT * FROM person WHERE user_id = 55;')[0];
        $this->assertCount(1, $people);
        $this->assertSame('dale', $people[0]['username']);

        $edges = $this->db->query('SELECT * FROM member_of;')[0];
        $this->assertCount(1, $edges);
        $this->assertSame(2020, $edges[0]['since']);
    }
}
