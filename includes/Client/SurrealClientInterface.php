<?php

namespace Dazamate\SurrealGraphSync\Client;

/**
 * Minimal Surreal query surface used by the plugin.
 * Implemented by SurrealHttpClient (JSON/HTTP) and satisfied by the SDK in tests.
 */
interface SurrealClientInterface {
    /**
     * @param array<string, mixed> $params
     * @return array<int, mixed>|null
     */
    public function query( string $query, array $params = [] ): ?array;

    /**
     * @param array<string, mixed> $params
     * @return array<int, array{status?: string, result?: mixed, time?: string}>|null
     */
    public function queryRaw( string $query, array $params = [] ): ?array;
}
