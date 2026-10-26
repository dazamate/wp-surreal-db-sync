<?php

namespace Dazamate\SurrealGraphSync\Tests\Query;

use PHPUnit\Framework\TestCase;
use Dazamate\SurrealGraphSync\Query\QueryBuilder;
use Dazamate\SurrealGraphSync\Data\MappedData;
use Dazamate\SurrealGraphSync\Field\NumberField;

class QueryBuilderOwnedRelationsTest extends TestCase {
    public function testOwnedRelationsAreClearedAfterTheUpsertAndBeforeRelating(): void {
        $q = QueryBuilder::build_sync_transaction(
            'recipe',
            'post_id = 7',
            (new MappedData())->set('post_id', new NumberField(7)),
            ['RELATE ($rec)->uses_ingredient->(ingredient:a) CONTENT {} RETURN id;'],
            ['uses_ingredient', 'uses_ingredient']
        );

        $upsert = strpos($q, 'LET $rec = $rec_res[0].id;');
        $define = strpos($q, 'DEFINE TABLE IF NOT EXISTS uses_ingredient TYPE ANY SCHEMALESS;');
        $delete = strpos($q, 'DELETE uses_ingredient WHERE in = $rec;');
        $relate = strpos($q, 'RELATE ($rec)->uses_ingredient');

        $this->assertNotFalse($define);
        $this->assertNotFalse($delete);
        $this->assertTrue($upsert < $define && $define < $delete && $delete < $relate);
        $this->assertSame(1, substr_count($q, 'DELETE uses_ingredient'), 'duplicate names are cleared once');
    }

    public function testNoOwnedRelationsMeansNoDeletes(): void {
        $q = QueryBuilder::build_sync_transaction('recipe', 'post_id = 7', new MappedData(), []);

        $this->assertStringNotContainsString('DELETE', $q);
    }

    public function testOwnedRelationsAreClearedEvenWithNoRelationsToCreate(): void {
        // A recipe saved with every line removed must lose all of its edges.
        $q = QueryBuilder::build_sync_transaction('recipe', 'post_id = 7', new MappedData(), [], ['uses_ingredient']);

        $this->assertStringContainsString('DELETE uses_ingredient WHERE in = $rec;', $q);
    }

    public function testTableNameValidation(): void {
        $this->assertTrue(QueryBuilder::is_valid_table_name('uses_ingredient'));
        $this->assertTrue(QueryBuilder::is_valid_table_name('has_category2'));
        $this->assertFalse(QueryBuilder::is_valid_table_name('uses_ingredient; DELETE recipe'));
        $this->assertFalse(QueryBuilder::is_valid_table_name(''));
        $this->assertFalse(QueryBuilder::is_valid_table_name('2fast'));
    }
}
