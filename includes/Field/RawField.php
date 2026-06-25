<?php

namespace Dazamate\SurrealGraphSync\Field;

use Dazamate\SurrealGraphSync\Field\Exception\InvalidFieldException;

// Escape hatch for a Surreal type with no dedicated Field class; the value is rendered
// verbatim behind a raw cast (e.g. <duration>1w).
class RawField extends Field {
    public function __construct(mixed $value, public readonly string $type) {
        parent::__construct($value);
    }

    protected function validate(): void {
        if ($this->type === '') {
            throw new InvalidFieldException('RawField requires a non-empty Surreal type.');
        }
    }
}
