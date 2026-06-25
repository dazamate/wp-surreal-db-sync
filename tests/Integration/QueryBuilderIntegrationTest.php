<?php

namespace Dazamate\SurrealGraphSync\Tests\Integration;

use Dazamate\SurrealGraphSync\Query\QueryBuilder;
use Dazamate\SurrealGraphSync\Data\MappedData;
use Dazamate\SurrealGraphSync\Field\StringField;
use Dazamate\SurrealGraphSync\Field\NumberField;
use Dazamate\SurrealGraphSync\Field\ArrayField;
use Surreal\Cbor\Types\Record\RecordId;

/**
 * Runs QueryBuilder-generated SurrealQL against a real SurrealDB and asserts the
 * data actually lands. This proves the generated query strings are valid and
 * behave as intended end-to-end — not just that they string-match.
 */
class QueryBuilderIntegrationTest extends IntegrationTestCase {
    /** Create a record directly and return its Surreal id string. */
    private function makeRecord(string $table, int $localId): string {
        $res = $this->db->query("UPSERT {$table} CONTENT { local_id: {$localId} } WHERE local_id = {$localId} RETURN id;");
        return $res[0][0]['id']->toString();
    }

    public function testUpsertObjectLandsAllFieldTypes(): void {
        $data = (new MappedData())
            ->set('post_id', new NumberField(1))
            ->set('title', new StringField("O'Brien")) // exercises quote escaping
            ->set('tags', new ArrayField(['a', 'b'], 'array<string>'));

        $q = sprintf('UPSERT post CONTENT %s WHERE post_id = 1 RETURN id;', QueryBuilder::build_object_str($data));
        $res = $this->db->query($q);

        $this->assertInstanceOf(RecordId::class, $res[0][0]['id']);

        $rows = $this->db->query('SELECT * FROM post WHERE post_id = 1;')[0];
        $this->assertCount(1, $rows);
        $this->assertSame("O'Brien", $rows[0]['title']);
        $this->assertSame(1, $rows[0]['post_id']);
        $this->assertSame(['a', 'b'], $rows[0]['tags']);
    }

    public function testNonUniqueRelateCreatesDuplicateEdges(): void {
        $from = $this->makeRecord('post', 1);
        $to   = $this->makeRecord('person', 2);

        $q = QueryBuilder::build_relate_query($from, $to, 'wrote', new MappedData(), false);
        $this->db->query($q);
        $this->db->query($q);

        $edges = $this->db->query('SELECT * FROM wrote;')[0];
        $this->assertCount(2, $edges, 'non-unique relate should allow duplicate edges');
    }

    public function testUniqueRelateIsIdempotentAndUpdatesData(): void {
        $from = $this->makeRecord('post', 1);
        $to   = $this->makeRecord('person', 2);

        $this->db->query(QueryBuilder::build_relate_query(
            $from, $to, 'wrote', (new MappedData())->set('role', new StringField('author')), true
        ));
        $this->db->query(QueryBuilder::build_relate_query(
            $from, $to, 'wrote', (new MappedData())->set('role', new StringField('editor')), true
        ));

        $edges = $this->db->query('SELECT * FROM wrote;')[0];
        $this->assertCount(1, $edges, 'unique relate should not create a second edge');
        $this->assertSame('editor', $edges[0]['role'], 'unique relate should update the existing edge data');
    }

    public function testSyncTransactionCommitsRecordAndEdgesAtomically(): void {
        $person = $this->makeRecord('person', 9);

        // Relation from the record being saved ($rec) to an existing person.
        $relation = QueryBuilder::build_relate_query(
            '$rec', $person, 'wrote', (new MappedData())->set('n', new NumberField(1)), true
        );

        $txn = QueryBuilder::build_sync_transaction(
            'post',
            'post_id = 5',
            (new MappedData())->set('post_id', new NumberField(5))->set('title', new StringField('Hello')),
            [$relation]
        );

        $res = $this->db->queryRaw($txn);

        foreach ($res as $statement) {
            $this->assertSame('OK', $statement['status'] ?? 'OK', 'no statement should error');
        }
        $this->assertInstanceOf(RecordId::class, $res[0]['result'], 'transaction returns the record id');

        $posts = $this->db->query('SELECT * FROM post WHERE post_id = 5;')[0];
        $this->assertCount(1, $posts);

        $edges = $this->db->query('SELECT * FROM wrote;')[0];
        $this->assertCount(1, $edges);
        $this->assertSame(1, $edges[0]['n']);
    }

    public function testSyncTransactionRollsBackWhenAnEdgeFails(): void {
        // Reference an undefined variable so the RELATE errors mid-transaction.
        $brokenRelation = QueryBuilder::build_relate_query(
            '$rec', '$undefined_target', 'wrote', new MappedData(), false
        );

        $txn = QueryBuilder::build_sync_transaction(
            'post',
            'post_id = 6',
            (new MappedData())->set('post_id', new NumberField(6)),
            [$brokenRelation]
        );

        $res = $this->db->queryRaw($txn);

        $statuses = array_map(static fn($s) => $s['status'] ?? 'OK', $res);
        $this->assertContains('ERR', $statuses, 'a broken edge should fail the transaction');

        $posts = $this->db->query('SELECT * FROM post WHERE post_id = 6;')[0];
        $this->assertCount(0, $posts, 'the record upsert must roll back with the failed edge');
    }
}
