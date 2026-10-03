<?php

namespace Tests\Support;

use App\Queue;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

abstract class ControllerTestCase extends TestCase
{
    protected InMemoryRedis $redis;
    private int $obLevel = 0;
    private array $serverBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->serverBackup = [
            'REQUEST_METHOD' => $_SERVER['REQUEST_METHOD'] ?? null,
            'REQUEST_URI' => $_SERVER['REQUEST_URI'] ?? null,
        ];
        unset($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
        http_response_code(200);
        $this->redis = new InMemoryRedis();
        $this->obLevel = ob_get_level();
        ob_start();
    }

    protected function tearDown(): void
    {
        while (ob_get_level() > $this->obLevel) {
            ob_end_clean();
        }
        http_response_code(200);
        $this->setQueueInstance(null);
        foreach ($this->serverBackup as $key => $value) {
            if ($value === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $value;
            }
        }
        parent::tearDown();
    }

    // Returns buffered output and clears the buffer so repeated calls only see new output.
    protected function output(): string
    {
        $out = (string) ob_get_contents();
        ob_clean();
        return $out;
    }

    protected function status(): int
    {
        return http_response_code() ?: 200;
    }

    protected function json(): mixed
    {
        return json_decode($this->output(), true);
    }

    protected function mockPdo(): \PDO&MockObject
    {
        return $this->createMock(\PDO::class);
    }

    protected function mockStatement(array $rows = [], mixed $row = false, int $rowCount = 0): \PDOStatement&MockObject
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchAll')->willReturn($rows);
        $stmt->method('fetch')->willReturn($row);
        $stmt->method('rowCount')->willReturn($rowCount);
        return $stmt;
    }

    protected function pdoException(string $sqlState, string $message = 'SQLSTATE error'): \PDOException
    {
        $e = new \PDOException($message);
        (new \ReflectionProperty(\Exception::class, 'code'))->setValue($e, $sqlState);
        return $e;
    }

    /** @return array{0: \PhpAmqpLib\Connection\AMQPStreamConnection&MockObject, 1: \PhpAmqpLib\Channel\AMQPChannel&MockObject} */
    protected function useMockQueueConnection(): array
    {
        $channel = $this->createMock(\PhpAmqpLib\Channel\AMQPChannel::class);
        $connection = $this->createMock(\PhpAmqpLib\Connection\AMQPStreamConnection::class);
        $connection->method('channel')->willReturn($channel);
        $this->setQueueInstance($connection);
        return [$connection, $channel];
    }

    private function setQueueInstance(?object $connection): void
    {
        (new \ReflectionProperty(Queue::class, 'instance'))->setValue(null, $connection);
    }
}
