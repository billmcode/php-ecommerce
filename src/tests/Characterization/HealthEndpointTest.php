<?php

namespace Tests\Characterization;

use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PhpAmqpLib\Exception\AMQPRuntimeException;
use Predis\Connection\ConnectionException;
use RuntimeException;
use Tests\Support\HttpTestCase;

/**
 * Characterizes GET /health exactly as the original Router behaves.
 *
 * Quirks pinned here on purpose:
 *  - the HTTP status is always 200, even when the top-level status is "degraded"
 *  - a RabbitMQ isConnected() === false reports rabbitmq "error" but leaves the top-level status "ok"
 *    (only a thrown exception degrades it)
 *  - only \Exception is caught, never \Error
 */
final class HealthEndpointTest extends HttpTestCase
{
    private const ALL_OK = '{"status":"ok","services":{"mysql":"connected","redis":"connected","rabbitmq":"connected"}}';

    #[Test]
    public function all_services_up_returns_the_exact_ok_document(): void
    {
        [$status, $body] = $this->request('GET', '/health');

        self::assertSame(200, $status);
        self::assertSame(self::ALL_OK, $body);
    }

    #[Test]
    public function it_probes_each_dependency_exactly_once(): void
    {
        $this->request('GET', '/health');

        self::assertSame(['SELECT 1'], array_column($this->pdo->executed(), 'sql'));
        self::assertSame([[]], $this->pdo->paramsOf('SELECT 1'));
        self::assertSame([['ping', []]], $this->redis->calls());
        self::assertSame([], $this->amqp->published());
        self::assertSame(0, $this->amqp->channelsOpened());
        self::assertSame([], $this->pdo->transactionEvents());
    }

    #[Test]
    public function health_does_not_touch_the_product_cache(): void
    {
        $this->redis->seed('products:all', '[]');

        [, $body] = $this->request('GET', '/health');

        self::assertSame(self::ALL_OK, $body);
        self::assertSame([], $this->redis->callsTo('get'));
        self::assertSame([], $this->redis->callsTo('setex'));
    }

    #[Test]
    public function mysql_failure_degrades_the_status(): void
    {
        $this->pdo->onSql('SELECT 1')->throws(new PDOException('MySQL server has gone away'));

        [$status, $body] = $this->request('GET', '/health');

        self::assertSame(200, $status);
        self::assertSame(
            '{"status":"degraded","services":{"mysql":"error","redis":"connected","rabbitmq":"connected"}}',
            $body
        );
    }

    #[Test]
    public function redis_failure_degrades_the_status(): void
    {
        $this->redis->failOn('ping', new ConnectionException(
            $this->createMock(\Predis\Connection\NodeConnectionInterface::class),
            'Connection refused'
        ));

        [$status, $body] = $this->request('GET', '/health');

        self::assertSame(200, $status);
        self::assertSame(
            '{"status":"degraded","services":{"mysql":"connected","redis":"error","rabbitmq":"connected"}}',
            $body
        );
    }

    #[Test]
    public function rabbitmq_reporting_disconnected_is_an_error_but_does_not_degrade_the_status(): void
    {
        $this->amqp->setConnected(false);

        [$status, $body] = $this->request('GET', '/health');

        self::assertSame(200, $status);
        self::assertSame(
            '{"status":"ok","services":{"mysql":"connected","redis":"connected","rabbitmq":"error"}}',
            $body
        );
    }

    #[Test]
    public function rabbitmq_check_throwing_is_an_error_and_degrades_the_status(): void
    {
        $this->amqp->failConnectionCheckWith(new AMQPRuntimeException('Broken pipe'));

        [$status, $body] = $this->request('GET', '/health');

        self::assertSame(200, $status);
        self::assertSame(
            '{"status":"degraded","services":{"mysql":"connected","redis":"connected","rabbitmq":"error"}}',
            $body
        );
    }

