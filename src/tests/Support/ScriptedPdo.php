<?php

namespace Tests\Support;

use Exception;
use PDO;
use PDOException;
use PDOStatement;
use ReflectionProperty;
use Throwable;

/**
 * PDO double keyed on SQL text. It never connects: the parent constructor is
 * skipped and every method the application uses is overridden.
 *
 * Rules are matched against whitespace-normalised SQL; when several rules
 * match, the most recently registered one wins. Statements without a rule
 * succeed with no rows and zero affected rows.
 */
final class ScriptedPdo extends PDO
{
    /** @var list<ScriptedRule> */
    private array $rules = [];

    /** @var list<array{sql: string, params: list<mixed>}> */
    private array $executed = [];

    /** @var list<string> */
    private array $transactionEvents = [];

    private string $lastInsertId = '0';
    private ?Throwable $commitFailure = null;

    public function __construct()
    {
    }

    public static function sqlStateException(string $sqlState, string $message = 'SQL error'): PDOException
    {
        $exception = new PDOException($message);
        (new ReflectionProperty(Exception::class, 'code'))->setValue($exception, $sqlState);

        return $exception;
    }

    public function onSql(string $fragment): ScriptedRule
    {
        return $this->rules[] = new ScriptedRule(self::normalise($fragment), false);
    }

    public function onSqlMatching(string $pattern): ScriptedRule
    {
        return $this->rules[] = new ScriptedRule($pattern, true);
    }

    public function failCommitWith(Throwable $failure): void
    {
        $this->commitFailure = $failure;
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new ScriptedStatement($this, self::normalise($query));
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $statement = new ScriptedStatement($this, self::normalise($query));
        $statement->execute();

        return $statement;
    }

    public function lastInsertId(?string $name = null): string|false
    {
        return $this->lastInsertId;
    }

    public function beginTransaction(): bool
    {
        $this->transactionEvents[] = 'begin';

        return true;
    }

    public function commit(): bool
    {
        $this->transactionEvents[] = 'commit';
        if ($this->commitFailure !== null) {
            throw $this->commitFailure;
        }

        return true;
    }

    public function rollBack(): bool
    {
        $this->transactionEvents[] = 'rollback';

        return true;
    }

    /** @internal Called by ScriptedStatement. */
    public function recordExecution(string $sql, array $params): ?ScriptedRule
    {
        $this->executed[] = ['sql' => $sql, 'params' => $params];

        foreach (array_reverse($this->rules) as $rule) {
            if (!$rule->matches($sql, $params)) {
                continue;
            }
            if ($rule->failure() !== null) {
                throw $rule->failure();
            }
            if ($rule->insertId() !== null) {
                $this->lastInsertId = (string) $rule->insertId();
            }

            return $rule;
        }

        return null;
    }

    /** @return list<array{sql: string, params: list<mixed>}> */
    public function executed(): array
    {
        return $this->executed;
    }

    /** @return list<array{sql: string, params: list<mixed>}> */
    public function executions(string $fragment): array
    {
        $fragment = self::normalise($fragment);

        return array_values(array_filter(
            $this->executed,
            static fn (array $execution): bool => str_contains($execution['sql'], $fragment)
        ));
    }

    /** @return list<list<mixed>> Bound parameters of every execution containing $fragment. */
    public function paramsOf(string $fragment): array
    {
        return array_map(
            static fn (array $execution): array => $execution['params'],
            $this->executions($fragment)
        );
    }

    public function wasExecuted(string $fragment): bool
    {
        return $this->executions($fragment) !== [];
    }

    /** @return list<string> Subset of 'begin', 'commit', 'rollback' in call order. */
    public function transactionEvents(): array
    {
        return $this->transactionEvents;
    }

    public function committed(): bool
    {
        return in_array('commit', $this->transactionEvents, true);
    }

    public function rolledBack(): bool
    {
        return in_array('rollback', $this->transactionEvents, true);
    }

    private static function normalise(string $sql): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $sql));
    }
}
