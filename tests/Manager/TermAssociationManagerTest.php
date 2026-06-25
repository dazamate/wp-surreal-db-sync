<?php

namespace Dazamate\SurrealGraphSync\Tests\Manager;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Dazamate\SurrealGraphSync\Manager\TermAssociationManager;

class TermAssociationManagerTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('get_object_taxonomies')->justReturn(['category']);
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

    public function testNoOptInLeavesRelationsUntouched(): void {
        Functions\when('apply_filters')->alias(fn(string $hook, $value = null) => $hook === 'surreal_graph_auto_term_relations' ? [] : $value);

        $post = new \WP_Post();
        $post->ID = 1;
        $post->post_type = 'post';

        $this->assertSame([], TermAssociationManager::append_term_relations([], $post));
    }

    public function testAppendsEdgesForSyncedTermsOnly(): void {
        Functions\when('apply_filters')->alias(function (string $hook, $value = null) {
            return match ($hook) {
                'surreal_graph_auto_term_relations' => ['category'],
                'surreal_graph_term_relation_name'  => 'has_' . func_get_args()[2], // taxonomy is 3rd arg
                default                             => $value,
            };
        });

        Functions\when('wp_get_object_terms')->justReturn([$this->term(11), $this->term(22)]);

        // term 11 is synced, term 22 is not.
        Functions\when('get_term_meta')->alias(fn($term_id) => $term_id === 11 ? 'category:synced11' : '');

        $post = new \WP_Post();
        $post->ID = 5;
        $post->post_type = 'post';

        $relations = TermAssociationManager::append_term_relations([], $post);

        $this->assertCount(1, $relations, 'only the synced term should be wired (best-effort)');
        $this->assertSame(5, $relations[0]->from_record);          // post self -> $rec
        $this->assertSame('category:synced11', $relations[0]->to_record);
        $this->assertSame('has_category', $relations[0]->relation_table);
    }
}
