<?php

namespace Dazamate\SurrealGraphSync\Field;

use Dazamate\SurrealGraphSync\Field\Exception\InvalidFieldException;

class StringField extends Field {
    protected function validate(): void {
        // null is the documented sentinel that renders as NULL.
        if ($this->value !== null && !is_string($this->value)) {
            throw new InvalidFieldException(
                sprintf('StringField value must be a string, got %s.', get_debug_type($this->value))
            );
        }
    }
}
