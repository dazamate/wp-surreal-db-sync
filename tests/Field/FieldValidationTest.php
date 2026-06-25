<?php

namespace Dazamate\SurrealGraphSync\Tests\Field;

use PHPUnit\Framework\TestCase;
use Dazamate\SurrealGraphSync\Field\StringField;
use Dazamate\SurrealGraphSync\Field\NumberField;
use Dazamate\SurrealGraphSync\Field\DateTimeField;
use Dazamate\SurrealGraphSync\Field\RecordField;
use Dazamate\SurrealGraphSync\Field\ArrayField;
use Dazamate\SurrealGraphSync\Field\ObjectField;
use Dazamate\SurrealGraphSync\Field\RawField;
use Dazamate\SurrealGraphSync\Field\Exception\InvalidFieldException;
use Dazamate\SurrealGraphSync\Data\MappedData;
use Dazamate\SurrealGraphSync\Dto\Reference\SurrealId;
use Dazamate\SurrealGraphSync\Dto\Reference\WordPressId;
use Dazamate\SurrealGraphSync\Enum\QueryType;

class FieldValidationTest extends TestCase {

    public function testStringFieldRejectsNonString(): void {
        $this->expectException(InvalidFieldException::class);
        $this->expectExceptionMessage('StringField value must be a string');

        new StringField(123);
    }

    public function testStringFieldAcceptsString(): void {
        $this->assertSame('hello', (new StringField('hello'))->value);
    }

    public function testNumberFieldRejectsNonNumeric(): void {
        $this->expectException(InvalidFieldException::class);
        $this->expectExceptionMessage('NumberField value must be numeric');

        new NumberField('abc');
    }

    public function testNumberFieldAcceptsNumericStringAndInt(): void {
        $this->assertSame('42', (new NumberField('42'))->value);
        $this->assertSame(0.2, (new NumberField(0.2))->value);
    }

    public function testDateTimeFieldRejectsInvalidString(): void {
        $this->expectException(InvalidFieldException::class);
        $this->expectExceptionMessage('ISO8601');

        new DateTimeField('Not a date');
    }

    public function testDateTimeFieldAcceptsIsoAndTimestamp(): void {
        $this->assertInstanceOf(DateTimeField::class, new DateTimeField('2025-01-26T18:29:00+00:00'));
        $this->assertInstanceOf(DateTimeField::class, new DateTimeField('345435435345'));
        $this->assertInstanceOf(DateTimeField::class, new DateTimeField(345435435345));
    }

    public function testRecordFieldRejectsInvalidSurrealId(): void {
        $this->expectException(InvalidFieldException::class);
        $this->expectExceptionMessage('is not a valid Surreal record id');

        new RecordField(new SurrealId('abc'));
    }

    public function testRecordFieldAcceptsValidReferences(): void {
        $this->assertInstanceOf(RecordField::class, new RecordField(new SurrealId('person:35r4')));
        $this->assertInstanceOf(RecordField::class, new RecordField(new WordPressId(123, QueryType::POST)));
    }

    public function testRecordFieldAcceptsEmptyReference(): void {
        // An empty reference is allowed; it renders as NULL downstream.
        $this->assertInstanceOf(RecordField::class, new RecordField(new SurrealId('')));
    }

    public function testRawFieldRejectsEmptyType(): void {
        $this->expectException(InvalidFieldException::class);
        $this->expectExceptionMessage('non-empty Surreal type');

        new RawField('1w', '');
    }

    public function testRawFieldAcceptsType(): void {
        $this->assertSame('duration', (new RawField('1w', 'duration'))->type);
    }

    public function testNestedFieldInArrayThrowsAtConstruction(): void {
        $this->expectException(InvalidFieldException::class);
        $this->expectExceptionMessage('NumberField value must be numeric');

        new ArrayField([new NumberField('not-a-number')], 'array<number>');
    }

    public function testNestedFieldInObjectThrowsAtConstruction(): void {
        $this->expectException(InvalidFieldException::class);
        $this->expectExceptionMessage('NumberField value must be numeric');

        new ObjectField((new MappedData())->set('count', new NumberField('nope')));
    }
}
