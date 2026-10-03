<?php

namespace Tests\Doubles;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\HttpTestCase;
use Tests\Support\PhpInputStreamWrapper;
use Tests\Support\QueueReset;

final class HttpTestCaseTest extends HttpTestCase
{
    #[Test]
    public function set_up_provides_fresh_fakes_and_installs_the_queue_spy(): void
    {
        self::assertSame([], $this->pdo->executed());
        self::assertSame([], $this->redis->calls());
        self::assertSame([], $this->amqp->published());
        self::assertSame($this->amqp->connection(), QueueReset::current());
        self::assertSame(200, http_response_code());
    }

    #[Test]
    public function request_returns_status_and_body_of_the_router(): void
    {
        [$status, $body] = $this->request('GET', '/nope');

        self::assertSame(404, $status);
        self::assertSame('{"error":"Not found"}', $body);
    }

    #[Test]
    public function request_serves_health_through_the_fakes(): void
    {
        [$status, $body] = $this->request('GET', '/health');

        self::assertSame(200, $status);
        self::assertSame(
            ['status' => 'ok', 'services' => ['mysql' => 'connected', 'redis' => 'connected', 'rabbitmq' => 'connected']],
            json_decode($body, true)
        );
    }

    #[Test]
    public function request_sets_the_server_globals(): void
    {
        $seen = null;

        $this->request('PUT', '/api/products/1?x=1', null, function () use (&$seen): void {
            $seen = [$_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']];
        });

        self::assertSame(['PUT', '/api/products/1?x=1'], $seen);
    }

    #[Test]
    #[DataProvider('bodies')]
    public function request_serves_the_body_behind_php_input(string|array|null $body, string $expected): void
    {
        $read = null;

        $this->request('POST', '/x', $body, function () use (&$read): void {
            $read = file_get_contents('php://input');
        });

        self::assertSame($expected, $read);
    }

    /** @return array<string, array{0: string|array<mixed>|null, 1: string}> */
    public static function bodies(): array
    {
        return [
            'raw string' => ['{"a":1}', '{"a":1}'],
            'array is json encoded' => [['name' => 'W', 'price' => 1.5], '{"name":"W","price":1.5}'],
            'no body' => [null, ''],
            'invalid json passes through' => ['not json', 'not json'],
        ];
    }

    #[Test]
    public function a_custom_handler_controls_status_and_output(): void
    {
        [$status, $body] = $this->request('GET', '/x', null, static function (): void {
            http_response_code(201);
            echo 'created';
        });

        self::assertSame([201, 'created'], [$status, $body]);
    }

    #[Test]
    public function capture_does_not_need_server_globals(): void
    {
        [$status, $body] = $this->capture(static function (): void {
            http_response_code(409);
            echo 'x';
        });

        self::assertSame([409, 'x'], [$status, $body]);
    }

    #[Test]
    public function the_php_wrapper_and_status_are_clean_between_requests(): void
    {
        $this->request('GET', '/x', 'body', static function (): void {
            http_response_code(500);
        });

        self::assertFalse(PhpInputStreamWrapper::isInstalled());
        [$status] = $this->request('GET', '/x', null, static function (): void {
        });
        self::assertSame(200, $status);
    }

    #[Test]
    public function output_is_captured_even_when_the_handler_throws(): void
    {
        $level = ob_get_level();

        try {
            $this->request('GET', '/x', null, static function (): void {
                echo 'partial';
                throw new \RuntimeException('boom');
            });
            self::fail('Expected exception');
        } catch (\RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }

        self::assertSame($level, ob_get_level());
        self::assertFalse(PhpInputStreamWrapper::isInstalled());
    }

    #[Test]
    public function tear_down_undoes_everything_a_test_left_behind(): void
    {
        $case = new class ('x') extends HttpTestCase {
            public function up(): void
            {
                $this->setUp();
            }

            public function down(): void
            {
                $this->tearDown();
            }
        };
        $level = ob_get_level();
        $before = $_SERVER['REQUEST_METHOD'] ?? null;

        $case->up();
        self::assertSame($level + 1, ob_get_level());

        ob_start();
        ob_start();
        PhpInputStreamWrapper::install('leaked');
        http_response_code(500);
        $_SERVER['REQUEST_METHOD'] = 'LEAKED';

        $case->down();

        self::assertSame($level, ob_get_level());
        self::assertFalse(PhpInputStreamWrapper::isInstalled());
        self::assertNotSame('leaked', file_get_contents('php://input'));
        self::assertNull(QueueReset::current());
        self::assertSame(200, http_response_code());
        self::assertSame($before, $_SERVER['REQUEST_METHOD'] ?? null);
    }
}
