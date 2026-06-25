<?php

namespace Dazamate\SurrealGraphSync\Data;

use Dazamate\SurrealGraphSync\Field\Field;

final class MappedData {
    /** @var array<string, Field> */
    private array $fields = [];

    public function set(string $key, Field $field): static {
        $this->fields[$key] = $field;
        return $this;
    }

    // Only adds the field when $condition is truthy; the factory defers building it.
    public function set_if(bool $condition, string $key, callable $field_factory): static {
        if ($condition) {
            $this->set($key, $field_factory());
        }

        return $this;
    }

    public function get(string $key): ?Field {
        return $this->fields[$key] ?? null;
    }

    public function has(string $key): bool {
        return isset($this->fields[$key]);
    }

    public function remove(string $key): static {
        unset($this->fields[$key]);
        return $this;
    }

    /** @return array<string, Field> */
    public function all(): array {
        return $this->fields;
    }

    public function is_empty(): bool {
        return empty($this->fields);
    }
}
