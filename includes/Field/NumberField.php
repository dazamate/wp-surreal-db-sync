<?php

namespace Dazamate\SurrealGraphSync\Field;

use Dazamate\SurrealGraphSync\Field\Exception\InvalidFieldException;

class NumberField extends Field {
    protected function validate(): void {
        // null is the documented sentinel that renders as NULL.
        if ($this->value !== null && !is_numeric($this->value)) {
            throw new InvalidFieldException(
                sprintf('NumberField value must be numeric, got %s.', get_debug_type($this->value))
            );
        }
    }
}
