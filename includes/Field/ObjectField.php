<?php

namespace Dazamate\SurrealGraphSync\Field;

use Dazamate\SurrealGraphSync\Data\MappedData;

class ObjectField extends Field {
    public function __construct(MappedData $data) {
        parent::__construct($data);
    }

    // The wrapped value is type-enforced as MappedData by the constructor.
    protected function validate(): void {}
}
