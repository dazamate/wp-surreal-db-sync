<?php

namespace Dazamate\SurrealGraphSync\Tests\Dto;

use PHPUnit\Framework\TestCase;
use Dazamate\SurrealGraphSync\Data\MappedData;
use Dazamate\SurrealGraphSync\Field\StringField;
use Dazamate\SurrealGraphSync\Field\NumberField;
use Dazamate\SurrealGraphSync\Field\DateTimeField;
use Dazamate\SurrealGraphSync\Field\RecordField;
use Dazamate\SurrealGraphSync\Field\ArrayField;
use Dazamate\SurrealGraphSync\Field\ObjectField;
use Dazamate\SurrealGraphSync\Field\RawField;
use Dazamate\SurrealGraphSync\Dto\Reference\SurrealId;
use Dazamate\SurrealGraphSync\Dto\Reference\WordPressId;
use Dazamate\SurrealGraphSync\Utils\FieldValidator;
use Dazamate\SurrealGraphSync\Utils\FieldRenderer;
use Dazamate\SurrealGraphSync\Enum\QueryType;

class MappedDataTest extends TestCase {

    public function testEmptyDataIsValid(): void {
        $errors = [];
        $this->assertTrue(FieldValidator::validate_mapped(new MappedData(), $errors));
        $this->assertEmpty($errors);
    }

    public function testRequiredKeyMissingFails(): void {
        $data   = (new MappedData())->set('title', new StringField('hi'));
        $errors = [];

        $this->assertFalse(FieldValidator::validate_mapped($data, $errors, ['title', 'post_id']));
        $this->assertStringContainsString('[post_id]: required field is missing', $errors[0]);
    }

    public function testStringFieldSuccess(): void {
        $errors = [];
        $data   = (new MappedData())->set('title', new StringField('Some Title'));

        $this->assertTrue(FieldValidator::validate_mapped($data, $errors));
        $this->assertEmpty($errors);
    }

    public function testNumberFieldSuccess(): void {
        $errors = [];
        $data   = (new MappedData())->set('quantity', new NumberField(123));

        $this->assertTrue(FieldValidator::validate_mapped($data, $errors));
        $this->assertEmpty($errors);
    }

    public function testDateTimeFieldSuccessWithIso(): void {
        $errors = [];
        $data   = (new MappedData())->set('published', new DateTimeField('2025-01-26T18:29:00+00:00'));

        $this->assertTrue(FieldValidator::validate_mapped($data, $errors));
        $this->assertEmpty($errors);
    }

    public function testDateTimeFieldSuccessWithTimestampStringAndInt(): void {
        $errors = [];
        $data   = (new MappedData())
            ->set('a', new DateTimeField('345435435345'))
            ->set('b', new DateTimeField(345435435345));

        $this->assertTrue(FieldValidator::validate_mapped($data, $errors));
        $this->assertEmpty($errors);
    }

    public function testRecordFieldSuccessWithPostIdAndRecordId(): void {
        $errors = [];
        $data   = (new MappedData())
            ->set('author', new RecordField(new WordPressId(123, QueryType::POST)))
            ->set('editor', new RecordField(new SurrealId('person:35r4')));

        $this->assertTrue(FieldValidator::validate_mapped($data, $errors));
        $this->assertEmpty($errors);
    }

    public function testArrayFieldSuccessWithScalarsAndFields(): void {
        $errors = [];
        $data   = (new MappedData())->set('tags', new ArrayField([
            'recipes',
            'summer',
            new StringField('nested typed item'),
        ], 'array<string>'));

        $this->assertTrue(FieldValidator::validate_mapped($data, $errors));
        $this->assertEmpty($errors);
    }

    public function testObjectFieldSuccess(): void {
        $errors = [];
        $data   = (new MappedData())->set('metadata', new ObjectField(
            (new MappedData())
                ->set('creator', new StringField('John Doe'))
                ->set('count', new NumberField(42))
        ));

        $this->assertTrue(FieldValidator::validate_mapped($data, $errors));
        $this->assertEmpty($errors);
    }

    public function testRawFieldValidatesWithTypeAndRenders(): void {
        $errors = [];
        $data   = (new MappedData())->set('span', new RawField('1w', 'duration'));

        $this->assertTrue(FieldValidator::validate_mapped($data, $errors));
        $this->assertSame('{span: <duration>1w}', FieldRenderer::to_object_string($data));
    }
}
