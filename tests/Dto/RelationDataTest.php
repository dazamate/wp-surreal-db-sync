<?php

namespace Dazamate\SurrealGraphSync\Tests\Dto;

use PHPUnit\Framework\TestCase;
use Dazamate\SurrealGraphSync\Data\RelationData;
use Dazamate\SurrealGraphSync\Data\MappedData;
use Dazamate\SurrealGraphSync\Field\StringField;
use Dazamate\SurrealGraphSync\Field\NumberField;
use Dazamate\SurrealGraphSync\Field\Exception\InvalidFieldException;
use Dazamate\SurrealGraphSync\Utils\RelationDataHelper;

class RelationDataTest extends TestCase {

    public function testMissingFromRecordFails(): void {
        $errors   = [];
        $relation = new RelationData('', 'some_table:2', 'edge_table');

        $this->assertFalse(RelationDataHelper::validate($relation, $errors));
        $this->assertStringContainsString('Invalid or missing "from_record"', implode(' ', $errors));
    }

    public function testInvalidFromRecordFails(): void {
        $errors   = [];
        $relation = new RelationData('invalid_format', 'some_table:2', 'edge_table');

        $this->assertFalse(RelationDataHelper::validate($relation, $errors));
        $this->assertStringContainsString('Invalid or missing "from_record"', implode(' ', $errors));
    }

    public function testMissingToRecordFails(): void {
        $errors   = [];
        $relation = new RelationData('some_table:1', '', 'edge_table');

        $this->assertFalse(RelationDataHelper::validate($relation, $errors));
        $this->assertStringContainsString('Invalid or missing "to_record"', implode(' ', $errors));
    }

    public function testMissingRelationTableFails(): void {
        $errors   = [];
        $relation = new RelationData('some_table:1', 'some_table:2', '');

        $this->assertFalse(RelationDataHelper::validate($relation, $errors));
        $this->assertStringContainsString('Invalid or missing "relation_table"', implode(' ', $errors));
    }

    public function testInvalidNestedDataThrowsAtConstruction(): void {
        // A wrong-typed field is rejected when the nested MappedData is built,
        // before the relation is ever validated.
        $this->expectException(InvalidFieldException::class);

        (new MappedData())->set('custom_field', new StringField(123)); // not a string
    }

    public function testValidMinimalSucceeds(): void {
        $errors   = [];
        $relation = new RelationData('some_table:1', 'some_table:2', 'edge_table');

        $this->assertTrue(RelationDataHelper::validate($relation, $errors));
        $this->assertEmpty($errors);
    }

    public function testValidFullSucceeds(): void {
        $errors   = [];
        $relation = new RelationData(
            'some_table:1',
            'some_table:2',
            'edge_table',
            (new MappedData())
                ->set('discount', new NumberField(0.2))
                ->set('label', new StringField('Hello World')),
            true
        );

        $this->assertTrue(RelationDataHelper::validate($relation, $errors));
        $this->assertEmpty($errors);
    }

    public function testNumericEndpointsAreAllowed(): void {
        $errors   = [];
        $relation = new RelationData(123, 456, 'edge_table');

        $this->assertTrue(RelationDataHelper::validate($relation, $errors));
        $this->assertEmpty($errors);
    }
}
