<?php

namespace Tests\Doubles;

use BadMethodCallException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Predis\Client;
use RuntimeException;
use Tests\Support\FakePredis;

final class FakePredisTest extends TestCase
{
    #[Test]
    public function it_satisfies_the_predis_client_type_hint(): void
    {
        self::assertInstanceOf(Client::class, new FakePredis());
    }

    #[Test]
    public function get_returns_null_for_unknown_keys_and_seeded_values_otherwise(): void
    {
        $redis = (new FakePredis())->seed('a', '1');

        self::assertSame('1', $redis->get('a'));
        self::assertNull($redis->get('missing'));
    }

    #[Test]
    public function setex_stores_the_value_with_its_ttl(): void
    {
        $redis = new FakePredis();

        $redis->setex('k', 60, 'v');

        self::assertSame('v', $redis->get('k'));
        self::assertSame('v', $redis->stored('k'));
        self::assertSame(60, $redis->ttl('k'));
    }

    #[Test]
    public function del_removes_keys_and_counts_them(): void
    {
        $redis = (new FakePredis())->seed('a', '1')->seed('b', '2');

        self::assertSame(2, $redis->del('a', 'b', 'c'));
        self::assertNull($redis->get('a'));
        self::assertSame(0, $redis->del('a'));
    }

    #[Test]
    public function ping_succeeds_until_told_to_fail(): void
    {
        $redis = new FakePredis();
        self::assertSame('PONG', $redis->ping());

        $redis->failOn('ping', new RuntimeException('down'));

        $this->expectExceptionMessage('down');
        $redis->ping();
    }

    #[Test]
    public function every_call_is_logged_in_order_including_failed_ones(): void
    {
        $redis = new FakePredis();
        $redis->get('a');
        $redis->setex('a', 60, 'v');
        $redis->del('a');
        $redis->failOn('del', new RuntimeException());
        try {
            $redis->del('b');
        } catch (RuntimeException) {
        }

        self::assertSame(
            [['get', ['a']], ['setex', ['a', 60, 'v']], ['del', ['a']], ['del', ['b']]],
            $redis->calls()
        );
        self::assertSame([['a', 60, 'v']], $redis->callsTo('SETEX'));
        self::assertSame([['a'], ['b']], $redis->callsTo('del'));
    }

    #[Test]
    public function seeding_is_not_logged(): void
    {
        self::assertSame([], (new FakePredis())->seed('a', '1')->calls());
    }

    #[Test]
    public function unsupported_commands_fail_loudly(): void
    {
        $this->expectException(BadMethodCallException::class);

        (new FakePredis())->flushall();
    }
}
