<?php

namespace Tests\Doubles;

use App\Queue;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exception\AMQPRuntimeException;
use PhpAmqpLib\Message\AMQPMessage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\AmqpSpy;
use Tests\Support\QueueReset;

final class AmqpSpyTest extends TestCase
{
    private AmqpSpy $spy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->spy = new AmqpSpy(
            $this->createMock(AMQPStreamConnection::class),
            $this->createMock(AMQPChannel::class)
        );
        QueueReset::install($this->spy->connection());
    }

    protected function tearDown(): void
    {
        QueueReset::reset();
        parent::tearDown();
    }

    #[Test]
    public function queue_publish_is_recorded_without_a_real_connection(): void
    {
        Queue::publish('order_created', ['order_id' => 7, 'total' => 12.5]);

        $published = $this->spy->published();
        self::assertCount(1, $published);
        self::assertSame('order_created', $published[0]['queue']);
        self::assertSame('', $published[0]['exchange']);
        self::assertSame('{"order_id":7,"total":12.5}', $published[0]['body']);
        self::assertSame(['order_id' => 7, 'total' => 12.5], $published[0]['payload']);
        self::assertSame(AMQPMessage::DELIVERY_MODE_PERSISTENT, $published[0]['properties']['delivery_mode']);
    }

    #[Test]
    public function the_queue_is_declared_durable_and_the_channel_closed(): void
    {
        Queue::publish('order_created', []);

        self::assertSame([['order_created', false, true, false, false]], $this->spy->declaredQueues());
        self::assertSame(1, $this->spy->channelsOpened());
        self::assertSame(1, $this->spy->channelsClosed());
    }

    #[Test]
    public function every_publish_is_recorded_in_order(): void
    {
        Queue::publish('a', ['n' => 1]);
        Queue::publish('b', ['n' => 2]);

        self::assertSame(['a', 'b'], array_column($this->spy->published(), 'queue'));
    }

    #[Test]
    public function the_connection_reports_connected_until_told_otherwise(): void
    {
        self::assertTrue($this->spy->connection()->isConnected());

        $this->spy->setConnected(false);

        self::assertFalse($this->spy->connection()->isConnected());
    }

    #[Test]
    public function the_connection_check_can_throw(): void
    {
        $this->spy->failConnectionCheckWith(new AMQPRuntimeException('socket closed'));

        $this->expectExceptionMessage('socket closed');
        $this->spy->connection()->isConnected();
    }

    #[Test]
    public function opening_a_channel_can_fail(): void
    {
        $this->spy->failChannelWith(new RuntimeException('no channel'));

        try {
            Queue::publish('order_created', []);
            self::fail('Expected exception');
        } catch (RuntimeException $e) {
            self::assertSame('no channel', $e->getMessage());
        }

        self::assertSame([], $this->spy->published());
        self::assertSame(0, $this->spy->channelsOpened());
    }

    #[Test]
    public function publishing_can_fail_and_nothing_is_recorded_as_published(): void
    {
        $this->spy->failPublishWith(new RuntimeException('broker down'));

        try {
            Queue::publish('order_created', []);
            self::fail('Expected exception');
        } catch (RuntimeException $e) {
            self::assertSame('broker down', $e->getMessage());
        }

        self::assertSame([], $this->spy->published());
        self::assertCount(1, $this->spy->declaredQueues());
        self::assertSame(0, $this->spy->channelsClosed());
    }

    #[Test]
    public function a_hook_runs_at_the_moment_of_publishing(): void
    {
        $events = [];
        $this->spy->beforePublish(static function () use (&$events): void {
            $events[] = 'publishing';
        });

        Queue::publish('order_created', []);
        $events[] = 'returned';

        self::assertSame(['publishing', 'returned'], $events);
    }
}
