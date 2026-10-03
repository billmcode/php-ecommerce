<?php

namespace Tests\Controllers;

use App\Controllers\HealthController;
use Error;
use Exception;
use InvalidArgumentException;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exception\AMQPRuntimeException;
use Predis\Connection\ConnectionException;
use Predis\Connection\NodeConnectionInterface;
use ReflectionMethod;
use RuntimeException;
use Throwable;
use TypeError;
use Tests\Support\HttpTestCase;

/**
 * Unit tests for App\Controllers\HealthController, instantiated directly (no Router).
 *
 * Behaviour pinned on purpose (inherited from the original Router handler):
 *  - the controller never sets an HTTP status: the response is always whatever the SAPI default is (200)
 *  - a RabbitMQ isConnected() === false marks rabbitmq "error" but does NOT degrade the top-level status;
 *    only a thrown exception does
 *  - only \Exception is caught; an \Error escapes
 *  - every dependency is probed even when an earlier one failed
 */
final class HealthControllerTest extends HttpTestCase
{
    private const ALL_OK = '{"status":"ok","services":{"mysql":"connected","redis":"connected","rabbitmq":"connected"}}';

    private function controller(): HealthController
    {
        return new HealthController($this->pdo, $this->redis, $this->amqp->connection());
    }

    /** @return array{0: int, 1: string} */
    private function health(?HealthController $controller = null): array
    {
        $controller ??= $this->controller();

        return $this->capture(static function () use ($controller): void {
            $controller->health();
        });
    }

    private function redisFailure(string $message = 'Connection refused'): ConnectionException
    {
        return new ConnectionException($this->createMock(NodeConnectionInterface::class), $message);
    }

    // ------------------------------------------------------------------
    // Happy path
    // ------------------------------------------------------------------

    #[Test]
    public function all_services_connected_returns_the_exact_ok_document(): void
    {
        [$status, $body] = $this->health();

        self::assertSame(200, $status);
        self::assertSame(self::ALL_OK, $body);
    }

    #[Test]
    public function the_document_decodes_to_the_expected_structure(): void
    {
        [, $body] = $this->health();

        self::assertSame(
            [
                'status' => 'ok',
                'services' => ['mysql' => 'connected', 'redis' => 'connected', 'rabbitmq' => 'connected'],
            ],
            json_decode($body, true)
        );
    }

    #[Test]
    public function services_are_reported_in_mysql_redis_rabbitmq_order(): void
    {
        $this->pdo->onSql('SELECT 1')->throws(new PDOException('down'));
        $this->amqp->setConnected(false);

        [, $body] = $this->health();

        $decoded = json_decode($body, true);
        self::assertSame(['status', 'services'], array_keys($decoded));
        self::assertSame(['mysql', 'redis', 'rabbitmq'], array_keys($decoded['services']));
    }

    #[Test]
    public function the_response_is_a_single_json_document_with_no_trailing_output(): void
    {
        [, $body] = $this->health();

        self::assertSame(trim($body), $body);
        self::assertJson($body);
        self::assertStringEndsWith('}', $body);
    }

    #[Test]
    public function health_returns_nothing(): void
    {
        $controller = $this->controller();
        $result = 'sentinel';

        $this->capture(static function () use ($controller, &$result): void {
            $result = $controller->health();
        });

        self::assertNull($result);
        self::assertSame('void', (string) (new ReflectionMethod(HealthController::class, 'health'))->getReturnType());
    }

    // ------------------------------------------------------------------
    // Probes
    // ------------------------------------------------------------------

    #[Test]
    public function mysql_is_probed_with_select_one_via_query(): void
    {
        $this->health();

        self::assertSame(['SELECT 1'], array_column($this->pdo->executed(), 'sql'));
        self::assertSame([[]], $this->pdo->paramsOf('SELECT 1'));
        self::assertSame([], $this->pdo->transactionEvents());
    }

    #[Test]
    public function redis_is_probed_with_a_single_argument_free_ping_and_nothing_else(): void
    {
        $this->health();

        self::assertSame([['ping', []]], $this->redis->calls());
        self::assertSame([], $this->redis->callsTo('get'));
        self::assertSame([], $this->redis->callsTo('setex'));
        self::assertSame([], $this->redis->callsTo('del'));
    }

