<?php

namespace Dazamate\SurrealGraphSync\Client;

/**
 * SurrealDB HTTP client using application/json (not CBOR).
 *
 * The official PHP SDK speaks CBOR; its float16 decoder crashes on PHP 8+
 * ("Bit shift by negative number") when responses contain fractional numbers
 * (e.g. quantity 0.5). JSON transport avoids that third-party bug entirely.
 */
class SurrealHttpClient implements SurrealClientInterface {
    private string $base_url;
    private string $namespace;
    private string $database;
    private ?string $token = null;

    public function __construct(
        string $base_url,
        string $namespace,
        string $database
    ) {
        $this->base_url  = rtrim( $base_url, '/' );
        $this->namespace = $namespace;
        $this->database  = $database;
    }

    /**
     * @param array{user: string, pass: string, namespace?: string} $auth
     */
    public function signin( array $auth ): void {
        $body = [
            'user' => $auth['user'],
            'pass' => $auth['pass'],
        ];

        // Namespace-scoped users only — system root must omit NS.
        if ( isset( $auth['namespace'] ) && $auth['namespace'] !== '' ) {
            $body['namespace'] = $auth['namespace'];
        }

        $res = $this->request( 'POST', '/signin', $body, with_ns: false, with_auth: false );

        if ( ! is_array( $res ) || empty( $res['token'] ) || ! is_string( $res['token'] ) ) {
            $msg = is_array( $res ) && isset( $res['details'] )
                ? (string) $res['details']
                : 'Surreal signin failed';
            throw new \RuntimeException( $msg );
        }

        $this->token = $res['token'];
    }

    public function use( string $namespace, string $database ): void {
        $this->namespace = $namespace;
        $this->database  = $database;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<int, mixed>|null
     */
    public function query( string $query, array $params = [] ): ?array {
        $raw = $this->queryRaw( $query, $params );

        if ( ! is_array( $raw ) ) {
            return null;
        }

        return array_map(
            static fn( array $item ) => $item['result'] ?? null,
            $raw
        );
    }

    /**
     * @param array<string, mixed> $params
     * @return array<int, array{status?: string, result?: mixed, time?: string}>|null
     */
    public function queryRaw( string $query, array $params = [] ): ?array {
        // HTTP /sql does not take RPC-style params; only plain SurrealQL.
        // Callers in this plugin always inline values already.
        unset( $params );

        $decoded = $this->request( 'POST', '/sql', $query, with_ns: true, with_auth: true, raw_body: true );

        if ( ! is_array( $decoded ) ) {
            throw new \RuntimeException( 'Surreal /sql returned a non-array response' );
        }

        // Normalise each statement to the same shape as the SDK's queryRaw().
        $out = [];

        foreach ( $decoded as $row ) {
            if ( ! is_array( $row ) ) {
                $out[] = [
                    'status' => 'ERR',
                    'result' => is_string( $row ) ? $row : 'Invalid statement response',
                ];
                continue;
            }

            $out[] = [
                'status' => (string) ( $row['status'] ?? 'ERR' ),
                'result' => $row['result'] ?? null,
                'time'   => $row['time'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * @param array<string, mixed>|string|null $body
     * @return mixed
     */
    private function request(
        string $method,
        string $path,
        array|string|null $body = null,
        bool $with_ns = true,
        bool $with_auth = true,
        bool $raw_body = false
    ): mixed {
        $url = $this->base_url . $path;

        $headers = [
            'Accept: application/json',
        ];

        if ( $with_auth && $this->token !== null ) {
            $headers[] = 'Authorization: Bearer ' . $this->token;
        }

        if ( $with_ns ) {
            $headers[] = 'surreal-ns: ' . $this->namespace;
            $headers[] = 'surreal-db: ' . $this->database;
        }

        $payload = null;

        if ( $body !== null ) {
            if ( $raw_body && is_string( $body ) ) {
                $headers[] = 'Content-Type: text/plain';
                $payload   = $body;
            } else {
                $headers[] = 'Content-Type: application/json';
                $payload   = json_encode( $body, JSON_THROW_ON_ERROR );
            }
        }

        $ch = curl_init( $url );

        if ( $ch === false ) {
            throw new \RuntimeException( 'Unable to init curl for Surreal request' );
        }

        curl_setopt_array( $ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_POSTFIELDS     => $payload,
        ] );

        $response = curl_exec( $ch );
        $errno    = curl_errno( $ch );
        $error    = curl_error( $ch );
        $status   = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
        // PHP 8.0+ closes the handle on unset; curl_close() is deprecated in 8.5.
        unset( $ch );

        if ( $errno !== 0 ) {
            throw new \RuntimeException( 'Surreal HTTP error: ' . $error );
        }

        if ( ! is_string( $response ) || $response === '' ) {
            throw new \RuntimeException( sprintf( 'Empty Surreal response (HTTP %d)', $status ) );
        }

        $decoded = json_decode( $response, true );

        if ( json_last_error() !== JSON_ERROR_NONE ) {
            throw new \RuntimeException( 'Invalid JSON from Surreal: ' . json_last_error_msg() );
        }

        if ( $status >= 400 ) {
            $detail = is_array( $decoded )
                ? (string) ( $decoded['details'] ?? $decoded['description'] ?? $decoded['result'] ?? $response )
                : $response;
            throw new \RuntimeException( sprintf( 'Surreal HTTP %d: %s', $status, $detail ) );
        }

        return $decoded;
    }
}
