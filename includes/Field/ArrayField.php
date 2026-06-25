<?php

namespace Dazamate\SurrealGraphSync\Field;

class ArrayField extends Field {
    /** @param array<mixed> $items */
    public function __construct(array $items, public readonly string $type = 'array') {
        parent::__construct($items);
    }

    // The item list is type-enforced by the constructor signature, and any Field
    // items validate themselves when constructed.
    protected function validate(): void {}
}
