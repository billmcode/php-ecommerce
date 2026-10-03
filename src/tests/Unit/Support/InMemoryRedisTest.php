<?php

namespace Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryRedis;

final class InMemoryRedisTest extends TestCase
{
    public function testGetReturnsNullForMissingKey(): void
    {
        $redis = new InMemoryRedis();
        $this->assertNull($redis->get('missing'));
    }

    public function testSetexStoresValueAndTtl(): void
    {
        $redis = new InMemoryRedis();
        $this->assertSame('OK', $redis->setex('k', 60, 'v'));
        $this->assertSame('v', $redis->get('k'));
        $this->assertSame(['k' => 60], $redis->ttls);
        $this->assertSame(['k' => 'v'], $redis->store);
    }

    public function testDelReturnsRemovedCountAndClearsTtl(): void
    {
        $redis = new InMemoryRedis();
        $redis->setex('a', 60, '1');
        $redis->setex('b', 60, '2');
        $this->assertSame(2, $redis->del('a', 'b', 'c'));
        $this->assertSame([], $redis->store);
        $this->assertSame([], $redis->ttls);
        $this->assertSame(0, $redis->del('a'));
    }

    public function testPingReturnsPong(): void
    {
        $this->assertSame('PONG', (new InMemoryRedis())->ping());
    }

    public function testPingThrowsConfiguredException(): void
    {
        $redis = new InMemoryRedis();
        $redis->pingException = new \RuntimeException('down');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('down');
        $redis->ping();
    }

    public function testCallsAreRecordedInOrder(): void
    {
        $redis = new InMemoryRedis();
        $redis->get('x');
        $redis->setex('x', 60, 'y');
        $redis->del('x', 'z');
        $redis->ping();
        $this->assertSame([
            ['get', ['x']],
            ['setex', ['x', 60, 'y']],
            ['del', ['x', 'z']],
            ['ping', []],
        ], $redis->calls);
    }

    public function testSeedStoresWithoutRecordingCall(): void
    {
        $redis = new InMemoryRedis();
        $redis->seed('s', 'val');
        $this->assertSame([], $redis->calls);
        $this->assertSame('val', $redis->get('s'));
        $this->assertCount(1, $redis->calls);
    }
}
