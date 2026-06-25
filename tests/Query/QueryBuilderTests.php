<?php

namespace Dazamate\SurrealGraphSync\Tests\Query;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Dazamate\SurrealGraphSync\Query\QueryBuilder;
use Dazamate\SurrealGraphSync\Data\MappedData;
use Dazamate\SurrealGraphSync\Field\StringField;
use Dazamate\SurrealGraphSync\Field\NumberField;
use Dazamate\SurrealGraphSync\Field\DateTimeField;
use Dazamate\SurrealGraphSync\Field\RecordField;
use Dazamate\SurrealGraphSync\Field\ArrayField;
use Dazamate\SurrealGraphSync\Field\ObjectField;
use Dazamate\SurrealGraphSync\Dto\Reference\SurrealId;
use Dazamate\SurrealGraphSync\Dto\Reference\WordPressId;
use Dazamate\SurrealGraphSync\Enum\QueryType;

class QueryBuilderTests extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        Functions\when('get_post_meta')->alias(function ($post_id, $key = '', $single = false) {
            return match($post_id) {
                42 => "image:abfsdfd897sdf9",
                default => null
            };
        });
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function mapped(array $fields): MappedData
    {
        $data = new MappedData();

        foreach ($fields as $key => $field) {
            $data->set($key, $field);
        }

        return $data;
    }

    public function testShouldRenderEmptyObject()
    {
        $this->assertSame('{}', QueryBuilder::build_object_str(new MappedData()));
    }

    public function testShouldBuildObjectWithSimpleStringProperty()
    {
        $data = $this->mapped([
            'title' => new StringField('Hello World'),
        ]);

        $this->assertSame("{title: <string>'Hello World'}", QueryBuilder::build_object_str($data));
    }

    public function testShouldBuildObjectWithSimpleNumberProperty()
    {
        $data = $this->mapped([
            'post_id' => new NumberField(42),
        ]);

        $this->assertSame("{post_id: <number>42}", QueryBuilder::build_object_str($data));
    }

    public function testShouldBuildObjectWithFloatNumberProperty()
    {
        $data = $this->mapped([
            'discount' => new NumberField(0.2),
        ]);

        $this->assertSame("{discount: <number>0.2}", QueryBuilder::build_object_str($data));
    }

    public function testShouldBuildObjectWithDatetimeProperty()
    {
        $data = $this->mapped([
            'created_at' => new DateTimeField('2025-01-26T12:34:56Z'),
        ]);

        $this->assertSame("{created_at: <datetime>'2025-01-26T12:34:56Z'}", QueryBuilder::build_object_str($data));
    }

    public function testShouldBuildObjectWithDatetimeStr()
    {
        $data = $this->mapped([
            'created_at' => new DateTimeField('476244630'),
        ]);

        $this->assertSame("{created_at: <datetime>'1985-02-03T02:10:30+00:00'}", QueryBuilder::build_object_str($data));
    }

    public function testShouldBuildObjectWithDatetimeInt()
    {
        $data = $this->mapped([
            'created_at' => new DateTimeField(476244630),
        ]);

        $this->assertSame("{created_at: <datetime>'1985-02-03T02:10:30+00:00'}", QueryBuilder::build_object_str($data));
    }

    public function testShouldExtractSurrealRecordIDFromPostID()
    {
        $data = $this->mapped([
            'image' => new RecordField(new WordPressId(42, QueryType::POST)),
        ]);

        $result = QueryBuilder::build_object_str($data);

        $this->assertStringContainsString('image: <record<image>>image:abfsdfd897sdf9', $result, 'Should contain the image surreal record id');
    }

    public function testShouldUseSurrealRecordIDIfPassedDirectly()
    {
        $data = $this->mapped([
            'image' => new RecordField(new SurrealId('image:abfsdfd897sdf9')),
        ]);

        $result = QueryBuilder::build_object_str($data);

        $this->assertStringContainsString('image: <record<image>>image:abfsdfd897sdf9', $result, 'Should contain the image surreal record id');
    }

    public function testShouldBuildQueryWithNULLValueIfRecordIdNoExist()
    {
        $data = $this->mapped([
            'image' => new RecordField(new WordPressId(22, QueryType::POST)),
        ]);

        $result = QueryBuilder::build_object_str($data);

        $this->assertStringContainsString('image: NULL', $result, 'Should contain NULL as the record');
    }

    public function testShouldBuildObjectWithArrayOfStrings()
    {
        $data = $this->mapped([
            'categories' => new ArrayField(['Breakfast', 'Quick & Easy'], 'array<string>'),
        ]);

        $expected = "{categories: <array<string>>['Breakfast', 'Quick & Easy']}";

        $this->assertSame($expected, QueryBuilder::build_object_str($data));
    }

    public function testShouldHandleEmptyArrayValue()
    {
        $data = $this->mapped([
            'empty_list' => new ArrayField([], 'array'),
        ]);

        $this->assertSame("{empty_list: NULL}", QueryBuilder::build_object_str($data));
    }

    public function testShouldBuildObjectWithNestedObject()
    {
        $data = $this->mapped([
            'recipe_yield' => new ObjectField($this->mapped([
                'amount'       => new NumberField(4),
                'measure_unit' => new StringField('servings'),
            ])),
        ]);

        $expected = "{recipe_yield: <object>{amount: <number>4, measure_unit: <string>'servings'}}";

        $this->assertSame($expected, QueryBuilder::build_object_str($data));
    }

    public function testShouldBuildObjectWithNestedArraysOfObjects()
    {
        $data = $this->mapped([
            'method_steps' => new ArrayField([
                new ObjectField($this->mapped([
                    'name'        => new StringField('Step 1'),
                    'description' => new StringField('Do something...'),
                    'step_image'  => new RecordField(new WordPressId(999, QueryType::POST)),
                ])),
                new ObjectField($this->mapped([
                    'name'        => new StringField('Step 2'),
                    'description' => new StringField('Do something else...'),
                    'step_image'  => new RecordField(new SurrealId('')),
                ])),
            ], 'array'),
        ]);

        $result = QueryBuilder::build_object_str($data);

        $this->assertStringStartsWith('{method_steps: <array>[', $result);
        $this->assertStringEndsWith(']}', $result);

        $this->assertStringContainsString("name: <string>'Step 1'", $result);
        $this->assertStringContainsString("description: <string>'Do something...'", $result);
        $this->assertStringContainsString("name: <string>'Step 2'", $result);
        $this->assertStringContainsString("description: <string>'Do something else...'", $result);
    }

    public function testShouldHandleNullValues()
    {
        $data = $this->mapped([
            'someField'      => new StringField(''),
            'someOtherField' => new NumberField(null),
        ]);

        $this->assertSame("{someField: NULL, someOtherField: NULL}", QueryBuilder::build_object_str($data));
    }
}
