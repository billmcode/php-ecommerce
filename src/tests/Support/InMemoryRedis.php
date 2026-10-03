<?php

namespace Tests\Support;

use Predis\Client;

final class InMemoryRedis extends Client
{
    /** @var array<string,string> */
    public array $store = [];
    /** @var array<string,int> */
    public array $ttls = [];
    /** @var list<array{0:string,1:array}> */
    public array $calls = [];
    public ?\Throwable $pingException = null;

    // Intentionally does not call parent::__construct(): no connection is ever opened.
    public function __construct()
    {
    }

    public function seed(string $key, string $value): void
    {
        $this->store[$key] = $value;
    }

    public function get($key)
    {
        $this->calls[] = ['get', [$key]];
        return $this->store[$key] ?? null;
    }

    public function setex($key, $seconds, $value)
    {
        $this->calls[] = ['setex', [$key, $seconds, $value]];
        $this->store[$key] = $value;
        $this->ttls[$key] = $seconds;
        return 'OK';
    }

    public function del(...$keys)
    {
        $this->calls[] = ['del', $keys];
        $removed = 0;
        foreach ($keys as $key) {
            if (array_key_exists($key, $this->store)) {
                unset($this->store[$key], $this->ttls[$key]);
                $removed++;
            }
        }
        return $removed;
    }

    public function ping($message = null)
    {
        $this->calls[] = ['ping', $message === null ? [] : [$message]];
        if ($this->pingException !== null) {
            throw $this->pingException;
        }
        return 'PONG';
    }
}
