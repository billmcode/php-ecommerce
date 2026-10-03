<?php

namespace Tests\Doubles;

use App\Queue;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\QueueReset;

final class QueueResetTest extends TestCase
{
    protected function tearDown(): void
    {
        QueueReset::reset();
        parent::tearDown();
    }

    #[Test]
    public function the_queue_singleton_starts_out_empty(): void
    {
        self::assertNull(QueueReset::current());
    }

    #[Test]
    public function install_makes_queue_connect_return_the_given_connection(): void
    {
        $connection = $this->createMock(AMQPStreamConnection::class);

        QueueReset::install($connection);

        self::assertSame($connection, QueueReset::current());
        self::assertSame($connection, Queue::connect());
    }

    #[Test]
    public function reset_clears_the_singleton_again(): void
    {
        QueueReset::install($this->createMock(AMQPStreamConnection::class));

        QueueReset::reset();

        self::assertNull(QueueReset::current());
    }
}
