<?php

namespace Dazamate\SurrealGraphSync\Tests\Service;

use PHPUnit\Framework\TestCase;
use Dazamate\SurrealGraphSync\Service\SyncService;

/**
 * Exposes SyncService's protected transaction-response helpers so their pure
 * logic (which message wins, when nothing landed) can be asserted without a DB.
 * The live behaviour is covered by the Integration suite.
 */
class SyncServiceProbe extends SyncService {
    public static function errors(?array $res): array {
        return self::collect_statement_errors($res);
    }

    public static function recordId(?array $res): ?string {
        return self::record_id_from_transaction($res);
    }
}

class SyncServiceTest extends TestCase {
    public function testNoErrorsWhenAllStatementsOk(): void {
        $res = [
            ['status' => 'OK', 'result' => 'whatever'],
            ['status' => 'OK', 'result' => []],
        ];
        $this->assertSame([], SyncServiceProbe::errors($res));
    }

    public function testPrefersSpecificErrorOverGenericRollbackMessage(): void {
        $res = [
            ['status' => 'ERR', 'result' => 'The query was not executed due to a failed transaction'],
            ['status' => 'ERR', 'result' => 'An error occurred: boom'],
        ];
        $this->assertSame(['An error occurred: boom'], SyncServiceProbe::errors($res));
    }

    public function testFallsBackToGenericWhenThatIsAllThereIs(): void {
        $res = [
            ['status' => 'ERR', 'result' => 'The query was not executed due to a failed transaction'],
        ];
        $this->assertSame(['The query was not executed due to a failed transaction'], SyncServiceProbe::errors($res));
    }

    public function testNullResponseIsReportedAsEmptyResponse(): void {
        $this->assertSame(['Empty response from Surreal'], SyncServiceProbe::errors(null));
    }

    public function testRecordIdFromStringResult(): void {
        $this->assertSame('post:abc', SyncServiceProbe::recordId([['result' => 'post:abc', 'status' => 'OK']]));
    }

    public function testRecordIdNullWhenMissing(): void {
        $this->assertNull(SyncServiceProbe::recordId([['result' => null, 'status' => 'OK']]));
        $this->assertNull(SyncServiceProbe::recordId(null));
    }
}