    #[Test]
    public function rabbitmq_is_checked_with_exactly_one_is_connected_call_and_no_channel_is_opened(): void
    {
        $connection = $this->createMock(AMQPStreamConnection::class);
        $connection->expects(self::once())->method('isConnected')->willReturn(true);
        $connection->expects(self::never())->method('channel');
        $connection->expects(self::never())->method('close');
        $connection->expects(self::never())->method('reconnect');

        [$status, $body] = $this->health(new HealthController($this->pdo, $this->redis, $connection));

        self::assertSame(200, $status);
        self::assertSame(self::ALL_OK, $body);
    }

    #[Test]
    public function health_does_not_publish_or_write_anything(): void
    {
        $this->health();

        self::assertSame([], $this->amqp->published());
        self::assertSame(0, $this->amqp->channelsOpened());
        self::assertSame([], $this->amqp->declaredQueues());
        self::assertSame(1, count($this->pdo->executed()));
        self::assertNull($this->redis->stored('products:all'));
    }

    #[Test]
    public function the_controller_can_be_called_repeatedly_and_re_probes_each_time(): void
    {
        $controller = $this->controller();

        [, $first] = $this->health($controller);
        $this->pdo->onSql('SELECT 1')->throws(new PDOException('gone'));
        [, $second] = $this->health($controller);

        self::assertSame(self::ALL_OK, $first);
        self::assertSame(
            '{"status":"degraded","services":{"mysql":"error","redis":"connected","rabbitmq":"connected"}}',
            $second
        );
        self::assertCount(2, $this->pdo->executions('SELECT 1'));
        self::assertCount(2, $this->redis->callsTo('ping'));
    }

    #[Test]
    public function constructing_the_controller_performs_no_io(): void
    {
        $connection = $this->createMock(AMQPStreamConnection::class);
        $connection->expects(self::never())->method(self::anything());

        new HealthController($this->pdo, $this->redis, $connection);

        self::assertSame([], $this->pdo->executed());
        self::assertSame([], $this->redis->calls());
    }

    #[Test]
    public function the_constructor_takes_pdo_redis_client_and_amqp_connection_in_that_order(): void
    {
        $parameters = (new ReflectionMethod(HealthController::class, '__construct'))->getParameters();

        self::assertSame(
            [\PDO::class, \Predis\Client::class, AMQPStreamConnection::class],
            array_map(static fn (\ReflectionParameter $p): string => (string) $p->getType(), $parameters)
        );
    }

    // ------------------------------------------------------------------
    // Individual failures
    // ------------------------------------------------------------------

    #[Test]
    public function mysql_failure_reports_error_and_degrades_the_status(): void
    {
        $this->pdo->onSql('SELECT 1')->throws(new PDOException('MySQL server has gone away'));

        [$status, $body] = $this->health();

        self::assertSame(200, $status);
        self::assertSame(
            '{"status":"degraded","services":{"mysql":"error","redis":"connected","rabbitmq":"connected"}}',
            $body
        );
    }

    #[Test]
    public function redis_failure_reports_error_and_degrades_the_status(): void
    {
        $this->redis->failOn('ping', $this->redisFailure());

        [$status, $body] = $this->health();

        self::assertSame(200, $status);
        self::assertSame(
            '{"status":"degraded","services":{"mysql":"connected","redis":"error","rabbitmq":"connected"}}',
            $body
        );
    }

    #[Test]
    public function rabbitmq_reporting_disconnected_is_an_error_but_the_status_stays_ok(): void
    {
        $this->amqp->setConnected(false);

        [$status, $body] = $this->health();

        self::assertSame(200, $status);
        self::assertSame(
            '{"status":"ok","services":{"mysql":"connected","redis":"connected","rabbitmq":"error"}}',
            $body
        );
    }

    #[Test]
    public function rabbitmq_check_throwing_reports_error_and_degrades_the_status(): void
    {
        $this->amqp->failConnectionCheckWith(new AMQPRuntimeException('Broken pipe'));

        [$status, $body] = $this->health();

        self::assertSame(200, $status);
        self::assertSame(
            '{"status":"degraded","services":{"mysql":"connected","redis":"connected","rabbitmq":"error"}}',
            $body
        );
    }

