<?php

namespace Dazamate\SurrealGraphSync\Utils;

use Dazamate\SurrealGraphSync\Validate\InputValidator;
use Dazamate\SurrealGraphSync\Enum\QueryType;
use Dazamate\SurrealGraphSync\Enum\MetaKeys;

class Inputs {
    public static function parse_record_id(string $record_value, QueryType $type = QueryType::POST): ?string {
        // A raw surreal record id was passed
        if (InputValidator::is_surreal_db_record($record_value)) return $record_value;

        // if it's an integer, assume it's a post id
        if (ctype_digit((string)$record_value)) {
            return match($type) {
                QueryType::POST => get_post_meta((int)$record_value, MetaKeys::SURREAL_DB_RECORD_ID_META_KEY->value, true) ?: NULL,
                QueryType::USER => get_user_meta((int)$record_value, MetaKeys::SURREAL_DB_RECORD_ID_META_KEY->value, true) ?: NULL,
                QueryType::TERM => get_term_meta((int)$record_value, MetaKeys::SURREAL_DB_RECORD_ID_META_KEY->value, true) ?: NULL,
                default => NULL
            };
        }

        return NULL;
    }
}