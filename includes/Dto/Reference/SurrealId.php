<?php

namespace Dazamate\SurrealGraphSync\Dto\Reference;

final class SurrealId implements RecordReference {
    public function __construct(public readonly string $record_id) {}
}