    /** @return array<string, array{0: Throwable}> */
    public static function catchableExceptions(): array
    {
        return [
            'PDOException' => [new PDOException('boom')],
            'RuntimeException' => [new RuntimeException('boom')],
            'InvalidArgumentException' => [new InvalidArgumentException('boom')],
            'base Exception' => [new Exception('boom')],
            'AMQPRuntimeException' => [new AMQPRuntimeException('boom')],
        ];
    }

    #[Test]
    #[DataProvider('catchableExceptions')]
    public function any_exception_from_mysql_is_caught(Throwable $failure): void
    {
        $this->pdo->onSql('SELECT 1')->throws($failure);

        [, $body] = $this->health();

        self::assertSame(
            '{"status":"degraded","services":{"mysql":"error","redis":"connected","rabbitmq":"connected"}}',
            $body
        );
    }

    #[Test]
    #[DataProvider('catchableExceptions')]
    public function any_exception_from_redis_is_caught(Throwable $failure): void
    {
        $this->redis->failOn('ping', $failure);

        [, $body] = $this->health();

        self::assertSame(
            '{"status":"degraded","services":{"mysql":"connected","redis":"error","rabbitmq":"connected"}}',
            $body
        );
    }

    #[Test]
    #[DataProvider('catchableExceptions')]
    public function any_exception_from_rabbitmq_is_caught(Throwable $failure): void
    {
        $this->amqp->failConnectionCheckWith($failure);

        [, $body] = $this->health();

        self::assertSame(
            '{"status":"degraded","services":{"mysql":"connected","redis":"connected","rabbitmq":"error"}}',
            $body
        );
    }

    /** @return array<string, array{0: string}> */
    public static function dependencies(): array
    {
        return ['mysql' => ['mysql'], 'redis' => ['redis'], 'rabbitmq' => ['rabbitmq']];
    }

    #[Test]
    #[DataProvider('dependencies')]
    public function an_error_is_not_swallowed_because_only_exceptions_are_caught(string $dependency): void
    {
        match ($dependency) {
            'mysql' => $this->pdo->onSql('SELECT 1')->throws(new TypeError('not an exception')),
            'redis' => $this->redis->failOn('ping', new Error('not an exception')),
            'rabbitmq' => $this->amqp->failConnectionCheckWith(new Error('not an exception')),
        };

        $this->expectException(Error::class);
        $this->expectExceptionMessage('not an exception');

        $this->health();
    }

    #[Test]
    public function a_failing_mysql_check_does_not_stop_redis_and_rabbitmq_from_being_probed(): void
    {
        $this->pdo->onSql('SELECT 1')->throws(new PDOException('down'));
        $connection = $this->createMock(AMQPStreamConnection::class);
        $connection->expects(self::once())->method('isConnected')->willReturn(true);

        [, $body] = $this->health(new HealthController($this->pdo, $this->redis, $connection));

        self::assertSame(
            '{"status":"degraded","services":{"mysql":"error","redis":"connected","rabbitmq":"connected"}}',
            $body
        );
        self::assertSame([['ping', []]], $this->redis->calls());
    }

    #[Test]
    public function a_failing_redis_check_does_not_stop_rabbitmq_from_being_probed(): void
    {
        $this->redis->failOn('ping', $this->redisFailure());
        $connection = $this->createMock(AMQPStreamConnection::class);
        $connection->expects(self::once())->method('isConnected')->willReturn(false);

        [, $body] = $this->health(new HealthController($this->pdo, $this->redis, $connection));

        self::assertSame(
            '{"status":"degraded","services":{"mysql":"connected","redis":"error","rabbitmq":"error"}}',
            $body
        );
    }

    // ------------------------------------------------------------------
    // Combinations
    // ------------------------------------------------------------------

