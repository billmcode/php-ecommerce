<?php

namespace Tests\Doubles;

use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\ScriptedPdo;

final class ScriptedPdoTest extends TestCase
{
    #[Test]
    public function it_satisfies_the_pdo_type_hint(): void
    {
        self::assertInstanceOf(PDO::class, new ScriptedPdo());
    }

    #[Test]
    public function prepared_statements_return_scripted_rows_and_record_bound_params(): void
    {
        $pdo = new ScriptedPdo();
        $pdo->onSql('FROM products WHERE id = ?')->fetchesRow(['id' => 3]);

        $statement = $pdo->prepare('SELECT * FROM products WHERE id = ?');
        $statement->execute([3]);

        self::assertSame(['id' => 3], $statement->fetch());
        self::assertFalse($statement->fetch());
        self::assertSame([['sql' => 'SELECT * FROM products WHERE id = ?', 'params' => [3]]], $pdo->executed());
    }

    #[Test]
    public function query_returns_scripted_rows_for_fetch_all(): void
    {
        $pdo = new ScriptedPdo();
        $pdo->onSql('ORDER BY id')->fetchesRows([['id' => 1], ['id' => 2]]);

        self::assertSame([['id' => 1], ['id' => 2]], $pdo->query('SELECT * FROM products ORDER BY id')->fetchAll());
        self::assertTrue($pdo->wasExecuted('FROM products'));
    }

    #[Test]
    public function unscripted_statements_yield_nothing(): void
    {
        $statement = (new ScriptedPdo())->prepare('DELETE FROM products WHERE id = ?');
        $statement->execute([1]);

        self::assertFalse($statement->fetch());
        self::assertSame([], $statement->fetchAll());
        self::assertSame(0, $statement->rowCount());
    }

    #[Test]
    public function affected_rows_come_from_the_matching_rule(): void
    {
        $pdo = new ScriptedPdo();
        $pdo->onSql('DELETE FROM products')->affects(1);

        $statement = $pdo->prepare('DELETE FROM products WHERE id = ?');
        $statement->execute([1]);

        self::assertSame(1, $statement->rowCount());
    }

    #[Test]
    public function the_most_recent_matching_rule_wins(): void
    {
        $pdo = new ScriptedPdo();
        $pdo->onSql('FROM products')->fetchesRow(['n' => 'general']);
        $pdo->onSql('FROM products WHERE id = ? FOR UPDATE')->fetchesRow(['n' => 'specific']);

        $statement = $pdo->prepare('SELECT * FROM products WHERE id = ? FOR UPDATE');
        $statement->execute([1]);

        self::assertSame(['n' => 'specific'], $statement->fetch());
    }

    #[Test]
    public function rules_can_be_restricted_to_bound_params_or_computed_from_them(): void
    {
        $pdo = new ScriptedPdo();
        $pdo->onSql('SELECT')->fetchesRow(static fn (array $params) => $params[0] === 1 ? ['id' => 1] : false);
        $pdo->onSql('INSERT')->withParams([2])->insertsId(20);

        $found = $pdo->prepare('SELECT 1');
        $found->execute([1]);
        $missing = $pdo->prepare('SELECT 1');
        $missing->execute([5]);
        $pdo->prepare('INSERT INTO t VALUES (?)')->execute([1]);
        self::assertSame('0', $pdo->lastInsertId());
        $pdo->prepare('INSERT INTO t VALUES (?)')->execute(['2']);

        self::assertSame(['id' => 1], $found->fetch());
        self::assertFalse($missing->fetch());
        self::assertSame('20', $pdo->lastInsertId());
    }

    #[Test]
    public function sql_is_matched_and_recorded_with_normalised_whitespace(): void
    {
        $pdo = new ScriptedPdo();
        $pdo->onSqlMatching('/^SELECT oi\.\* FROM order_items oi JOIN products/')->fetchesRows([['id' => 1]]);

        $statement = $pdo->prepare("SELECT oi.* FROM order_items oi\n     JOIN products p ON p.id = oi.product_id");
        $statement->execute();

        self::assertSame([['id' => 1]], $statement->fetchAll());
        self::assertSame(
            [[]],
            $pdo->paramsOf("FROM order_items oi\n JOIN products")
        );
    }

    #[Test]
    public function rules_can_throw_on_execution_and_the_attempt_is_still_recorded(): void
    {
        $pdo = new ScriptedPdo();
        $pdo->onSql('DELETE')->throws(new RuntimeException('boom'));
        $statement = $pdo->prepare('DELETE FROM products WHERE id = ?');

        try {
            $statement->execute([9]);
            self::fail('Expected exception');
        } catch (RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }

        self::assertSame([[9]], $pdo->paramsOf('DELETE FROM products'));
    }

    #[Test]
    public function sql_state_exceptions_carry_the_state_as_a_string_code(): void
    {
        $exception = ScriptedPdo::sqlStateException('23000', 'Integrity constraint violation');

        self::assertInstanceOf(PDOException::class, $exception);
        self::assertSame('23000', $exception->getCode());
        self::assertSame('Integrity constraint violation', $exception->getMessage());
    }

    #[Test]
    public function transactions_are_recorded_in_order(): void
    {
        $pdo = new ScriptedPdo();
        self::assertFalse($pdo->committed());
        self::assertFalse($pdo->rolledBack());

        $pdo->beginTransaction();
        $pdo->rollBack();

        self::assertSame(['begin', 'rollback'], $pdo->transactionEvents());
        self::assertTrue($pdo->rolledBack());
        self::assertFalse($pdo->committed());
    }

    #[Test]
    public function commit_can_be_made_to_fail(): void
    {
        $pdo = new ScriptedPdo();
        $pdo->failCommitWith(new RuntimeException('commit failed'));
        $pdo->beginTransaction();

        try {
            $pdo->commit();
            self::fail('Expected exception');
        } catch (RuntimeException) {
        }

        self::assertSame(['begin', 'commit'], $pdo->transactionEvents());
    }
}
