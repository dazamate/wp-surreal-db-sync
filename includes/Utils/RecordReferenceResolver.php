<?php

namespace Dazamate\SurrealGraphSync\Utils;

use Dazamate\SurrealGraphSync\Dto\Reference\RecordReference;
use Dazamate\SurrealGraphSync\Dto\Reference\SurrealId;
use Dazamate\SurrealGraphSync\Dto\Reference\WordPressId;

// Resolves RecordReference data parcels to Surreal record ids.
final class RecordReferenceResolver {
    public static function resolve(RecordReference $reference): ?string {
        if (self::is_empty($reference)) {
            return null;
        }

        return match (true) {
            $reference instanceof SurrealId   => $reference->record_id,
            $reference instanceof WordPressId => Inputs::parse_record_id((string) $reference->id, $reference->type),
            default                           => null,
        };
    }

    public static function is_empty(RecordReference $reference): bool {
        return match (true) {
            $reference instanceof SurrealId   => $reference->record_id === '',
            $reference instanceof WordPressId => $reference->id < 1,
            default                           => true,
        };
    }
}
