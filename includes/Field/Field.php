<?php

namespace Dazamate\SurrealGraphSync\Field;

abstract class Field {
    public function __construct(public readonly mixed $value) {
        $this->validate();
    }

    abstract protected function validate(): void;
}
