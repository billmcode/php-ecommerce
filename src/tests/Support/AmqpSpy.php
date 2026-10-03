<?php

namespace Tests\Support;

use Closure;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PHPUnit\Framework\MockObject\MockObject;
use Throwable;

/**
 * Wires PHPUnit mocks of AMQPStreamConnection / AMQPChannel to record what
 * App\Queue::publish does, and to simulate connection or publish failures.
 */
final class AmqpSpy
{
    /** @var list<array{0: mixed, 1: mixed, 2: mixed, 3: mixed, 4: mixed}> */
    private array $declaredQueues = [];

    /** @var list<array{queue: string, exchange: string, body: string, payload: mixed, properties: array}> */
    private array $published = [];

    private int $channelsOpened = 0;
    private int $channelsClosed = 0;
    private bool $connected = true;
    private ?Throwable $connectionCheckFailure = null;
    private ?Throwable $channelFailure = null;
    private ?Throwable $publishFailure = null;
    private ?Closure $onPublish = null;

    /**
     * @param AMQPStreamConnection&MockObject $connection
     * @param AMQPChannel&MockObject          $channel
     */
    public function __construct(private readonly AMQPStreamConnection $connection, AMQPChannel $channel)
    {
        $connection->method('isConnected')->willReturnCallback(function (): bool {
            if ($this->connectionCheckFailure !== null) {
                throw $this->connectionCheckFailure;
            }

            return $this->connected;
        });

        $connection->method('channel')->willReturnCallback(function () use ($channel): AMQPChannel {
            if ($this->channelFailure !== null) {
                throw $this->channelFailure;
            }
            $this->channelsOpened++;

            return $channel;
        });

        $channel->method('queue_declare')->willReturnCallback(function (...$arguments) {
            $this->declaredQueues[] = array_slice($arguments, 0, 5);

            return null;
        });

        $channel->method('basic_publish')->willReturnCallback(
            function (AMQPMessage $message, string $exchange = '', string $routingKey = ''): void {
                if ($this->onPublish !== null) {
                    ($this->onPublish)();
                }
                if ($this->publishFailure !== null) {
                    throw $this->publishFailure;
                }
                $this->published[] = [
                    'queue' => $routingKey,
                    'exchange' => $exchange,
                    'body' => $message->getBody(),
                    'payload' => json_decode($message->getBody(), true),
                    'properties' => $message->get_properties(),
                ];
            }
        );

        $channel->method('close')->willReturnCallback(function (): void {
            $this->channelsClosed++;
        });
    }

    public function connection(): AMQPStreamConnection
    {
        return $this->connection;
    }

    public function setConnected(bool $connected): self
    {
        $this->connected = $connected;

        return $this;
    }

    public function failConnectionCheckWith(Throwable $failure): self
    {
        $this->connectionCheckFailure = $failure;

        return $this;
    }

    public function failChannelWith(Throwable $failure): self
    {
        $this->channelFailure = $failure;

        return $this;
    }

    public function failPublishWith(Throwable $failure): self
    {
        $this->publishFailure = $failure;

        return $this;
    }

    /** Runs $callback at the moment basic_publish is invoked, before it records or fails. */
    public function beforePublish(callable $callback): self
    {
        $this->onPublish = Closure::fromCallable($callback);

        return $this;
    }

    /** @return list<array{queue: string, exchange: string, body: string, payload: mixed, properties: array}> */
    public function published(): array
    {
        return $this->published;
    }

    /** @return list<array{0: mixed, 1: mixed, 2: mixed, 3: mixed, 4: mixed}> */
    public function declaredQueues(): array
    {
        return $this->declaredQueues;
    }

    public function channelsOpened(): int
    {
        return $this->channelsOpened;
    }

    public function channelsClosed(): int
    {
        return $this->channelsClosed;
    }
}
