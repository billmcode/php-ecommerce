<?php

namespace Tests\Support;

use App\Router;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PHPUnit\Framework\TestCase;

/**
 * Base class for tests that drive the application through
 * `new Router($pdo, $redis, $amqp)->handleRequest()` with all collaborators faked.
 */
abstract class HttpTestCase extends TestCase
{
    protected ScriptedPdo $pdo;
    protected FakePredis $redis;
    protected AmqpSpy $amqp;

    private int $outputBufferLevel;

    /** @var array<string, mixed> */
    private array $serverBackup;

    protected function setUp(): void
    {
        parent::setUp();

        $this->outputBufferLevel = ob_get_level();
        ob_start();
        http_response_code(200);

        $this->serverBackup = array_intersect_key($_SERVER, array_flip(['REQUEST_METHOD', 'REQUEST_URI']));
        unset($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);

        $this->pdo = new ScriptedPdo();
        $this->redis = new FakePredis();
        $this->amqp = new AmqpSpy(
            $this->createMock(AMQPStreamConnection::class),
            $this->createMock(AMQPChannel::class)
        );
        QueueReset::install($this->amqp->connection());
    }

    protected function tearDown(): void
    {
        PhpInputStreamWrapper::restore();
        QueueReset::reset();

        while (ob_get_level() > $this->outputBufferLevel) {
            ob_end_clean();
        }

        http_response_code(200);
        unset($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
        $_SERVER = array_merge($_SERVER, $this->serverBackup);

        parent::tearDown();
    }

    /**
     * Sets the request globals and runs $handler (default: a fresh Router built
     * from the fakes, then handleRequest()) with the given body behind php://input.
     *
     * @param string|array<mixed>|null $body    Raw request body, or data to JSON-encode.
     * @param (callable(): mixed)|null $handler Replaces the Router, e.g. a controller action.
     *
     * @return array{0: int, 1: string} HTTP status code and response body.
     */
    protected function request(
        string $method,
        string $uri,
        string|array|null $body = null,
        ?callable $handler = null
    ): array {
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI'] = $uri;

        return $this->capture($handler ?? $this->routerHandler(), $body);
    }

    /**
     * Runs $handler with output buffered and the body served behind php://input.
     *
     * @param callable(): mixed        $handler
     * @param string|array<mixed>|null $body
     *
     * @return array{0: int, 1: string} HTTP status code and response body.
     */
    protected function capture(callable $handler, string|array|null $body = null): array
    {
        http_response_code(200);
        PhpInputStreamWrapper::install(is_array($body) ? json_encode($body) : (string) $body);
        ob_start();
        try {
            $handler();
        } finally {
            $output = (string) ob_get_clean();
            PhpInputStreamWrapper::restore();
        }

        return [(int) http_response_code(), $output];
    }

    /** @return callable(): void */
    protected function routerHandler(): callable
    {
        $router = new Router($this->pdo, $this->redis, $this->amqp->connection());

        return static function () use ($router): void {
            $router->handleRequest();
        };
    }
}
