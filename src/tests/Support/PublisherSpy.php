<?php

namespace Tests\Support;

use Throwable;

/** Callable stand-in for a queue publisher: records (queueName, payload) pairs. */
final class PublisherSpy
{
    /** @var list<array{0: string, 1: array}> */
    private array $calls = [];
    private ?Throwable $failure = null;

    public function __invoke(string $queueName, array $payload): void
    {
        $this->calls[] = [$queueName, $payload];

        if ($this->failure !== null) {
            throw $this->failure;
        }
    }

    public function failWith(Throwable $failure): self
    {
        $this->failure = $failure;

        return $this;
    }

    /** @return list<array{0: string, 1: array}> */
    public function calls(): array
    {
        return $this->calls;
    }
}