    /**
     * @param array{mysql: bool, redis: bool, rabbitmq: string} $down  rabbitmq: 'up' | 'disconnected' | 'throws'
     */
    #[Test]
    #[DataProvider('downCombinations')]
    public function every_combination_of_failures_is_reported_independently(
        array $down,
        string $expectedStatus,
        string $expectedMysql,
        string $expectedRedis,
        string $expectedRabbit
    ): void {
        if ($down['mysql']) {
            $this->pdo->onSql('SELECT 1')->throws(new PDOException('down'));
        }
        if ($down['redis']) {
            $this->redis->failOn('ping', new RuntimeException('down'));
        }
        if ($down['rabbitmq'] === 'disconnected') {
            $this->amqp->setConnected(false);
        } elseif ($down['rabbitmq'] === 'throws') {
            $this->amqp->failConnectionCheckWith(new RuntimeException('down'));
        }

        [$status, $body] = $this->request('GET', '/health');

        $expected = json_encode([
            'status' => $expectedStatus,
            'services' => ['mysql' => $expectedMysql, 'redis' => $expectedRedis, 'rabbitmq' => $expectedRabbit],
        ]);
        self::assertSame(200, $status);
        self::assertSame($expected, $body);
        // Every probe still runs even when an earlier one failed.
        self::assertSame(['SELECT 1'], array_column($this->pdo->executed(), 'sql'));
        self::assertSame([['ping', []]], $this->redis->calls());
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string, 2: string, 3: string, 4: string}> */
    public static function downCombinations(): array
    {
        $c = 'connected';
        $e = 'error';

        return [
            'all up' => [['mysql' => false, 'redis' => false, 'rabbitmq' => 'up'], 'ok', $c, $c, $c],
            'mysql only' => [['mysql' => true, 'redis' => false, 'rabbitmq' => 'up'], 'degraded', $e, $c, $c],
            'redis only' => [['mysql' => false, 'redis' => true, 'rabbitmq' => 'up'], 'degraded', $c, $e, $c],
            'rabbitmq disconnected only' => [['mysql' => false, 'redis' => false, 'rabbitmq' => 'disconnected'], 'ok', $c, $c, $e],
            'rabbitmq throws only' => [['mysql' => false, 'redis' => false, 'rabbitmq' => 'throws'], 'degraded', $c, $c, $e],
            'mysql and redis' => [['mysql' => true, 'redis' => true, 'rabbitmq' => 'up'], 'degraded', $e, $e, $c],
            'mysql and rabbitmq disconnected' => [['mysql' => true, 'redis' => false, 'rabbitmq' => 'disconnected'], 'degraded', $e, $c, $e],
            'mysql and rabbitmq throws' => [['mysql' => true, 'redis' => false, 'rabbitmq' => 'throws'], 'degraded', $e, $c, $e],
            'redis and rabbitmq disconnected' => [['mysql' => false, 'redis' => true, 'rabbitmq' => 'disconnected'], 'degraded', $c, $e, $e],
            'redis and rabbitmq throws' => [['mysql' => false, 'redis' => true, 'rabbitmq' => 'throws'], 'degraded', $c, $e, $e],
            'all down, rabbitmq disconnected' => [['mysql' => true, 'redis' => true, 'rabbitmq' => 'disconnected'], 'degraded', $e, $e, $e],
            'all down, rabbitmq throws' => [['mysql' => true, 'redis' => true, 'rabbitmq' => 'throws'], 'degraded', $e, $e, $e],
        ];
    }

    #[Test]
    public function an_error_instead_of_an_exception_is_not_caught_by_the_health_check(): void
    {
        $this->redis->failOn('ping', new \TypeError('not an exception'));

        $this->expectException(\TypeError::class);

        $this->request('GET', '/health');
    }

    #[Test]
    public function the_service_keys_are_always_in_mysql_redis_rabbitmq_order(): void
    {
        $this->pdo->onSql('SELECT 1')->throws(new PDOException('down'));
        $this->redis->failOn('ping', new RuntimeException('down'));
        $this->amqp->setConnected(false);

        [, $body] = $this->request('GET', '/health');

        $decoded = json_decode($body, true);
        self::assertSame(['status', 'services'], array_keys($decoded));
        self::assertSame(['mysql', 'redis', 'rabbitmq'], array_keys($decoded['services']));
    }

    #[Test]
    public function the_health_route_accepts_a_trailing_slash_and_query_string(): void
    {
        [$status, $body] = $this->request('GET', '/health/?probe=1');

        self::assertSame(200, $status);
        self::assertSame(self::ALL_OK, $body);
    }
}
