<?php

namespace Dazamate\SurrealGraphSync\Field;

use Dazamate\SurrealGraphSync\Dto\Reference\RecordReference;
use Dazamate\SurrealGraphSync\Dto\Reference\SurrealId;
use Dazamate\SurrealGraphSync\Field\Exception\InvalidFieldException;
use Dazamate\SurrealGraphSync\Utils\RecordReferenceResolver;
use Dazamate\SurrealGraphSync\Validate\InputValidator;

class RecordField extends Field {
    public function __construct(RecordReference $reference) {
        parent::__construct($reference);
    }

    protected function validate(): void {
        $reference = $this->value;

        // An empty reference is allowed and renders as NULL.
        if (!$reference instanceof RecordReference || RecordReferenceResolver::is_empty($reference)) {
            return;
        }

        if ($reference instanceof SurrealId && !InputValidator::is_surreal_db_record($reference->record_id)) {
            throw new InvalidFieldException(
                sprintf("'%s' is not a valid Surreal record id (expected 'table:id').", $reference->record_id)
            );
        }
    }
}
