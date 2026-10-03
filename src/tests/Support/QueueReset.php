<?php

namespace Tests\Support;

use App\Queue;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use ReflectionProperty;

/** Swaps the private static App\Queue::$instance so Queue::publish never opens a real connection. */
final class QueueReset
{
    public static function install(?AMQPStreamConnection $connection): void
    {
        (new ReflectionProperty(Queue::class, 'instance'))->setValue(null, $connection);
    }

    public static function reset(): void
    {
        self::install(null);
    }

    public static function current(): ?AMQPStreamConnection
    {
        return (new ReflectionProperty(Queue::class, 'instance'))->getValue();
    }
}
