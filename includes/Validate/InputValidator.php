<?php

namespace Dazamate\SurrealGraphSync\Validate;

use Dazamate\SurrealGraphSync\Query\QueryBuilder;

class InputValidator {
    public static function is_ISO8601(mixed $value): bool {        
        if (!is_string($value)) {
            return false;
        }

        // Accept both the numeric UTC offset (e.g. +00:00) and the 'Z' (Zulu) suffix.
        foreach ([\DateTime::ATOM, 'Y-m-d\TH:i:s\Z'] as $format) {
            $dt = \DateTime::createFromFormat($format, $value);

            if ($dt && $dt->format($format) === $value) {
                return true;
            }
        }

        return false;
    }

    public static function is_surreal_db_record(mixed $value): bool {
        return is_string($value) && strpos($value, ':') !== false;
    }
}
