<?php

namespace Dazamate\SurrealGraphSync\Field;

use Dazamate\SurrealGraphSync\Field\Exception\InvalidFieldException;
use Dazamate\SurrealGraphSync\Validate\InputValidator;

class DateTimeField extends Field {
    protected function validate(): void {
        // null is the documented sentinel that renders as NULL.
        if ($this->value === null) {
            return;
        }

        // A Unix timestamp (int or numeric string) is accepted as-is.
        if (ctype_digit((string) $this->value)) {
            return;
        }

        if (!InputValidator::is_ISO8601($this->value)) {
            throw new InvalidFieldException(
                'DateTimeField value must be a Unix timestamp or a valid ISO8601 datetime string (e.g. 2025-01-26T18:29:00+00:00).'
            );
        }
    }
}
