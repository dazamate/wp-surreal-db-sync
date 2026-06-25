<?php

namespace Dazamate\SurrealGraphSync\Data;

final class RelationData {
    public function __construct(
        public readonly string|int $from_record,
        public readonly string|int $to_record,
        public readonly string $relation_table,
        public readonly ?MappedData $data = null,
        public readonly bool $unique = true,
    ) {}
}
