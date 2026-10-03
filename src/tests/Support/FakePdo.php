<?php

namespace Tests\Support;

use PHPUnit\Framework\MockObject\MockObject;

/**
 * Wires a PDO mock so prepare()/query() return canned statements chosen by SQL
 * substring (longest matching pattern wins) and everything is recorded.
 */
final class FakePdo
{
    /** @var list<string> normalized SQL of every prepare() call */
    public array $prepared = [];
    /** @var list<string> normalized SQL of every query() call */
    public array $queried = [];
    /** @var list<array{sql: string, params: array}> every statement execute() */
    public array $executions = [];
    /** @var list<string> beginTransaction / commit / rollBack in call order */
    public array $events = [];
    /** @var list<string> normalized SQL that matched no rule */
    public array $unmatched = [];

    /** @var array<string, array{rows: array, fetch: mixed, rowCount: int, executeThrows: ?\Throwable, queryThrows: ?\Throwable}> */
    private array $rules = [];
    /** @var list<string> */
    private array $insertIds = [];
    private ?\Throwable $rollBackException = null;
    private bool $rollBackResult = true;

    /**
     * @param \Closure(): \PHPUnit\Framework\MockObject\MockObject $statementFactory returns a fresh PDOStatement mock
     */
    public function __construct(public readonly \PDO&MockObject $pdo, private readonly \Closure $statementFactory)
    {
        $this->wire();
    }

    /**
     * @param array $rows            returned by fetchAll()
     * @param mixed $fetch           returned by fetch(); a Closure receives the execute() params
     */
    public function onSql(
        string $substring,
        array $rows = [],
        mixed $fetch = false,
        int $rowCount = 0,
        ?\Throwable $executeThrows = null,
        ?\Throwable $queryThrows = null,
    ): static {
        $this->rules[self::normalize($substring)] = [
            'rows' => $rows,
            'fetch' => $fetch,
            'rowCount' => $rowCount,
            'executeThrows' => $executeThrows,
            'queryThrows' => $queryThrows,
        ];
        return $this;
    }

    public function withInsertIds(string ...$ids): static
    {
        $this->insertIds = array_values($ids);
        return $this;
    }

    public function rollBackThrows(\Throwable $e): static
    {
        $this->rollBackException = $e;
        return $this;
    }

    public function rollBackReturns(bool $result): static
    {
        $this->rollBackResult = $result;
        return $this;
    }

    /** @return list<array> execute() params of every execution whose SQL contains $substring */
    public function paramsFor(string $substring): array
    {
        $needle = self::normalize($substring);
        $found = [];
        foreach ($this->executions as $execution) {
            if (str_contains($execution['sql'], $needle)) {
                $found[] = $execution['params'];
            }
        }
        return $found;
    }

    public function hasInteractions(): bool
    {
        return $this->prepared !== [] || $this->queried !== [] || $this->executions !== [] || $this->events !== [];
    }

    private static function normalize(string $sql): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $sql));
    }

    private function wire(): void
    {
        $this->pdo->method('prepare')->willReturnCallback(function (string $sql, array $options = []) {
            $sql = self::normalize($sql);
            $this->prepared[] = $sql;
            return $this->statement($sql, false);
        });

        $this->pdo->method('query')->willReturnCallback(function (string $sql, ...$rest) {
            $sql = self::normalize($sql);
            $this->queried[] = $sql;
            return $this->statement($sql, true);
        });

        $this->pdo->method('lastInsertId')->willReturnCallback(function () {
            if (count($this->insertIds) > 1) {
                return array_shift($this->insertIds);
            }
            return $this->insertIds[0] ?? '0';
        });

        $this->pdo->method('beginTransaction')->willReturnCallback(function () {
            $this->events[] = 'beginTransaction';
            return true;
        });

        $this->pdo->method('commit')->willReturnCallback(function () {
            $this->events[] = 'commit';
            return true;
        });

        $this->pdo->method('rollBack')->willReturnCallback(function () {
            $this->events[] = 'rollBack';
            if ($this->rollBackException !== null) {
                throw $this->rollBackException;
            }
            return $this->rollBackResult;
        });
    }

    private function statement(string $sql, bool $isQuery): MockObject
    {
        $rule = $this->match($sql);
        if ($rule === null) {
            $this->unmatched[] = $sql;
            $rule = ['rows' => [], 'fetch' => false, 'rowCount' => 0, 'executeThrows' => null, 'queryThrows' => null];
        }

        if ($isQuery && $rule['queryThrows'] !== null) {
            throw $rule['queryThrows'];
        }

        $state = new \stdClass();
        $state->params = [];

        $stmt = ($this->statementFactory)();
        $stmt->method('execute')->willReturnCallback(function (?array $params = null) use ($sql, $rule, $state) {
            $state->params = $params ?? [];
            $this->executions[] = ['sql' => $sql, 'params' => $state->params];
            if ($rule['executeThrows'] !== null) {
                throw $rule['executeThrows'];
            }
            return true;
        });
        $stmt->method('fetchAll')->willReturn($rule['rows']);
        $stmt->method('fetch')->willReturnCallback(
            fn () => $rule['fetch'] instanceof \Closure ? ($rule['fetch'])($state->params) : $rule['fetch']
        );
        $stmt->method('rowCount')->willReturn($rule['rowCount']);

        return $stmt;
    }

    private function match(string $sql): ?array
    {
        $best = null;
        $bestLength = -1;
        foreach ($this->rules as $pattern => $rule) {
            if (str_contains($sql, $pattern) && strlen($pattern) > $bestLength) {
                $best = $rule;
                $bestLength = strlen($pattern);
            }
        }
        return $best;
    }
}