    /**
     * Every combination of mysql (up/down) x redis (up/down) x rabbitmq (connected/disconnected/throws).
     *
     * @return array<string, array{0: bool, 1: bool, 2: string, 3: string}>
     */
    public static function combinations(): array
    {
        $doc = static fn (string $status, string $mysql, string $redis, string $rabbit): string => '{"status":"'
            . $status . '","services":{"mysql":"' . $mysql . '","redis":"' . $redis . '","rabbitmq":"' . $rabbit . '"}}';

        return [
            'all up' => [true, true, 'connected', $doc('ok', 'connected', 'connected', 'connected')],
            'rabbit disconnected only' => [true, true, 'disconnected', $doc('ok', 'connected', 'connected', 'error')],
            'rabbit throws only' => [true, true, 'throws', $doc('degraded', 'connected', 'connected', 'error')],
            'redis down only' => [true, false, 'connected', $doc('degraded', 'connected', 'error', 'connected')],
            'redis down, rabbit disconnected' => [true, false, 'disconnected', $doc('degraded', 'connected', 'error', 'error')],
            'redis down, rabbit throws' => [true, false, 'throws', $doc('degraded', 'connected', 'error', 'error')],
            'mysql down only' => [false, true, 'connected', $doc('degraded', 'error', 'connected', 'connected')],
            'mysql down, rabbit disconnected' => [false, true, 'disconnected', $doc('degraded', 'error', 'connected', 'error')],
            'mysql down, rabbit throws' => [false, true, 'throws', $doc('degraded', 'error', 'connected', 'error')],
            'mysql and redis down' => [false, false, 'connected', $doc('degraded', 'error', 'error', 'connected')],
            'mysql and redis down, rabbit disconnected' => [false, false, 'disconnected', $doc('degraded', 'error', 'error', 'error')],
            'everything down' => [false, false, 'throws', $doc('degraded', 'error', 'error', 'error')],
        ];
    }

    #[Test]
    #[DataProvider('combinations')]
    public function every_combination_of_service_states_produces_the_expected_document(
        bool $mysqlUp,
        bool $redisUp,
        string $rabbit,
        string $expected
    ): void {
        if (!$mysqlUp) {
            $this->pdo->onSql('SELECT 1')->throws(new PDOException('MySQL down'));
        }
        if (!$redisUp) {
            $this->redis->failOn('ping', $this->redisFailure());
        }
        match ($rabbit) {
            'connected' => $this->amqp->setConnected(true),
            'disconnected' => $this->amqp->setConnected(false),
            'throws' => $this->amqp->failConnectionCheckWith(new AMQPRuntimeException('Broken pipe')),
        };

        [$status, $body] = $this->health();

        self::assertSame(200, $status, 'the controller never changes the HTTP status');
        self::assertSame($expected, $body);
    }

    // ------------------------------------------------------------------
    // Status code
    // ------------------------------------------------------------------

    #[Test]
    #[DataProvider('combinations')]
    public function the_http_status_is_left_untouched_whatever_the_outcome(
        bool $mysqlUp,
        bool $redisUp,
        string $rabbit
    ): void {
        if (!$mysqlUp) {
            $this->pdo->onSql('SELECT 1')->throws(new PDOException('MySQL down'));
        }
        if (!$redisUp) {
            $this->redis->failOn('ping', $this->redisFailure());
        }
        if ($rabbit === 'disconnected') {
            $this->amqp->setConnected(false);
        } elseif ($rabbit === 'throws') {
            $this->amqp->failConnectionCheckWith(new AMQPRuntimeException('Broken pipe'));
        }

        $controller = $this->controller();
        http_response_code(202);
        ob_start();
        try {
            $controller->health();
        } finally {
            ob_end_clean();
        }

        self::assertSame(202, http_response_code(), 'health() must not call http_response_code()');
    }

    #[Test]
    public function a_degraded_report_is_still_served_with_status_200(): void
    {
        $this->pdo->onSql('SELECT 1')->throws(new PDOException('down'));
        $this->redis->failOn('ping', $this->redisFailure());
        $this->amqp->failConnectionCheckWith(new RuntimeException('down'));

        [$status, $body] = $this->health();

        self::assertSame(200, $status);
        self::assertStringStartsWith('{"status":"degraded"', $body);
    }
}
