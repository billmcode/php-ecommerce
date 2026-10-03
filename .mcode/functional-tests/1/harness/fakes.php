<?php
/**
 * Functional-test harness stand-ins for the services that cannot run on this
 * sandbox (no Docker): a stateful PDO that emulates exactly the SQL statements
 * the application issues, and a recording AMQP connection/channel.
 *
 * State lives in a JSON file (FT_STATE_FILE) so it survives across the
 * one-process-per-request lifecycle of `php -S`. The pytest side seeds it,
 * flips failure switches in it, and inspects it.
 */

namespace FtHarness;

final class State
{
    public static array $d = [];
    public static string $file = '';

    public static function load(string $file): void
    {
        self::$file = $file;
        self::$d = json_decode((string) file_get_contents($file), true);
    }

    public static function save(): void
    {
        file_put_contents(self::$file, json_encode(self::$d, JSON_PRETTY_PRINT), LOCK_EX);
    }

    public static function cfg(string $key, $default = false)
    {
        return self::$d['config'][$key] ?? $default;
    }
}

function money($v): string
{
    return number_format((float) $v, 2, '.', '');
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function pdoException(string $msg, string $sqlstate): \PDOException
{
    $e = new \PDOException($msg);
    $prop = new \ReflectionProperty(\Exception::class, 'code');
    $prop->setAccessible(true);
    $prop->setValue($e, $sqlstate);
    $e->errorInfo = [$sqlstate, 1451, $msg];
    return $e;
}

final class FtStmt extends \PDOStatement
{
    private array $rows = [];
    private int $affected = 0;
    private int $cursor = 0;

    public function __construct(private FtPdo $pdo, private string $sql)
    {
    }

    public function load(array $rows): void
    {
        $this->rows = $rows;
        $this->cursor = 0;
    }

    public function execute(?array $params = null): bool
    {
        $this->pdo->runSql($this->sql, $params ?? [], $this);
        return true;
    }

    public function setAffected(int $n): void
    {
        $this->affected = $n;
    }

    public function fetch(int $mode = \PDO::FETCH_DEFAULT, int $cursorOrientation = \PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        if ($this->cursor >= count($this->rows)) {
            return false;
        }
        return $this->rows[$this->cursor++];
    }

    public function fetchAll(int $mode = \PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        $rest = array_slice($this->rows, $this->cursor);
        $this->cursor = count($this->rows);
        return $rest;
    }

    public function rowCount(): int
    {
        return $this->affected;
    }
}

final class FtPdo extends \PDO
{
    private bool $inTx = false;
    private ?array $snapshot = null;
    private int $lastId = 0;

    public function __construct()
    {
        // intentionally does not call parent::__construct (no MySQL available)
    }

    public function rollBackOnShutdown(): void
    {
        if ($this->inTx && $this->snapshot !== null) {
            // an in-flight transaction at process end is rolled back by MySQL
            $this->restore();
            $this->inTx = false;
        }
    }

    private function guard(string $sql): void
    {
        if (State::cfg('db_fail')) {
            throw new \PDOException('FT harness: simulated MySQL outage');
        }
        $needle = State::cfg('fail_sql_contains', '');
        if ($needle !== '' && stripos($sql, $needle) !== false) {
            throw new \PDOException('FT harness: simulated statement failure');
        }
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): \PDOStatement|false
    {
        $this->guard($query);
        $stmt = new FtStmt($this, $query);
        $stmt->execute();
        return $stmt;
    }

    public function prepare(string $query, array $options = []): \PDOStatement|false
    {
        $this->guard($query);
        return new FtStmt($this, $query);
    }

    public function beginTransaction(): bool
    {
        if ($this->inTx) {
            throw new \PDOException('There is already an active transaction');
        }
        $this->inTx = true;
        $this->snapshot = [
            'products' => State::$d['products'],
            'orders' => State::$d['orders'],
            'order_items' => State::$d['order_items'],
        ];
        return true;
    }

    public function commit(): bool
    {
        if (!$this->inTx) {
            throw new \PDOException('There is no active transaction');
        }
        $this->inTx = false;
        $this->snapshot = null;
        return true;
    }

    public function rollBack(): bool
    {
        if (!$this->inTx) {
            throw new \PDOException('There is no active transaction');
        }
        $this->restore();
        $this->inTx = false;
        return true;
    }

    private function restore(): void
    {
        // AUTO_INCREMENT counters are intentionally NOT restored (MySQL semantics)
        foreach (['products', 'orders', 'order_items'] as $t) {
            State::$d[$t] = $this->snapshot[$t];
        }
        $this->snapshot = null;
    }

    public function lastInsertId(?string $name = null): string|false
    {
        return (string) $this->lastId;
    }

    private function nextId(string $table): int
    {
        $id = State::$d['auto'][$table];
        State::$d['auto'][$table] = $id + 1;
        return $this->lastId = $id;
    }

    private static function idMatches(array $row, $value): bool
    {
        // MySQL coerces the bound value numerically; non-numeric -> 0 (no row has id 0)
        return is_numeric($value) && (float) $row['id'] === (float) $value;
    }

    public function runSql(string $rawSql, array $p, FtStmt $stmt): void
    {
        $this->guard($rawSql);
        $sql = preg_replace('/\s+/', ' ', trim($rawSql));
        $d = &State::$d;
        $stmt->load([]);
        $stmt->setAffected(0);

        switch (true) {
            case $sql === 'SELECT 1':
                $stmt->load([['1' => 1]]);
                return;

            // ---- products
            case $sql === 'SELECT * FROM products ORDER BY id':
                $stmt->load($d['products']);
                return;

            case $sql === 'SELECT * FROM products WHERE id = ?':
            case $sql === 'SELECT * FROM products WHERE id = ? FOR UPDATE':
                foreach ($d['products'] as $row) {
                    if (self::idMatches($row, $p[0])) {
                        $stmt->load([$row]);
                        return;
                    }
                }
                return;

            case $sql === 'SELECT id FROM products WHERE id = ?':
                foreach ($d['products'] as $row) {
                    if (self::idMatches($row, $p[0])) {
                        $stmt->load([['id' => $row['id']]]);
                        return;
                    }
                }
                return;

            case $sql === 'INSERT INTO products (name, description, price, stock) VALUES (?, ?, ?, ?)':
                $id = $this->nextId('products');
                $d['products'][] = [
                    'id' => $id,
                    'name' => (string) $p[0],
                    'description' => $p[1],
                    'price' => money($p[2]),
                    'stock' => (int) $p[3],
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                $stmt->setAffected(1);
                return;

            case $sql === 'UPDATE products SET stock = stock - ? WHERE id = ?':
                foreach ($d['products'] as &$row) {
                    if (self::idMatches($row, $p[1])) {
                        $row['stock'] = (int) $row['stock'] - (int) $p[0];
                        $stmt->setAffected(1);
                    }
                }
                unset($row);
                return;

            case (bool) preg_match('/^UPDATE products SET ((?:(?:name|description|price|stock) = \?(?:, )?)+) WHERE id = \?$/', $sql, $m):
                $cols = array_map(fn($c) => trim(explode('=', $c)[0]), explode(', ', $m[1]));
                $idValue = $p[count($cols)];
                foreach ($d['products'] as &$row) {
                    if (!self::idMatches($row, $idValue)) {
                        continue;
                    }
                    foreach ($cols as $i => $col) {
                        $row[$col] = match ($col) {
                            'price' => money($p[$i]),
                            'stock' => (int) $p[$i],
                            default => (string) $p[$i],
                        };
                    }
                    $row['updated_at'] = now();
                    $stmt->setAffected(1);
                }
                unset($row);
                return;

            case $sql === 'DELETE FROM products WHERE id = ?':
                foreach ($d['order_items'] as $oi) {
                    if (is_numeric($p[0]) && (int) $oi['product_id'] === (int) $p[0]) {
                        throw pdoException(
                            'SQLSTATE[23000]: Integrity constraint violation: 1451 Cannot delete or update a parent row',
                            '23000'
                        );
                    }
                }
                $before = count($d['products']);
                $d['products'] = array_values(array_filter(
                    $d['products'],
                    fn($row) => !self::idMatches($row, $p[0])
                ));
                $stmt->setAffected($before - count($d['products']));
                return;

            // ---- orders
            case $sql === 'SELECT * FROM orders ORDER BY id DESC':
                $stmt->load(array_reverse($d['orders']));
                return;

            case $sql === 'SELECT * FROM orders WHERE id = ?':
                foreach ($d['orders'] as $row) {
                    if (self::idMatches($row, $p[0])) {
                        $stmt->load([$row]);
                        return;
                    }
                }
                return;

            case $sql === 'SELECT oi.*, p.name as product_name FROM order_items oi JOIN products p ON p.id = oi.product_id WHERE oi.order_id = ?':
                $rows = [];
                foreach ($d['order_items'] as $oi) {
                    if ((int) $oi['order_id'] !== (int) $p[0]) {
                        continue;
                    }
                    foreach ($d['products'] as $prod) {
                        if ($prod['id'] === $oi['product_id']) {
                            $rows[] = $oi + ['product_name' => $prod['name']];
                        }
                    }
                }
                $stmt->load($rows);
                return;

            case $sql === 'INSERT INTO orders (total) VALUES (0)':
                $id = $this->nextId('orders');
                $d['orders'][] = [
                    'id' => $id,
                    'status' => 'pending',
                    'total' => '0.00',
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                $stmt->setAffected(1);
                return;

            case $sql === 'INSERT INTO order_items (order_id, product_id, quantity, price) VALUES (?, ?, ?, ?)':
                $id = $this->nextId('order_items');
                $d['order_items'][] = [
                    'id' => $id,
                    'order_id' => (int) $p[0],
                    'product_id' => (int) $p[1],
                    'quantity' => (int) $p[2],
                    'price' => money($p[3]),
                ];
                $stmt->setAffected(1);
                return;

            case $sql === 'UPDATE orders SET total = ? WHERE id = ?':
                foreach ($d['orders'] as &$row) {
                    if (self::idMatches($row, $p[1])) {
                        $row['total'] = money($p[0]);
                        $row['updated_at'] = now();
                        $stmt->setAffected(1);
                    }
                }
                unset($row);
                return;
        }

        throw new \PDOException('FT harness: unsupported SQL: ' . $sql);
    }
}

final class FtChannel
{
    private array $declared = [];

    public function queue_declare(...$args): void
    {
        $this->declared = [
            'queue' => $args[0] ?? null,
            'passive' => $args[1] ?? null,
            'durable' => $args[2] ?? null,
            'exclusive' => $args[3] ?? null,
            'auto_delete' => $args[4] ?? null,
        ];
    }

    public function basic_publish($message, $exchange = '', $routingKey = ''): void
    {
        if (State::cfg('publish_fail')) {
            throw new \Exception('FT harness: simulated publish failure');
        }
        State::$d['published'][] = [
            'exchange' => $exchange,
            'routing_key' => $routingKey,
            'declared' => $this->declared,
            'delivery_mode' => $message->get('delivery_mode'),
            'body' => $message->getBody(),
        ];
    }

    public function close(): void
    {
    }
}

final class FtAmqp extends \PhpAmqpLib\Connection\AMQPStreamConnection
{
    public function __construct()
    {
        // intentionally does not connect (no RabbitMQ available)
    }

    public function channel($channel_id = null)
    {
        return new FtChannel();
    }

    public function isConnected()
    {
        if (State::cfg('rabbit_throws')) {
            throw new \Exception('FT harness: simulated RabbitMQ failure');
        }
        return (bool) State::cfg('rabbit_connected', true);
    }
}
