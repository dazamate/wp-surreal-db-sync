<?php

namespace Dazamate\SurrealGraphSync\Utils;

use Dazamate\SurrealGraphSync\Data\MappedData;
use Dazamate\SurrealGraphSync\Field\ArrayField;
use Dazamate\SurrealGraphSync\Field\DateTimeField;
use Dazamate\SurrealGraphSync\Field\Field;
use Dazamate\SurrealGraphSync\Field\NumberField;
use Dazamate\SurrealGraphSync\Field\ObjectField;
use Dazamate\SurrealGraphSync\Field\RawField;
use Dazamate\SurrealGraphSync\Field\RecordField;
use Dazamate\SurrealGraphSync\Field\StringField;

// Renders Field/MappedData data parcels into SurrealQL fragments.
final class FieldRenderer {
    // Render as a SurrealQL object literal, e.g. {title: <string>'x', post_id: <number>1}.
    public static function to_object_string(MappedData $data): string {
        $parts = [];

        foreach ($data->all() as $key => $field) {
            $parts[] = sprintf('%s: %s', $key, self::to_property_clause($field));
        }

        return sprintf('{%s}', implode(', ', $parts));
    }

    public static function to_property_clause(Field $field): string {
        return match (true) {
            $field instanceof StringField   => self::is_empty($field) ? 'NULL' : sprintf("<string>'%s'", self::escape((string) $field->value)),
            // Cast to int|float so floats (e.g. 0.2) aren't truncated, but the value
            // still renders without quotes.
            $field instanceof NumberField   => self::is_empty($field) ? 'NULL' : sprintf('<number>%s', $field->value + 0),
            $field instanceof DateTimeField => self::is_empty($field) ? 'NULL' : sprintf("<datetime>'%s'", self::normalise_datetime($field->value)),
            $field instanceof ArrayField    => self::is_empty($field) ? 'NULL' : sprintf('<%s>%s', $field->type, self::render_elements($field->value)),
            $field instanceof ObjectField   => self::is_empty($field) ? 'NULL' : sprintf('<object>%s', self::to_object_string($field->value)),
            $field instanceof RawField      => self::is_empty($field) ? 'NULL' : sprintf('<%s>%s', $field->type, $field->value),
            $field instanceof RecordField   => self::render_record_clause($field),
            default                         => 'NULL',
        };
    }

    public static function to_array_element(Field $field): ?string {
        return match (true) {
            $field instanceof StringField   => sprintf("'%s'", self::escape((string) $field->value)),
            $field instanceof NumberField   => (string) $field->value,
            $field instanceof DateTimeField => sprintf("<datetime>'%s'", self::normalise_datetime($field->value)),
            // Nested arrays render without an outer cast.
            $field instanceof ArrayField    => self::render_elements($field->value),
            // Objects nest without an outer cast inside arrays.
            $field instanceof ObjectField   => self::to_object_string($field->value),
            $field instanceof RawField      => sprintf("'%s'", self::escape((string) $field->value)),
            $field instanceof RecordField   => RecordReferenceResolver::resolve($field->value),
            default                         => null,
        };
    }

    // Backslashes must be escaped before quotes so the quote escape isn't doubled.
    public static function escape(string $value): string {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
    }

    private static function render_record_clause(RecordField $field): string {
        $record_id = RecordReferenceResolver::resolve($field->value);

        if ($record_id === null) {
            return 'NULL';
        }

        // The resolved id is always `table:id`, so the target table is derived from
        // it — the caller never has to name it. This keeps the cast specific
        // (<record<image>>) for SCHEMAFULL tables without burdening the mapping code.
        $table = explode(':', $record_id, 2)[0];

        return sprintf('<record<%s>>%s', $table, $record_id);
    }

    private static function is_empty(Field $field): bool {
        // Zero is a legitimate number value, so it must not collapse to NULL.
        if ($field instanceof NumberField) {
            return !($field->value === 0 || $field->value === '0') && empty($field->value);
        }

        if ($field instanceof ObjectField) {
            return $field->value->is_empty();
        }

        return empty($field->value);
    }

    /** @param array<mixed> $items */
    private static function render_elements(array $items): string {
        if (empty($items)) {
            return '[]';
        }

        $out = [];

        foreach ($items as $item) {
            $rendered = self::render_element($item);

            if ($rendered !== null) {
                $out[] = $rendered;
            }
        }

        return sprintf('[%s]', implode(', ', $out));
    }

    private static function render_element(mixed $item): ?string {
        if ($item instanceof Field) {
            return self::to_array_element($item);
        }

        // Plain (untyped) array nests as a sub-array.
        if (is_array($item)) {
            return self::render_elements($item);
        }

        if (is_string($item)) {
            return sprintf("'%s'", self::escape($item));
        }

        return (string) $item;
    }

    // Convert a unix timestamp to an ISO8601 string, otherwise pass through.
    private static function normalise_datetime(mixed $value): string {
        return ctype_digit((string) $value)
            ? date('c', (int) $value)
            : (string) $value;
    }
}
