<?php

namespace Dazamate\SurrealGraphSync\Utils;

use Dazamate\SurrealGraphSync\Data\MappedData;

// Presence checks for MappedData parcels. Per-field type validation lives in each
// Field subclass constructor, which throws InvalidFieldException on a type mismatch.
final class FieldValidator {
    /**
     * @param array<int, string> $errors
     * @param array<int, string> $required
     */
    public static function validate_mapped(MappedData $data, array &$errors = [], array $required = []): bool {
        $valid = true;

        foreach ($required as $key) {
            if (!$data->has($key)) {
                $errors[] = "[$key]: required field is missing.";
                $valid = false;
            }
        }

        return $valid;
    }
}
