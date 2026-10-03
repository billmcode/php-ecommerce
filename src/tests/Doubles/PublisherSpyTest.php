<?php

namespace Tests\Doubles;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\PublisherSpy;

final class PublisherSpyTest extends TestCase
{
    #[Test]
    public function it_records_queue_and_payload_of_every_call(): void
    {
        $spy = new PublisherSpy();

        $spy('order_created', ['order_id' => 1]);
        $spy('other', []);

        self::assertSame([['order_created', ['order_id' => 1]], ['other', []]], $spy->calls());
    }

    #[Test]
    public function it_can_be_used_as_a_callable(): void
    {
        $spy = new PublisherSpy();

        self::assertIsCallable($spy);
    }

    #[Test]
    public function it_records_the_call_before_throwing(): void
    {
        $spy = (new PublisherSpy())->failWith(new RuntimeException('broker down'));

        try {
            $spy('order_created', ['order_id' => 1]);
            self::fail('Expected exception');
        } catch (RuntimeException $e) {
            self::assertSame('broker down', $e->getMessage());
        }

        self::assertSame([['order_created', ['order_id' => 1]]], $spy->calls());
    }
}
