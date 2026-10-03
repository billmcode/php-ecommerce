<?php

namespace Tests\Support;

use PDO;
use PDOStatement;

final class ScriptedStatement extends PDOStatement
{
    /** @var list<array<string, mixed>> */
    private array $rows = [];
    private int $affectedRows = 0;

    public function __construct(private readonly ScriptedPdo $pdo, private readonly string $sql)
    {
    }

    public function execute(?array $params = null): bool
    {
        $rule = $this->pdo->recordExecution($this->sql, array_values($params ?? []));

        $this->rows = $rule?->rowsFor(array_values($params ?? [])) ?? [];
        $this->affectedRows = $rule?->affectedRows() ?? 0;

        return true;
    }

    public function fetch(
        int $mode = PDO::FETCH_DEFAULT,
        int $cursorOrientation = PDO::FETCH_ORI_NEXT,
        int $cursorOffset = 0
    ): mixed {
        $row = array_shift($this->rows);

        return $row ?? false;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        $rows = $this->rows;
        $this->rows = [];

        return $rows;
    }

    public function rowCount(): int
    {
        return $this->affectedRows;
    }
}
