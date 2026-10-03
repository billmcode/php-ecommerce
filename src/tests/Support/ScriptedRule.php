<?php

namespace Tests\Support;

use Closure;
use Throwable;

/** Canned outcome for every statement whose SQL matches. */
final class ScriptedRule
{
    /** @var list<mixed>|null */
    private ?array $params = null;

    /** @var list<array<string, mixed>>|callable */
    private $rows = [];

    private int $affectedRows = 0;
    private string|int|null $insertId = null;
    private ?Throwable $failure = null;

    public function __construct(private readonly string $needle, private readonly bool $isRegex)
    {
    }

    /** Only match executions bound with exactly these parameters (loose comparison). */
    public function withParams(array $params): self
    {
        $this->params = array_values($params);

        return $this;
    }

    /**
     * @param array<string, mixed>|false|callable(list<mixed>): (array<string, mixed>|false) $row
     */
    public function fetchesRow(array|false|Closure $row): self
    {
        if ($row instanceof Closure) {
            $this->rows = static function (array $params) use ($row): array {
                $resolved = $row($params);

                return $resolved === false ? [] : [$resolved];
            };
        } else {
            $this->rows = $row === false ? [] : [$row];
        }

        return $this;
    }

    /**
     * @param list<array<string, mixed>>|Closure(list<mixed>): list<array<string, mixed>> $rows
     */
    public function fetchesRows(array|Closure $rows): self
    {
        $this->rows = $rows;

        return $this;
    }

    public function affects(int $rowCount): self
    {
        $this->affectedRows = $rowCount;

        return $this;
    }

    public function insertsId(int|string $id): self
    {
        $this->insertId = $id;

        return $this;
    }

    public function throws(Throwable $failure): self
    {
        $this->failure = $failure;

        return $this;
    }

    public function matches(string $sql, array $params): bool
    {
        $sqlMatches = $this->isRegex
            ? preg_match($this->needle, $sql) === 1
            : str_contains($sql, $this->needle);

        return $sqlMatches && ($this->params === null || $this->params == $params);
    }

    /** @return list<array<string, mixed>> */
    public function rowsFor(array $params): array
    {
        return is_callable($this->rows) ? ($this->rows)($params) : $this->rows;
    }

    public function affectedRows(): int
    {
        return $this->affectedRows;
    }

    public function insertId(): string|int|null
    {
        return $this->insertId;
    }

    public function failure(): ?Throwable
    {
        return $this->failure;
    }
}
