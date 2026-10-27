<?php

namespace Dazamate\SurrealGraphSync\Tests\Manager;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Dazamate\SurrealGraphSync\Manager\PostSyncManager;
use Dazamate\SurrealGraphSync\Data\MappedData;

/**
 * Only published posts belong in SurrealDB: records carry no status, so anything synced is
 * live. These tests pin down which saves sync, which delete the record, and which do nothing.
 */
class PostSyncManagerTest extends TestCase {
    /** @var list<string> record ids passed to surreal_delete_record */
    private array $deleted = [];

    /** @var list<int> post ids passed to surreal_sync_post */
    private array $synced = [];

    /** @var list<array{string, mixed}> table and mapped data passed to surreal_sync_post */
    private array $payloads = [];

    /** @var list<string> statuses the surreal_sync_post_statuses filter returns */
    private array $statuses = ['publish'];

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        $this->deleted  = [];
        $this->synced   = [];
        $this->payloads = [];
        $this->statuses = ['publish'];

        Functions\when('wp_is_post_revision')->justReturn(false);
        Functions\when('wp_is_post_autosave')->justReturn(false);
        Functions\when('get_post_thumbnail_id')->justReturn(0);
        Functions\when('delete_post_meta')->justReturn(true);
        Functions\when('get_post_meta')->justReturn('recipe:abc');
        Functions\when('apply_filters')->alias(function (string $hook, $value = null) {
            return match ($hook) {
                'surreal_sync_post_statuses'  => $this->statuses,
                'surreal_map_post_table_name' => 'recipe',
                default                       => $value,
            };
        });

        Actions\expectDone('surreal_delete_record')->zeroOrMoreTimes()->whenHappen(function (string $id) {
            $this->deleted[] = $id;
        });
        Actions\expectDone('surreal_sync_post')->zeroOrMoreTimes()->whenHappen(function (\WP_Post $post, $table, $data) {
            $this->synced[]   = $post->ID;
            $this->payloads[] = [$table, $data];
        });
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function post(string $status): \WP_Post {
        $post = new \WP_Post();
        $post->ID = 7;
        $post->post_type = 'recipe';
        $post->post_title = 'Crispy garlic chicken';
        $post->post_status = $status;
        $post->post_content = '';
        $post->post_date_gmt = '2026-10-26 00:00:00';
        $post->post_modified_gmt = '2026-10-26 00:00:00';

        Functions\when('get_post')->justReturn($post);

        return $post;
    }

    public function testPublishedPostSyncs(): void {
        PostSyncManager::on_post_save(7, $this->post('publish'), true);

        $this->assertSame([7], $this->synced);
        $this->assertSame([], $this->deleted);
    }

    /** @return array<string, array{string}> */
    public static function unpublishedStatuses(): array {
        return [
            'draft'      => ['draft'],
            'pending'    => ['pending'],
            'private'    => ['private'],
            'scheduled'  => ['future'],
            'trash'      => ['trash'],
            'auto-draft' => ['auto-draft'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unpublishedStatuses')]
    public function testUnpublishedPostDeletesItsRecordAndDoesNotSync(string $status): void {
        PostSyncManager::on_post_save(7, $this->post($status), true);

        $this->assertSame([], $this->synced);
        $this->assertSame(['recipe:abc'], $this->deleted);
    }

    public function testRevisionsAndAutosavesAreIgnored(): void {
        Functions\when('wp_is_post_revision')->justReturn(true);
        PostSyncManager::on_post_save(7, $this->post('draft'), true);

        Functions\when('wp_is_post_revision')->justReturn(false);
        Functions\when('wp_is_post_autosave')->justReturn(true);
        PostSyncManager::on_post_save(7, $this->post('publish'), true);

        $this->assertSame([], $this->synced);
        $this->assertSame([], $this->deleted);
    }

    public function testSyncPostRefusesUnpublishedPosts(): void {
        // The CLI calls sync_post directly, so the status rule lives there too.
        $this->assertFalse(PostSyncManager::sync_post(7, $this->post('draft')));
        $this->assertFalse(PostSyncManager::sync_post(7, $this->post('private')));
        $this->assertSame([], $this->synced);
    }

    public function testStatusFilterCanWidenTheSyncableStatuses(): void {
        $this->statuses = ['publish', 'private'];

        $this->assertTrue(PostSyncManager::sync_post(7, $this->post('private')));
        $this->assertSame([7], $this->synced);
    }

    public function testUntrashIsNotHooked(): void {
        // untrash_post fires while the post is still `trash`; restoring re-saves it with its new
        // status and on_post_save decides then.
        $this->assertFalse(method_exists(PostSyncManager::class, 'on_post_untrash'));
    }

    public function testMappedDataReachesTheSyncAction(): void {
        PostSyncManager::sync_post(7, $this->post('publish'));

        $this->assertSame('recipe', $this->payloads[0][0]);
        $this->assertInstanceOf(MappedData::class, $this->payloads[0][1]);
    }
}
