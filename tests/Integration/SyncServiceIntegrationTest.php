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
 * meta, that a relation that can't be resolved is skipped with a warning while the
 * record still saves, and that owned relations are rebuilt on every save.
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
        $posts = $this->selectRows('SELECT * FROM post WHERE post_id = 123;');
        $this->assertCount(1, $posts);
        $this->assertSame('Atomic', $posts[0]['title']);

        // Self-referencing edge landed, pointing out of the new post record.
        $edges = $this->selectRows('SELECT * FROM wrote;');
        $this->assertCount(1, $edges);
        $this->assertSame('author', $edges[0]['role']);
        $this->assertSame($this->meta['post:123:surreal_id'], $edges[0]['in']->toString());
    }

    public function testSyncPostSkipsAnUnresolvableRelationAndStillSaves(): void {
        // Relation to an unknown WP id (999) that has no surreal_id meta can't be
        // resolved. That edge is skipped with a warning; the record and the other
        // edge still land.
        $person = $this->makeRecord('person', 1);
        $relations = [
            new RelationData(123, 999, 'wrote', null, true),
            new RelationData(123, $person, 'wrote', null, true),
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

        $this->assertArrayHasKey('post:123:surreal_id', $this->meta);
        $this->assertCount(1, $this->selectRows('SELECT * FROM post WHERE post_id = 123;'));
        $this->assertCount(1, $this->selectRows('SELECT * FROM wrote;'));

        $warnings = $this->meta['post:123:surreal_sync_error'] ?? [];
        $this->assertCount(1, $warnings);
        $this->assertStringStartsWith('Surreal sync warning:', $warnings[0]);
    }

    public function testOwnedRelationsAreRebuiltOnEverySave(): void {
        $a = $this->makeRecord('ingredient', 1);
        $b = $this->makeRecord('ingredient', 2);
        $line = fn(string $to, int $pos) => new RelationData(
            123, $to, 'uses_ingredient', (new MappedData())->set('position', new NumberField($pos)), false
        );

        $save = function (array $relations): void {
            Functions\when('apply_filters')->alias(function (string $hook, $value = null) use ($relations) {
                return match ($hook) {
                    'get_surreal_db_conn'          => $this->db,
                    'surreal_graph_map_related'    => $relations,
                    'surreal_graph_owned_relations' => ['uses_ingredient'],
                    default                        => $value,
                };
            });

            $post = new \WP_Post();
            $post->ID = 123;
            $post->post_type = 'recipe';
            PostSyncService::sync_post($post, 'recipe', (new MappedData())->set('post_id', new NumberField(123)));
        };

        // Three lines, two of them the same ingredient: non-unique edges keep both.
        $save([$line($a, 0), $line($b, 1), $line($a, 2)]);
        $this->assertCount(3, $this->selectRows('SELECT * FROM uses_ingredient;'));

        // A line is removed and the rest are reordered: only the new set remains.
        $save([$line($b, 0)]);
        $edges = $this->selectRows('SELECT * FROM uses_ingredient;');
        $this->assertCount(1, $edges);
        $this->assertSame($b, $edges[0]['out']->toString());
        $this->assertSame(0, $edges[0]['position']);

        // Every line removed: no edges left.
        $save([]);
        $this->assertCount(0, $this->selectRows('SELECT * FROM uses_ingredient;'));

        // The recipe record itself survived every save, once.
        $this->assertCount(1, $this->selectRows('SELECT * FROM recipe WHERE post_id = 123;'));
    }

    public function testSyncUserStillAbortsWhenRelationUnresolvable(): void {
        // Only post syncs opt in to skip-with-warning; a user sync must not drop a link silently.
        $user = new \WP_User();
        $user->ID = 56;

        UserSyncService::sync_user($user, 'person', (new MappedData())->set('user_id', new NumberField(56)), [
            new RelationData(56, 999, 'member_of', null, true),
        ]);

        $this->assertArrayNotHasKey('user:56:surreal_id', $this->meta);
        $this->assertCount(0, $this->selectRows('SELECT * FROM person WHERE user_id = 56;'));
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

        $people = $this->selectRows('SELECT * FROM person WHERE user_id = 55;');
        $this->assertCount(1, $people);
        $this->assertSame('dale', $people[0]['username']);

        $edges = $this->selectRows('SELECT * FROM member_of;');
        $this->assertCount(1, $edges);
        $this->assertSame(2020, $edges[0]['since']);
    }
}
