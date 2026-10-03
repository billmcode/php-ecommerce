<?php

namespace Tests\Support;

use BadMethodCallException;
use Predis\Client;
use Throwable;

/**
 * In-memory Predis client. The parent constructor is never called, so no
 * connection object exists and nothing can ever reach a Redis server.
 */
final class FakePredis extends Client
{
    /** @var array<string, string> */
    private array $store = [];

    /** @var array<string, int> */
    private array $ttls = [];

    /** @var list<array{0: string, 1: list<mixed>}> */
    private array $calls = [];

    /** @var array<string, Throwable> */
    private array $failures = [];

    public function __construct()
    {
    }

    public function __call($commandID, $arguments)
    {
        $command = strtolower($commandID);
        $this->calls[] = [$command, array_values($arguments)];

        if (isset($this->failures[$command])) {
            throw $this->failures[$command];
        }

        switch ($command) {
            case 'get':
                return $this->store[$arguments[0]] ?? null;
            case 'setex':
                [$key, $ttl, $value] = $arguments;
                $this->store[$key] = (string) $value;
                $this->ttls[$key] = (int) $ttl;

                return 'OK';
            case 'del':
                $removed = 0;
                foreach ($arguments as $key) {
                    if (array_key_exists($key, $this->store)) {
                        unset($this->store[$key], $this->ttls[$key]);
                        $removed++;
                    }
                }

                return $removed;
            case 'ping':
                return 'PONG';
        }

        throw new BadMethodCallException("FakePredis does not implement '{$commandID}'");
    }

    public function seed(string $key, string $value): self
    {
        $this->store[$key] = $value;

        return $this;
    }

    public function failOn(string $command, Throwable $failure): self
    {
        $this->failures[strtolower($command)] = $failure;

        return $this;
    }

    public function stored(string $key): ?string
    {
        return $this->store[$key] ?? null;
    }

    public function ttl(string $key): ?int
    {
        return $this->ttls[$key] ?? null;
    }

    /** @return list<array{0: string, 1: list<mixed>}> */
    public function calls(): array
    {
        return $this->calls;
    }

    /** @return list<list<mixed>> Arguments of every call to $command, in order. */
    public function callsTo(string $command): array
    {
        $command = strtolower($command);

        return array_values(array_map(
            static fn (array $call): array => $call[1],
            array_filter($this->calls, static fn (array $call): bool => $call[0] === $command)
        ));
    }
}
