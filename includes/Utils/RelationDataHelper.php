<?php

namespace Dazamate\SurrealGraphSync\Utils;

use Dazamate\SurrealGraphSync\Data\RelationData;
use Dazamate\SurrealGraphSync\Validate\InputValidator;

// Validation for RelationData data parcels.
final class RelationDataHelper {
    /** @param array<int, string> $errors */
    public static function validate(RelationData $relation, array &$errors): bool {
        $valid = true;

        if (!self::is_valid_endpoint($relation->from_record)) {
            $errors[] = 'Invalid or missing "from_record" in relation data.';
            $valid = false;
        }

        if (!self::is_valid_endpoint($relation->to_record)) {
            $errors[] = 'Invalid or missing "to_record" in relation data.';
            $valid = false;
        }

        if (empty($relation->relation_table)) {
            $errors[] = 'Invalid or missing "relation_table" in relation data.';
            $valid = false;
        }

        if ($relation->data !== null && !FieldValidator::validate_mapped($relation->data, $errors)) {
            $valid = false;
        }

        return $valid;
    }

    private static function is_valid_endpoint(string|int $endpoint): bool {
        return !empty($endpoint)
            && (InputValidator::is_surreal_db_record($endpoint) || ctype_digit((string) $endpoint));
    }
}
