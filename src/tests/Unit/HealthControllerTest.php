<?php

namespace Tests\Unit;

use App\Controllers\HealthController;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exception\AMQPRuntimeException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\Support\ControllerTestCase;
use Tests\Support\FakePdo;

/**
 * Branch matrix for App\Controllers\HealthController, driven directly (no Router).
 */
final class HealthControllerTest extends ControllerTestCase
{
    private const ALL_OK = '{"status":"ok","services":{"mysql":"connected","redis":"connected","rabbitmq":"connected"}}';

    private FakePdo $db;
    private AMQPStreamConnection&MockObject $amqp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = new FakePdo($this->mockPdo(), fn () => $this->createMock(\PDOStatement::class));
        $this->db->onSql('SELECT 1');
        $this->amqp = $this->createMock(AMQPStreamConnection::class);
        $this->amqp->method('isConnected')->willReturn(true);
    }

    protected function tearDown(): void
    {
        $this->assertSame([], $this->db->unmatched, 'SQL that no test rule matched');
        parent::tearDown();
    }

    private function check(): string
    {
        (new HealthController($this->db->pdo, $this->redis, $this->amqp))->check();
        return $this->capturedOutput();
    }

    private function queueThrows(\Throwable $e): void
    {
        $this->amqp = $this->createMock(AMQPStreamConnection::class);
        $this->amqp->method('isConnected')->willThrowException($e);
    }

    private function queueDisconnected(): void
    {
        $this->amqp = $this->createMock(AMQPStreamConnection::class);
        $this->amqp->method('isConnected')->willReturn(false);
    }

    private function mysqlThrows(\Throwable $e): void
    {
        $this->db->onSql('SELECT 1', queryThrows: $e);
    }

    private static function body(string $status, string $mysql, string $redis, string $rabbit): string
    {
        return '{"status":"' . $status . '","services":{"mysql":"' . $mysql . '","redis":"' . $redis
            . '","rabbitmq":"' . $rabbit . '"}}';
    }

    // --- all ok ---

    public function testAllServicesUpReportsOkWithExactBytesAndKeyOrder(): void
    {
        $out = $this->check();

        $this->assertSame(self::ALL_OK, $out);
        $this->assertSame(['status', 'services'], array_keys(json_decode($out, true)));
        $this->assertSame(['mysql', 'redis', 'rabbitmq'], array_keys(json_decode($out, true)['services']));
    }

    public function testHandlerSetsNoStatusCodeSoItStays200(): void
    {
        $this->check();
        $this->assertSame(200, $this->httpStatus());
    }

    public function testHandlerLeavesStatusCodeUntouchedEvenWhenDegraded(): void
    {
        $this->mysqlThrows(new \PDOException('gone'));
        http_response_code(202);

        $this->check();

        $this->assertSame(202, $this->httpStatus());
    }

    public function testProbesEachServiceExactlyOnce(): void
    {
        $this->amqp = $this->createMock(AMQPStreamConnection::class);
        $this->amqp->expects($this->once())->method('isConnected')->willReturn(true);

        $this->check();

        $this->assertSame(['SELECT 1'], $this->db->queried);
        $this->assertSame([['ping', []]], $this->redis->calls);
    }

    public function testMysqlProbeUsesPdoQueryAndNeverPrepareOrTransactions(): void
    {
        $this->check();

        $this->assertSame(['SELECT 1'], $this->db->queried);
        $this->assertSame([], $this->db->prepared);
        $this->assertSame([], $this->db->executions);
        $this->assertSame([], $this->db->events);
    }

    public function testMysqlProbeCallsPdoQueryWithSelectOneVerbatim(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $pdo->expects($this->once())->method('query')->with('SELECT 1');
        $pdo->expects($this->never())->method('prepare');
        $pdo->expects($this->never())->method('exec');

        (new HealthController($pdo, $this->redis, $this->amqp))->check();

        $this->assertSame(self::ALL_OK, $this->capturedOutput());
    }

    public function testRedisProbeIsPingWithNoArgumentsAndNoCacheReads(): void
    {
        $this->check();

        $this->assertSame([['ping', []]], $this->redis->calls);
    }

    // --- mysql failures ---

    public static function exceptionProvider(): array
    {
        return [
            'generic Exception' => [new \Exception('mysql gone')],
            'PDOException' => [new \PDOException('SQLSTATE[HY000] [2002] Connection refused')],
            'RuntimeException' => [new \RuntimeException('mysql gone')],
        ];
    }

    #[DataProvider('exceptionProvider')]
    public function testMysqlQueryThrowingMarksMysqlErrorAndDegradesOthersUnaffected(\Throwable $e): void
    {
        $this->mysqlThrows($e);

        $out = $this->check();

        $this->assertSame(self::body('degraded', 'error', 'connected', 'connected'), $out);
        $this->assertSame(200, $this->httpStatus());
        $this->assertSame([['ping', []]], $this->redis->calls, 'redis is still probed after mysql failed');
    }

    // --- redis failures ---

    #[DataProvider('exceptionProvider')]
    public function testRedisPingThrowingMarksRedisErrorAndDegrades(\Throwable $e): void
    {
        $this->redis->pingException = $e;

        $out = $this->check();

        $this->assertSame(self::body('degraded', 'connected', 'error', 'connected'), $out);
        $this->assertSame(['SELECT 1'], $this->db->queried);
    }

    // --- rabbitmq ---

    public function testRabbitmqNotConnectedReportsErrorButStatusStaysOk(): void
    {
        $this->queueDisconnected();

        $out = $this->check();

        $this->assertSame(self::body('ok', 'connected', 'connected', 'error'), $out);
        $this->assertSame('ok', json_decode($out, true)['status']);
    }

    #[DataProvider('exceptionProvider')]
    public function testRabbitmqIsConnectedThrowingReportsErrorAndDegrades(\Throwable $e): void
    {
        $this->queueThrows($e);

        $this->assertSame(self::body('degraded', 'connected', 'connected', 'error'), $this->check());
    }

    public function testRabbitmqAmqpExceptionIsCaught(): void
    {
        $this->queueThrows(new AMQPRuntimeException('rabbit gone'));

        $this->assertSame(self::body('degraded', 'connected', 'connected', 'error'), $this->check());
    }

    // --- multiple failures ---

    public static function combinationProvider(): array
    {
        // [mysqlDown, redisDown, rabbit ('up'|'disconnected'|'throws'), expected body]
        return [
            'mysql + redis' => [true, true, 'up', self::body('degraded', 'error', 'error', 'connected')],
            'mysql + rabbit throws' => [true, false, 'throws', self::body('degraded', 'error', 'connected', 'error')],
            'redis + rabbit throws' => [false, true, 'throws', self::body('degraded', 'connected', 'error', 'error')],
            'all throw' => [true, true, 'throws', self::body('degraded', 'error', 'error', 'error')],
            'mysql down + rabbit disconnected' => [true, false, 'disconnected', self::body('degraded', 'error', 'connected', 'error')],
            'redis down + rabbit disconnected' => [false, true, 'disconnected', self::body('degraded', 'connected', 'error', 'error')],
            'mysql + redis down + rabbit disconnected' => [true, true, 'disconnected', self::body('degraded', 'error', 'error', 'error')],
        ];
    }

    #[DataProvider('combinationProvider')]
    public function testMultipleFailuresAtOnce(bool $mysqlDown, bool $redisDown, string $rabbit, string $expected): void
    {
        if ($mysqlDown) {
            $this->mysqlThrows(new \PDOException('mysql gone'));
        }
        if ($redisDown) {
            $this->redis->pingException = new \RuntimeException('redis gone');
        }
        match ($rabbit) {
            'throws' => $this->queueThrows(new \RuntimeException('rabbit gone')),
            'disconnected' => $this->queueDisconnected(),
            default => null,
        };

        $this->assertSame($expected, $this->check());
    }

    // --- only Exception is caught (verbatim catch clauses) ---

    public function testNonExceptionThrowablesAreNotCaught(): void
    {
        $this->redis->pingException = new \Error('fatal');

        try {
            $this->check();
            $this->fail('Error from ping() was expected to propagate');
        } catch (\Error $e) {
            $this->assertSame('fatal', $e->getMessage());
        }
    }
}
