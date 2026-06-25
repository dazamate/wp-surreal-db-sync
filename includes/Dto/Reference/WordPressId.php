<?php

namespace Dazamate\SurrealGraphSync\Dto\Reference;

use Dazamate\SurrealGraphSync\Enum\QueryType;

final class WordPressId implements RecordReference {
    public function __construct(
        public readonly int $id,
        public readonly QueryType $type,
    ) {}
}
