<?php

namespace Dazamate\SurrealGraphSync\Tests\Mapper;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Dazamate\SurrealGraphSync\Mapper\Term\TermMapper;
use Dazamate\SurrealGraphSync\Data\MappedData;
use Dazamate\SurrealGraphSync\Utils\FieldRenderer;

class TermMapperTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        // Filters pass their value through by default.
        Functions\when('apply_filters')->alias(fn(string $hook, $value = null) => $value);
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function makeTerm(int $id, int $parent = 0): \WP_Term {
        $term = new \WP_Term();
        $term->term_id = $id;
        $term->parent = $parent;
        $term->taxonomy = 'category';
        $term->name = 'News';
        $term->slug = 'news';
        $term->description = 'Latest';
        $term->count = 3;
        return $term;
    }

    public function testMapSeedsGenericFields(): void {
        Functions\when('get_term')->alias(fn($id) => $this->makeTerm($id));

        $data = TermMapper::map(new MappedData(), 7);

        $this->assertSame("<string>'News'", FieldRenderer::to_property_clause($data->get('name')));
        $this->assertSame("<string>'news'", FieldRenderer::to_property_clause($data->get('slug')));
        $this->assertSame('<number>7', FieldRenderer::to_property_clause($data->get('term_id')));
        $this->assertSame("<string>'category'", FieldRenderer::to_property_clause($data->get('taxonomy')));
        $this->assertSame('<number>3', FieldRenderer::to_property_clause($data->get('count')));
    }

    public function testHierarchyAddsChildOfEdgeWhenParentSynced(): void {
        Functions\when('get_term_meta')->justReturn('category:parentabc');

        $relations = TermMapper::map_hierarchy([], $this->makeTerm(10, 5));

        $this->assertCount(1, $relations);
        $this->assertSame('child_of', $relations[0]->relation_table);
        $this->assertSame(10, $relations[0]->from_record);          // self -> $rec at render
        $this->assertSame('category:parentabc', $relations[0]->to_record);
        $this->assertTrue($relations[0]->unique);
    }

    public function testHierarchySkippedWhenNoParent(): void {
        $this->assertSame([], TermMapper::map_hierarchy([], $this->makeTerm(10, 0)));
    }

    public function testHierarchySkippedWhenParentNotSyncedYet(): void {
        Functions\when('get_term_meta')->justReturn(''); // parent has no surreal id

        $this->assertCount(0, TermMapper::map_hierarchy([], $this->makeTerm(10, 5)));
    }
}
