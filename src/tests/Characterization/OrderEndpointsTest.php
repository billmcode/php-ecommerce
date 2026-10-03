<?php

namespace Tests\Characterization;

use DateTimeImmutable;
use InvalidArgumentException;
use PDOException;
use PhpAmqpLib\Exception\AMQPRuntimeException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\HttpTestCase;
use Tests\Support\ScriptedPdo;

/**
 * Characterization of the order endpoints (list, get, create) as served by the
 * original App\Router. Only the public contract is used, so the same tests must
 * keep passing once the handlers live in App\Controllers\OrderController.
 */
final class OrderEndpointsTest extends HttpTestCase
{
    private const SQL_INSERT_ORDER = 'INSERT INTO orders (total) VALUES (0)';
    private const SQL_SELECT_PRODUCT = 'SELECT * FROM products WHERE id = ? FOR UPDATE';
    private const SQL_INSERT_ITEM = 'INSERT INTO order_items (order_id, product_id, quantity, price) VALUES (?, ?, ?, ?)';
    private const SQL_UPDATE_STOCK = 'UPDATE products SET stock = stock - ? WHERE id = ?';
    private const SQL_UPDATE_TOTAL = 'UPDATE orders SET total = ? WHERE id = ?';
    private const SQL_SELECT_ORDER = 'SELECT * FROM orders WHERE id = ?';
    private const SQL_SELECT_ITEMS = 'SELECT oi.*, p.name as product_name FROM order_items oi JOIN products p ON p.id = oi.product_id WHERE oi.order_id = ?';

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    /** Scripts what the transaction sees for SELECT ... FOR UPDATE of one product. */
    private function givenProduct(int|string $id, string $name, string|int|float $price, int|string $stock): void
    {
        $this->pdo->onSql(self::SQL_SELECT_PRODUCT)->withParams([$id])->fetchesRow([
            'id' => $id,
            'name' => $name,
            'description' => '',
            'price' => $price,
            'stock' => $stock,
        ]);
    }

    private function givenNewOrderId(int $orderId): void
    {
        $this->pdo->onSql(self::SQL_INSERT_ORDER)->insertsId($orderId);
    }

    /**
     * Scripts the read-back performed by getOrder().
     *
     * @param array<string, mixed>             $order
     * @param list<array<string, mixed>>       $items
     */
    private function givenStoredOrder(int $orderId, array $order, array $items): void
    {
        $this->pdo->onSql(self::SQL_SELECT_ORDER)->withParams([$orderId])->fetchesRow($order);
        $this->pdo->onSql('FROM order_items oi JOIN products p')->withParams([$orderId])->fetchesRows($items);
    }

    /** @return list<string> */
    private function executedSql(): array
    {
        return array_column($this->pdo->executed(), 'sql');
    }

    private function assertNothingExecuted(): void
    {
        self::assertSame([], $this->pdo->executed());
        self::assertSame([], $this->pdo->transactionEvents());
        self::assertSame([], $this->redis->calls());
        self::assertSame([], $this->amqp->published());
        self::assertSame([], $this->amqp->declaredQueues());
        self::assertSame(0, $this->amqp->channelsOpened());
    }

    /** Asserts a rolled-back 400 with no side effect outside the transaction. */
    private function assertRejectedAndRolledBack(int $status, string $body, string $message): void
    {
        self::assertSame(400, $status);
        self::assertSame(json_encode(['error' => $message]), $body);
        self::assertSame(['begin', 'rollback'], $this->pdo->transactionEvents());
        self::assertTrue($this->pdo->rolledBack());
        self::assertFalse($this->pdo->committed());
        self::assertSame([], $this->redis->calls());
        self::assertSame([], $this->amqp->published());
        self::assertSame([], $this->amqp->declaredQueues());
        self::assertSame(0, $this->amqp->channelsOpened());
    }

    /** Asserts a rolled-back 500 with no side effect outside the transaction. */
    private function assertFailedAndRolledBack(int $status, string $body): void
    {
        self::assertSame(500, $status);
        self::assertSame('{"error":"Order creation failed"}', $body);
        self::assertSame(['begin', 'rollback'], $this->pdo->transactionEvents());
        self::assertSame([], $this->redis->calls());
        self::assertSame([], $this->amqp->published());
        self::assertSame(0, $this->amqp->channelsOpened());
    }

    // ------------------------------------------------------------------
    // GET /api/orders
    // ------------------------------------------------------------------

    #[Test]
    public function list_orders_returns_every_order_row_as_json_in_database_order(): void
    {
        $rows = [
            ['id' => 3, 'total' => '59.97', 'created_at' => '2026-01-03 10:00:00'],
            ['id' => 2, 'total' => '10.00', 'created_at' => '2026-01-02 10:00:00'],
            ['id' => 1, 'total' => '0.00', 'created_at' => '2026-01-01 10:00:00'],
        ];
        $this->pdo->onSql('SELECT * FROM orders ORDER BY id DESC')->fetchesRows($rows);

        [$status, $body] = $this->request('GET', '/api/orders');

        self::assertSame(200, $status);
        self::assertSame(json_encode($rows), $body);
    }

    #[Test]
    public function list_orders_runs_a_single_unbound_query_and_ignores_the_cache_and_queue(): void
    {
        $this->pdo->onSql('SELECT * FROM orders ORDER BY id DESC')->fetchesRows([['id' => 1]]);

        $this->request('GET', '/api/orders');

        self::assertSame([['sql' => 'SELECT * FROM orders ORDER BY id DESC', 'params' => []]], $this->pdo->executed());
        self::assertSame([], $this->pdo->transactionEvents());
        self::assertSame([], $this->redis->calls());
        self::assertSame([], $this->amqp->published());
    }

    #[Test]
    public function list_orders_returns_an_empty_json_array_when_there_are_no_orders(): void
    {
        [$status, $body] = $this->request('GET', '/api/orders');

        self::assertSame(200, $status);
        self::assertSame('[]', $body);
    }

    #[Test]
    public function list_orders_does_not_attach_items(): void
    {
        $this->pdo->onSql('SELECT * FROM orders ORDER BY id DESC')->fetchesRows([['id' => 1, 'total' => '5.00']]);

        [, $body] = $this->request('GET', '/api/orders');

        self::assertSame('[{"id":1,"total":"5.00"}]', $body);
        self::assertFalse($this->pdo->wasExecuted('order_items'));
    }

    #[Test]
    #[DataProvider('listUriVariants')]
    public function list_orders_tolerates_trailing_slash_and_query_string(string $uri): void
    {
        $this->pdo->onSql('SELECT * FROM orders ORDER BY id DESC')->fetchesRows([['id' => 1]]);

        [$status, $body] = $this->request('GET', $uri);

        self::assertSame(200, $status);
        self::assertSame('[{"id":1}]', $body);
    }

    /** @return array<string, array{string}> */
    public static function listUriVariants(): array
    {
        return [
            'trailing slash' => ['/api/orders/'],
            'query string' => ['/api/orders?limit=5'],
            'slash and query' => ['/api/orders/?page=2'],
        ];
    }

    // ------------------------------------------------------------------
    // GET /api/orders/{id}
    // ------------------------------------------------------------------

    #[Test]
    public function get_order_returns_the_order_with_its_items_and_product_names(): void
    {
        $order = ['id' => 5, 'total' => '39.98', 'created_at' => '2026-02-01 12:00:00'];
        $items = [
            ['id' => 10, 'order_id' => 5, 'product_id' => 1, 'quantity' => 2, 'price' => '19.99', 'product_name' => 'Widget'],
            ['id' => 11, 'order_id' => 5, 'product_id' => 2, 'quantity' => 1, 'price' => '5.00', 'product_name' => 'Gadget'],
        ];
        $this->givenStoredOrder(5, $order, $items);

        [$status, $body] = $this->request('GET', '/api/orders/5');

        self::assertSame(200, $status);
        self::assertSame(json_encode($order + ['items' => $items]), $body);
    }

    #[Test]
    public function get_order_appends_the_items_key_after_the_order_columns(): void
    {
        $this->givenStoredOrder(5, ['id' => 5, 'total' => '1.00'], [['id' => 1, 'product_name' => 'Widget']]);

        [, $body] = $this->request('GET', '/api/orders/5');

        $decoded = json_decode($body, true);
        self::assertSame(['id', 'total', 'items'], array_keys($decoded));
        self::assertSame([['id' => 1, 'product_name' => 'Widget']], $decoded['items']);
    }

    #[Test]
    public function get_order_queries_the_order_then_the_join_with_the_integer_id_bound(): void
    {
        $this->givenStoredOrder(7, ['id' => 7, 'total' => '1.00'], []);

        $this->request('GET', '/api/orders/7');

        self::assertSame(
            [
                ['sql' => self::SQL_SELECT_ORDER, 'params' => [7]],
                ['sql' => self::SQL_SELECT_ITEMS, 'params' => [7]],
            ],
            $this->pdo->executed()
        );
        self::assertSame([], $this->pdo->transactionEvents());
        self::assertSame([], $this->redis->calls());
    }

    #[Test]
    public function get_order_without_items_returns_an_empty_items_array(): void
    {
        $this->givenStoredOrder(5, ['id' => 5, 'total' => '0.00'], []);

        [$status, $body] = $this->request('GET', '/api/orders/5');

        self::assertSame(200, $status);
        self::assertSame('{"id":5,"total":"0.00","items":[]}', $body);
    }

    #[Test]
    public function get_order_returns_404_when_the_order_does_not_exist(): void
    {
        $this->pdo->onSql(self::SQL_SELECT_ORDER)->fetchesRow(false);

        [$status, $body] = $this->request('GET', '/api/orders/99');

        self::assertSame(404, $status);
        self::assertSame('{"error":"Order not found"}', $body);
        self::assertSame([['sql' => self::SQL_SELECT_ORDER, 'params' => [99]]], $this->pdo->executed());
    }

    #[Test]
    public function get_order_does_not_look_up_items_for_a_missing_order(): void
    {
        $this->request('GET', '/api/orders/99');

        self::assertFalse($this->pdo->wasExecuted('order_items'));
    }

    #[Test]
    #[DataProvider('orderIdUriVariants')]
    public function get_order_casts_the_path_id_to_int_and_ignores_slash_and_query(string $uri, int $expectedId): void
    {
        $this->givenStoredOrder($expectedId, ['id' => $expectedId], []);

        [$status, $body] = $this->request('GET', $uri);

        self::assertSame(200, $status);
        self::assertSame('{"id":' . $expectedId . ',"items":[]}', $body);
        self::assertSame([[$expectedId], [$expectedId]], array_column($this->pdo->executed(), 'params'));
    }

    /** @return array<string, array{string, int}> */
    public static function orderIdUriVariants(): array
    {
        return [
            'plain' => ['/api/orders/12', 12],
            'trailing slash' => ['/api/orders/12/', 12],
            'query string' => ['/api/orders/12?expand=items', 12],
            'leading zeros' => ['/api/orders/007', 7],
            'zero' => ['/api/orders/0', 0],
        ];
    }

    #[Test]
    #[DataProvider('unroutedOrderRequests')]
    public function unrouted_order_requests_fall_through_to_the_generic_404(string $method, string $uri): void
    {
        [$status, $body] = $this->request($method, $uri);

        self::assertSame(404, $status);
        self::assertSame('{"error":"Not found"}', $body);
        self::assertSame([], $this->pdo->executed());
        self::assertSame([], $this->pdo->transactionEvents());
    }

    /** @return array<string, array{string, string}> */
    public static function unroutedOrderRequests(): array
    {
        return [
            'non numeric id' => ['GET', '/api/orders/abc'],
            'negative id' => ['GET', '/api/orders/-1'],
            'nested path' => ['GET', '/api/orders/1/items'],
            'PUT collection' => ['PUT', '/api/orders'],
            'DELETE collection' => ['DELETE', '/api/orders'],
            'PUT item' => ['PUT', '/api/orders/1'],
            'DELETE item' => ['DELETE', '/api/orders/1'],
            'POST item' => ['POST', '/api/orders/1'],
            'lowercase method' => ['get', '/api/orders'],
        ];
    }

    // ------------------------------------------------------------------
    // POST /api/orders : request body validation (before the transaction)
    // ------------------------------------------------------------------

    #[Test]
    #[DataProvider('bodiesWithoutAnItemsArray')]
    public function create_order_rejects_a_body_without_a_non_empty_items_array(string $rawBody): void
    {
        [$status, $body] = $this->request('POST', '/api/orders', $rawBody);

        self::assertSame(400, $status);
        self::assertSame('{"error":"items array is required"}', $body);
        $this->assertNothingExecuted();
    }

    /** @return array<string, array{string}> */
    public static function bodiesWithoutAnItemsArray(): array
    {
        return [
            'empty body' => [''],
            'invalid json' => ['not json'],
            'truncated json' => ['{"items":['],
            'empty object' => ['{}'],
            'empty list' => ['[]'],
            'json null' => ['null'],
            'json string' => ['"items"'],
            'json number' => ['42'],
            'items missing' => ['{"other":[{"product_id":1,"quantity":1}]}'],
            'items null' => ['{"items":null}'],
            'items empty array' => ['{"items":[]}'],
            'items empty object' => ['{"items":{}}'],
            'items string' => ['{"items":"abc"}'],
            'items number' => ['{"items":5}'],
            'items true' => ['{"items":true}'],
            'items false' => ['{"items":false}'],
            'bare list of items' => ['[{"product_id":1,"quantity":1}]'],
        ];
    }

    #[Test]
    public function create_order_accepts_items_encoded_as_a_json_object(): void
    {
        $this->givenNewOrderId(42);
        $this->givenProduct(1, 'Widget', '10.00', 5);
        $this->givenStoredOrder(42, ['id' => 42], []);

        [$status] = $this->request('POST', '/api/orders', '{"items":{"a":{"product_id":1,"quantity":1}}}');

        self::assertSame(201, $status);
        self::assertSame([[42, 1, 1, '10.00']], $this->pdo->paramsOf(self::SQL_INSERT_ITEM));
    }

    // ------------------------------------------------------------------
    // POST /api/orders : validation failures inside the transaction
    // ------------------------------------------------------------------

    #[Test]
    #[DataProvider('itemsMissingProductIdOrQuantity')]
    public function create_order_rejects_an_item_missing_product_id_or_quantity(string $rawBody): void
    {
        $this->givenNewOrderId(42);

        [$status, $body] = $this->request('POST', '/api/orders', $rawBody);

        $this->assertRejectedAndRolledBack($status, $body, 'Each item needs product_id and quantity');
        self::assertSame([self::SQL_INSERT_ORDER], $this->executedSql());
    }

    /** @return array<string, array{string}> */
    public static function itemsMissingProductIdOrQuantity(): array
    {
        return [
            'no product_id' => ['{"items":[{"quantity":1}]}'],
            'no quantity' => ['{"items":[{"product_id":1}]}'],
            'null product_id' => ['{"items":[{"product_id":null,"quantity":1}]}'],
            'null quantity' => ['{"items":[{"product_id":1,"quantity":null}]}'],
            'empty item' => ['{"items":[{}]}'],
            'empty array item' => ['{"items":[[]]}'],
            'number item' => ['{"items":[5]}'],
            'string item' => ['{"items":["abc"]}'],
            'null item' => ['{"items":[null]}'],
            'wrong key names' => ['{"items":[{"id":1,"qty":1}]}'],
        ];
    }

    #[Test]
    public function create_order_begins_the_transaction_and_inserts_the_order_before_validating_items(): void
    {
        $this->givenNewOrderId(42);

        $this->request('POST', '/api/orders', '{"items":[{"quantity":1}]}');

        self::assertSame(['begin', 'rollback'], $this->pdo->transactionEvents());
        self::assertSame([['sql' => self::SQL_INSERT_ORDER, 'params' => []]], $this->pdo->executed());
    }

    #[Test]
    #[DataProvider('quantitiesBelowOne')]
    public function create_order_rejects_a_quantity_below_one(string $quantityJson): void
    {
        $this->givenNewOrderId(42);
        $this->givenProduct(1, 'Widget', '10.00', 100);

        [$status, $body] = $this->request('POST', '/api/orders', '{"items":[{"product_id":1,"quantity":' . $quantityJson . '}]}');

        $this->assertRejectedAndRolledBack($status, $body, 'Quantity must be at least 1');
        self::assertFalse($this->pdo->wasExecuted(self::SQL_SELECT_PRODUCT));
        self::assertSame([self::SQL_INSERT_ORDER], $this->executedSql());
    }

    /** @return array<string, array{string}> */
    public static function quantitiesBelowOne(): array
    {
        return [
            'zero' => ['0'],
            'minus one' => ['-1'],
            'string zero' => ['"0"'],
            'string negative' => ['"-3"'],
            'non numeric string' => ['"abc"'],
            'empty string' => ['""'],
            'false' => ['false'],
            'fraction below one' => ['0.5'],
            'string fraction below one' => ['"0.9"'],
        ];
    }

    #[Test]
    #[DataProvider('unknownProductIds')]
    public function create_order_rejects_an_unknown_product_naming_the_id_as_given(string $productIdJson, string $expectedMessage): void
    {
        $this->givenNewOrderId(42);

        [$status, $body] = $this->request('POST', '/api/orders', '{"items":[{"product_id":' . $productIdJson . ',"quantity":1}]}');

        $this->assertRejectedAndRolledBack($status, $body, $expectedMessage);
        self::assertSame([self::SQL_INSERT_ORDER, self::SQL_SELECT_PRODUCT], $this->executedSql());
    }

    /** @return array<string, array{string, string}> */
    public static function unknownProductIds(): array
    {
        return [
            'integer' => ['99', 'Product 99 not found'],
            'numeric string' => ['"99"', 'Product 99 not found'],
            'non numeric string' => ['"abc"', 'Product abc not found'],
            'float' => ['1.5', 'Product 1.5 not found'],
            'zero' => ['0', 'Product 0 not found'],
            'negative' => ['-4', 'Product -4 not found'],
            'true' => ['true', 'Product 1 not found'],
            'false' => ['false', 'Product  not found'],
        ];
    }

    #[Test]
    public function create_order_looks_the_product_up_with_the_product_id_exactly_as_given(): void
    {
        $this->givenNewOrderId(42);

        $this->request('POST', '/api/orders', '{"items":[{"product_id":"7","quantity":1}]}');

        self::assertSame([['7']], $this->pdo->paramsOf(self::SQL_SELECT_PRODUCT));
    }

    #[Test]
    public function create_order_rejects_insufficient_stock_naming_the_product(): void
    {
        $this->givenNewOrderId(42);
        $this->givenProduct(1, 'Widget', '10.00', 2);

        [$status, $body] = $this->request('POST', '/api/orders', '{"items":[{"product_id":1,"quantity":3}]}');

        $this->assertRejectedAndRolledBack($status, $body, 'Insufficient stock for Widget');
        self::assertSame([self::SQL_INSERT_ORDER, self::SQL_SELECT_PRODUCT], $this->executedSql());
    }

    #[Test]
    public function create_order_rejects_an_out_of_stock_product(): void
    {
        $this->givenNewOrderId(42);
        $this->givenProduct(1, 'Widget', '10.00', 0);

        [$status, $body] = $this->request('POST', '/api/orders', '{"items":[{"product_id":1,"quantity":1}]}');

        $this->assertRejectedAndRolledBack($status, $body, 'Insufficient stock for Widget');
    }

    #[Test]
    public function create_order_json_encodes_the_product_name_in_the_error_message(): void
    {
        $this->givenNewOrderId(42);
        $this->givenProduct(1, 'Café "Deluxe"/XL', '10.00', 0);

        [$status, $body] = $this->request('POST', '/api/orders', '{"items":[{"product_id":1,"quantity":1}]}');

        self::assertSame(400, $status);
        // json_encode escapes non-ASCII as a backslash-u sequence, quotes, and forward slashes.
        $escapedAccent = '\\' . 'u00e9';
        self::assertSame('{"error":"Insufficient stock for Caf' . $escapedAccent . ' \"Deluxe\"\/XL"}', $body);
    }

    #[Test]
    #[DataProvider('stockComparisons')]
    public function create_order_compares_stock_to_quantity_with_loose_php_comparison(
        int|string $stock,
        string $quantityJson,
        bool $accepted
    ): void {
        $this->givenNewOrderId(42);
        $this->givenProduct(1, 'Widget', '10.00', $stock);
        $this->givenStoredOrder(42, ['id' => 42], []);

        [$status, $body] = $this->request('POST', '/api/orders', '{"items":[{"product_id":1,"quantity":' . $quantityJson . '}]}');

        if ($accepted) {
            self::assertSame(201, $status);
            self::assertTrue($this->pdo->committed());
            self::assertFalse($this->pdo->rolledBack());
        } else {
            $this->assertRejectedAndRolledBack($status, $body, 'Insufficient stock for Widget');
        }
    }

    /** @return array<string, array{int|string, string, bool}> */
    public static function stockComparisons(): array
    {
        return [
            'stock equals quantity' => [5, '5', true],
            'stock above quantity' => [6, '5', true],
            'stock one below quantity' => [4, '5', false],
            'no stock' => [0, '1', false],
            'one in stock for one' => [1, '1', true],
            'string stock equals int quantity' => ['5', '5', true],
            'string stock below int quantity' => ['4', '5', false],
            'int stock equals string quantity' => [5, '"5"', true],
            'int stock below string quantity' => [5, '"6"', false],
            'numeric strings compare numerically (10 >= 9)' => ['10', '"9"', true],
            'numeric strings compare numerically (9 < 10)' => ['9', '"10"', false],
            'equal numeric strings' => ['5', '"5"', true],
            'fractional quantity above stock is rejected' => [5, '5.5', false],
            'fractional quantity below stock is accepted' => [5, '4.5', true],
        ];
    }

    #[Test]
    public function create_order_rolls_back_everything_when_the_second_item_fails(): void
    {
        $this->givenNewOrderId(42);
        $this->givenProduct(1, 'Widget', '10.00', 10);
        $this->givenProduct(2, 'Gadget', '5.00', 1);

        [$status, $body] = $this->request('POST', '/api/orders', [
            'items' => [
                ['product_id' => 1, 'quantity' => 2],
                ['product_id' => 2, 'quantity' => 3],
            ],
        ]);

        $this->assertRejectedAndRolledBack($status, $body, 'Insufficient stock for Gadget');
        self::assertSame(
            [
                self::SQL_INSERT_ORDER,
                self::SQL_SELECT_PRODUCT,
                self::SQL_INSERT_ITEM,
                self::SQL_UPDATE_STOCK,
                self::SQL_SELECT_PRODUCT,
            ],
            $this->executedSql()
        );
        self::assertFalse($this->pdo->wasExecuted(self::SQL_UPDATE_TOTAL));
    }

    #[Test]
    public function create_order_rolls_back_everything_when_the_second_item_is_unknown(): void
    {
        $this->givenNewOrderId(42);
        $this->givenProduct(1, 'Widget', '10.00', 10);

        [$status, $body] = $this->request('POST', '/api/orders', [
            'items' => [
                ['product_id' => 1, 'quantity' => 1],
                ['product_id' => 77, 'quantity' => 1],
            ],
        ]);

        $this->assertRejectedAndRolledBack($status, $body, 'Product 77 not found');
    }

    #[Test]
    public function create_order_rolls_back_everything_when_the_second_item_is_malformed(): void
    {
        $this->givenNewOrderId(42);
        $this->givenProduct(1, 'Widget', '10.00', 10);

        [$status, $body] = $this->request('POST', '/api/orders', [
            'items' => [
                ['product_id' => 1, 'quantity' => 1],
                ['product_id' => 1],
            ],
        ]);

        $this->assertRejectedAndRolledBack($status, $body, 'Each item needs product_id and quantity');
    }

    #[Test]
    public function create_order_reports_the_first_failing_item_only(): void
    {
        $this->givenNewOrderId(42);

        [$status, $body] = $this->request('POST', '/api/orders', [
            'items' => [
                ['product_id' => 1, 'quantity' => 0],
                ['product_id' => 2],
            ],
        ]);

        $this->assertRejectedAndRolledBack($status, $body, 'Quantity must be at least 1');
    }

    // ------------------------------------------------------------------
    // POST /api/orders : unexpected failures (500)
    // ------------------------------------------------------------------

    #[Test]
    public function create_order_returns_500_when_inserting_the_order_row_fails(): void
    {
        $this->pdo->onSql(self::SQL_INSERT_ORDER)->throws(new RuntimeException('connection lost'));

        [$status, $body] = $this->request('POST', '/api/orders', '{"items":[{"product_id":1,"quantity":1}]}');

        $this->assertFailedAndRolledBack($status, $body);
        self::assertFalse($this->pdo->committed());
    }

    #[Test]
    public function create_order_returns_500_without_leaking_the_message_of_a_database_error(): void
    {
        $this->givenNewOrderId(42);
        $this->givenProduct(1, 'Widget', '10.00', 10);
        $this->pdo->onSql(self::SQL_INSERT_ITEM)->throws(
            ScriptedPdo::sqlStateException('23000', 'Integrity constraint violation: secret detail')
        );

        [$status, $body] = $this->request('POST', '/api/orders', '{"items":[{"product_id":1,"quantity":1}]}');

        $this->assertFailedAndRolledBack($status, $body);
        self::assertStringNotContainsString('secret', $body);
        self::assertFalse($this->pdo->committed());
    }

    #[Test]
    #[DataProvider('failingStatements')]
    public function create_order_returns_500_when_any_statement_in_the_transaction_throws(string $failingSql): void
    {
        $this->givenNewOrderId(42);
        $this->givenProduct(1, 'Widget', '10.00', 10);
        $this->pdo->onSql($failingSql)->throws(new PDOException('boom'));

        [$status, $body] = $this->request('POST', '/api/orders', '{"items":[{"product_id":1,"quantity":1}]}');

        $this->assertFailedAndRolledBack($status, $body);
        self::assertFalse($this->pdo->committed());
    }

    /** @return array<string, array{string}> */
    public static function failingStatements(): array
    {
        return [
            'select product for update' => [self::SQL_SELECT_PRODUCT],
            'insert order item' => [self::SQL_INSERT_ITEM],
            'decrease stock' => [self::SQL_UPDATE_STOCK],
            'update order total' => [self::SQL_UPDATE_TOTAL],
        ];
    }

    #[Test]
    public function create_order_returns_500_and_rolls_back_when_commit_fails_without_touching_cache_or_queue(): void
    {
        $this->givenNewOrderId(42);
        $this->givenProduct(1, 'Widget', '10.00', 10);
        $this->pdo->failCommitWith(new PDOException('commit failed'));

        [$status, $body] = $this->request('POST', '/api/orders', '{"items":[{"product_id":1,"quantity":1}]}');

        self::assertSame(500, $status);
        self::assertSame('{"error":"Order creation failed"}', $body);
        self::assertSame(['begin', 'commit', 'rollback'], $this->pdo->transactionEvents());
        self::assertSame([], $this->redis->calls());
        self::assertSame([], $this->amqp->published());
        self::assertSame(0, $this->amqp->channelsOpened());
    }

    #[Test]
    public function create_order_treats_an_invalid_argument_exception_from_the_database_layer_as_a_400(): void
    {
        $this->givenNewOrderId(42);
        $this->givenProduct(1, 'Widget', '10.00', 10);
        $this->pdo->onSql(self::SQL_UPDATE_STOCK)->throws(new InvalidArgumentException('driver says no'));

        [$status, $body] = $this->request('POST', '/api/orders', '{"items":[{"product_id":1,"quantity":1}]}');

        $this->assertRejectedAndRolledBack($status, $body, 'driver says no');
    }

    // ------------------------------------------------------------------
    // POST /api/orders : failures after commit (quirks, characterized as-is)
    // ------------------------------------------------------------------

    #[Test]
    public function create_order_when_the_queue_channel_cannot_be_opened_commits_then_rolls_back_and_returns_500(): void
    {
        $this->givenNewOrderId(42);
        $this->givenProduct(1, 'Widget', '10.00', 10);
        $this->amqp->failChannelWith(new AMQPRuntimeException('broker down'));

        [$status, $body] = $this->request('POST', '/api/orders', '{"items":[{"product_id":1,"quantity":1}]}');

        self::assertSame(500, $status);
        self::assertSame('{"error":"Order creation failed"}', $body);
        self::assertSame(['begin', 'commit', 'rollback'], $this->pdo->transactionEvents());
        self::assertSame([], $this->amqp->published());
        self::assertSame([], $this->amqp->declaredQueues());
    }

    #[Test]
    public function create_order_when_publishing_fails_commits_then_rolls_back_and_returns_500(): void
    {
        $this->givenNewOrderId(42);
        $this->givenProduct(1, 'Widget', '10.00', 10);
        $this->amqp->failPublishWith(new AMQPRuntimeException('publish failed'));

        [$status, $body] = $this->request('POST', '/api/orders', '{"items":[{"product_id":1,"quantity":1}]}');

        self::assertSame(500, $status);
        self::assertSame('{"error":"Order creation failed"}', $body);
        self::assertSame(['begin', 'commit', 'rollback'], $this->pdo->transactionEvents());
        self::assertSame([], $this->amqp->published());
        self::assertSame(1, $this->amqp->channelsOpened());
        self::assertSame(0, $this->amqp->channelsClosed());
    }

    #[Test]
    public function create_order_when_publishing_fails_the_order_stays_stored_and_caches_were_already_invalidated(): void
    {
        $this->givenNewOrderId(42);
        $this->givenProduct(1, 'Widget', '10.00', 10);
        $this->givenProduct(2, 'Gadget', '5.00', 10);
        $this->amqp->failPublishWith(new RuntimeException('publish failed'));

        $this->request('POST', '/api/orders', [
            'items' => [['product_id' => 1, 'quantity' => 1], ['product_id' => 2, 'quantity' => 1]],
        ]);

        self::assertTrue($this->pdo->wasExecuted(self::SQL_UPDATE_TOTAL));
        self::assertSame([['products:1'], ['products:2'], ['products:all']], $this->redis->callsTo('del'));
        self::assertSame([
            ['order_created', false, true, false, false],
        ], $this->amqp->declaredQueues());
    }

    #[Test]
    public function create_order_when_publishing_fails_never_reads_the_order_back(): void
    {
        $this->givenNewOrderId(42);
        $this->givenProduct(1, 'Widget', '10.00', 10);
        $this->givenStoredOrder(42, ['id' => 42], []);
        $this->amqp->failPublishWith(new RuntimeException('publish failed'));

        [, $body] = $this->request('POST', '/api/orders', '{"items":[{"product_id":1,"quantity":1}]}');

        self::assertFalse($this->pdo->wasExecuted(self::SQL_SELECT_ORDER));
        self::assertStringNotContainsString('"id":42', $body);
    }

    #[Test]
    public function create_order_when_publishing_throws_an_invalid_argument_exception_returns_400_after_the_commit(): void
    {
        $this->givenNewOrderId(42);
        $this->givenProduct(1, 'Widget', '10.00', 10);
        $this->amqp->failPublishWith(new InvalidArgumentException('bad payload'));

        [$status, $body] = $this->request('POST', '/api/orders', '{"items":[{"product_id":1,"quantity":1}]}');

        self::assertSame(400, $status);
        self::assertSame('{"error":"bad payload"}', $body);
        self::assertSame(['begin', 'commit', 'rollback'], $this->pdo->transactionEvents());
        self::assertSame([['products:1'], ['products:all']], $this->redis->callsTo('del'));
    }

    #[Test]
    public function create_order_when_cache_invalidation_fails_after_commit_rolls_back_and_skips_the_publish(): void
    {
        $this->givenNewOrderId(42);
        $this->givenProduct(1, 'Widget', '10.00', 10);
        $this->redis->failOn('del', new RuntimeException('redis down'));

        [$status, $body] = $this->request('POST', '/api/orders', '{"items":[{"product_id":1,"quantity":1}]}');

        self::assertSame(500, $status);
        self::assertSame('{"error":"Order creation failed"}', $body);
        self::assertSame(['begin', 'commit', 'rollback'], $this->pdo->transactionEvents());
        self::assertSame([], $this->amqp->published());
        self::assertSame(0, $this->amqp->channelsOpened());
    }

    #[Test]
    public function create_order_lets_a_php_error_from_the_publisher_escape_the_router_after_the_commit(): void
    {
        $this->givenNewOrderId(42);
        $this->givenProduct(1, 'Widget', '10.00', 10);
        $this->amqp->failPublishWith(new \Error('not an Exception'));

        $escaped = null;
        try {
            $this->request('POST', '/api/orders', '{"items":[{"product_id":1,"quantity":1}]}');
        } catch (\Error $e) {
            $escaped = $e;
        }

        self::assertNotNull($escaped);
        self::assertSame('not an Exception', $escaped->getMessage());
        self::assertSame(['begin', 'commit'], $this->pdo->transactionEvents());
    }

    // ------------------------------------------------------------------
    // POST /api/orders : success path
    // ------------------------------------------------------------------

    #[Test]
    public function create_order_runs_the_exact_statement_sequence_for_a_single_item(): void
    {
        $this->givenNewOrderId(42);
        $this->givenProduct(1, 'Widget', '19.99', 10);
        $this->givenStoredOrder(42, ['id' => 42, 'total' => '39.98'], []);

        $this->request('POST', '/api/orders', '{"items":[{"product_id":1,"quantity":2}]}');

        $total = 0 + (float) '19.99' * 2;
        self::assertSame(
            [
                ['sql' => self::SQL_INSERT_ORDER, 'params' => []],
                ['sql' => self::SQL_SELECT_PRODUCT, 'params' => [1]],
                ['sql' => self::SQL_INSERT_ITEM, 'params' => [42, 1, 2, '19.99']],
                ['sql' => self::SQL_UPDATE_STOCK, 'params' => [2, 1]],
                ['sql' => self::SQL_UPDATE_TOTAL, 'params' => [$total, 42]],
                ['sql' => self::SQL_SELECT_ORDER, 'params' => [42]],
                ['sql' => self::SQL_SELECT_ITEMS, 'params' => [42]],
            ],
            $this->pdo->executed()
        );
    }

    #[Test]
    public function create_order_binds_the_order_id_returned_by_the_database_as_an_int(): void
    {
        $this->givenNewOrderId(1234);
        $this->givenProduct(1, 'Widget', '10.00', 10);
        $this->givenStoredOrder(1234, ['id' => 1234], []);

        $this->request('POST', '/api/orders', '{"items":[{"product_id":1,"quantity":1}]}');

        self::assertSame([[1234, 1, 1, '10.00']], $this->pdo->paramsOf(self::SQL_INSERT_ITEM));
        self::assertSame([[10.0, 1234]], $this->pdo->paramsOf(self::SQL_UPDATE_TOTAL));
        self::assertSame([[1234], [1234]], [
            ...$this->pdo->paramsOf(self::SQL_SELECT_ORDER),
            ...$this->pdo->paramsOf('FROM order_items oi JOIN products p'),
        ]);
    }

    #[Test]
    public function create_order_commits_once_and_never_rolls_back_on_success(): void
    {
        $this->givenNewOrderId(42);
        $this->givenProduct(1, 'Widget', '10.00', 10);
        $this->givenStoredOrder(42, ['id' => 42], []);

        $this->request('POST', '/api/orders', '{"items":[{"product_id":1,"quantity":1}]}');

        self::assertSame(['begin', 'commit'], $this->pdo->transactionEvents());
        self::assertTrue($this->pdo->committed());
        self::assertFalse($this->pdo->rolledBack());
    }

    #[Test]
    public function create_order_responds_201_with_the_same_body_as_get_order(): void
    {
        $order = ['id' => 42, 'total' => '39.98', 'created_at' => '2026-03-01 09:30:00'];
        $items = [
            ['id' => 100, 'order_id' => 42, 'product_id' => 1, 'quantity' => 2, 'price' => '19.99', 'product_name' => 'Widget'],
        ];
        $this->givenNewOrderId(42);
        $this->givenProduct(1, 'Widget', '19.99', 10);
        $this->givenStoredOrder(42, $order, $items);

        [$createStatus, $createBody] = $this->request('POST', '/api/orders', '{"items":[{"product_id":1,"quantity":2}]}');
        [$getStatus, $getBody] = $this->request('GET', '/api/orders/42');

        self::assertSame(201, $createStatus);
        self::assertSame(200, $getStatus);
        self::assertSame($getBody, $createBody);
        self::assertSame(json_encode($order + ['items' => $items]), $createBody);
    }

    #[Test]
    public function create_order_responds_404_when_the_committed_order_cannot_be_read_back(): void
    {
        $this->givenNewOrderId(42);
        $this->givenProduct(1, 'Widget', '10.00', 10);

        [$status, $body] = $this->request('POST', '/api/orders', '{"items":[{"product_id":1,"quantity":1}]}');

        self::assertSame(404, $status);
        self::assertSame('{"error":"Order not found"}', $body);
        self::assertSame(['begin', 'commit'], $this->pdo->transactionEvents());
        self::assertCount(1, $this->amqp->published());
    }

    #[Test]
    public function create_order_invalidates_caches_after_commit_and_publishes_after_invalidating(): void
    {
        $this->givenNewOrderId(42);
        $this->givenProduct(1, 'Widget', '10.00', 10);
        $this->givenProduct(2, 'Gadget', '5.00', 10);
        $this->givenStoredOrder(42, ['id' => 42], []);

        $atPublish = null;
        $this->amqp->beforePublish(function () use (&$atPublish): void {
            $atPublish = [
                'transaction' => $this->pdo->transactionEvents(),
                'del' => $this->redis->callsTo('del'),
                'readBack' => $this->pdo->wasExecuted(self::SQL_SELECT_ORDER),
            ];
        });

        $this->request('POST', '/api/orders', [
            'items' => [['product_id' => 1, 'quantity' => 1], ['product_id' => 2, 'quantity' => 1]],
        ]);

        self::assertSame(
            [
                'transaction' => ['begin', 'commit'],
                'del' => [['products:1'], ['products:2'], ['products:all']],
                'readBack' => false,
            ],
            $atPublish
        );
    }

    #[Test]
    public function create_order_deletes_one_cache_key_per_item_then_the_list_key(): void
    {
        $this->givenNewOrderId(42);
        $this->givenProduct(3, 'Widget', '10.00', 10);
        $this->givenProduct(9, 'Gadget', '5.00', 10);
        $this->givenStoredOrder(42, ['id' => 42], []);

        $this->request('POST', '/api/orders', [
            'items' => [['product_id' => 9, 'quantity' => 1], ['product_id' => 3, 'quantity' => 1]],
        ]);

        self::assertSame([['products:9'], ['products:3'], ['products:all']], $this->redis->callsTo('del'));
        self::assertSame(['del', 'del', 'del'], array_column($this->redis->calls(), 0));
    }

    #[Test]
    public function create_order_deletes_the_cache_key_once_per_item_when_a_product_repeats(): void
    {
        $this->givenNewOrderId(42);
        $this->givenProduct(1, 'Widget', '10.00', 10);
        $this->givenStoredOrder(42, ['id' => 42], []);

        $this->request('POST', '/api/orders', [
            'items' => [['product_id' => 1, 'quantity' => 1], ['product_id' => 1, 'quantity' => 2]],
        ]);

        self::assertSame([['products:1'], ['products:1'], ['products:all']], $this->redis->callsTo('del'));
        self::assertSame([[42, 1, 1, '10.00'], [42, 1, 2, '10.00']], $this->pdo->paramsOf(self::SQL_INSERT_ITEM));
    }

    #[Test]
    public function create_order_does_not_write_to_the_cache_it_only_deletes(): void
    {
        $this->givenNewOrderId(42);
        $this->givenProduct(1, 'Widget', '10.00', 10);
        $this->givenStoredOrder(42, ['id' => 42], []);
        $this->redis->seed('products:1', 'stale')->seed('products:all', 'stale');

        $this->request('POST', '/api/orders', '{"items":[{"product_id":1,"quantity":1}]}');

        self::assertNull($this->redis->stored('products:1'));
        self::assertNull($this->redis->stored('products:all'));
        self::assertSame([], $this->redis->callsTo('setex'));
        self::assertSame([], $this->redis->callsTo('get'));
    }

    #[Test]
    public function create_order_publishes_one_order_created_message_to_a_durable_queue(): void
    {
        $this->givenNewOrderId(42);
        $this->givenProduct(1, 'Widget', '10.00', 10);
        $this->givenStoredOrder(42, ['id' => 42], []);

        $this->request('POST', '/api/orders', '{"items":[{"product_id":1,"quantity":1}]}');

        self::assertCount(1, $this->amqp->published());
        $message = $this->amqp->published()[0];
        self::assertSame('order_created', $message['queue']);
        self::assertSame('', $message['exchange']);
        // queue_declare($queue, passive: false, durable: true, exclusive: false, auto_delete: false)
        self::assertSame([['order_created', false, true, false, false]], $this->amqp->declaredQueues());
        self::assertSame(2, $message['properties']['delivery_mode']);
        self::assertSame(1, $this->amqp->channelsOpened());
        self::assertSame(1, $this->amqp->channelsClosed());
    }

    #[Test]
    public function create_order_message_body_has_order_id_total_and_iso8601_created_at(): void
    {
        $this->givenNewOrderId(42);
        $this->givenProduct(1, 'Widget', '19.99', 10);
        $this->givenStoredOrder(42, ['id' => 42], []);

        $before = new DateTimeImmutable('now');
        $this->request('POST', '/api/orders', '{"items":[{"product_id":1,"quantity":2}]}');
        $after = new DateTimeImmutable('now');

        $message = $this->amqp->published()[0];
        $payload = $message['payload'];

        self::assertSame(['order_id', 'total', 'created_at'], array_keys($payload));
        self::assertSame(42, $payload['order_id']);
        self::assertIsFloat($payload['total']);
        self::assertSame(0 + (float) '19.99' * 2, $payload['total']);
        self::assertMatchesRegularExpression(
            '/^\{"order_id":42,"total":' . preg_quote(json_encode(0 + (float) '19.99' * 2), '/') . ',"created_at":"[^"]+"\}$/',
            $message['body']
        );
        self::assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/',
            $payload['created_at']
        );

        $createdAt = new DateTimeImmutable($payload['created_at']);
        self::assertGreaterThanOrEqual($before->getTimestamp() - 1, $createdAt->getTimestamp());
        self::assertLessThanOrEqual($after->getTimestamp() + 1, $createdAt->getTimestamp());
    }

    #[Test]
    public function create_order_message_total_is_serialized_without_a_fraction_for_whole_amounts(): void
    {
        $this->givenNewOrderId(42);
        $this->givenProduct(1, 'Widget', '10.00', 10);
        $this->givenStoredOrder(42, ['id' => 42], []);

        $this->request('POST', '/api/orders', '{"items":[{"product_id":1,"quantity":2}]}');

        $message = $this->amqp->published()[0];
        // The PHP total is the float 20.0, but json_encode writes it as 20 so consumers decode an int.
        self::assertStringContainsString('"total":20,', $message['body']);
        self::assertSame(20, $message['payload']['total']);
        self::assertSame([[20.0, 42]], $this->pdo->paramsOf(self::SQL_UPDATE_TOTAL));
    }

    #[Test]
    public function create_order_sums_float_line_totals_across_items(): void
    {
        $this->givenNewOrderId(42);
        $this->givenProduct(1, 'Widget', '19.99', 10);
        $this->givenProduct(2, 'Gadget', '5.50', 10);
        $this->givenProduct(3, 'Gizmo', 0.1, 10);
        $this->givenStoredOrder(42, ['id' => 42], []);

        $this->request('POST', '/api/orders', [
            'items' => [
                ['product_id' => 1, 'quantity' => 2],
                ['product_id' => 2, 'quantity' => 3],
                ['product_id' => 3, 'quantity' => 7],
            ],
        ]);

        $expected = 0 + (float) '19.99' * 2;
        $expected += (float) '5.50' * 3;
        $expected += 0.1 * 7;

        self::assertSame([[$expected, 42]], $this->pdo->paramsOf(self::SQL_UPDATE_TOTAL));
        self::assertSame($expected, $this->amqp->published()[0]['payload']['total']);
        self::assertSame(
            [[42, 1, 2, '19.99'], [42, 2, 3, '5.50'], [42, 3, 7, 0.1]],
            $this->pdo->paramsOf(self::SQL_INSERT_ITEM)
        );
        self::assertSame([[2, 1], [3, 2], [7, 3]], $this->pdo->paramsOf(self::SQL_UPDATE_STOCK));
    }

    #[Test]
    public function create_order_processes_items_one_at_a_time_in_request_order(): void
    {
        $this->givenNewOrderId(42);
        $this->givenProduct(2, 'Gadget', '5.00', 10);
        $this->givenProduct(1, 'Widget', '10.00', 10);
        $this->givenStoredOrder(42, ['id' => 42], []);

        $this->request('POST', '/api/orders', [
            'items' => [['product_id' => 2, 'quantity' => 1], ['product_id' => 1, 'quantity' => 1]],
        ]);

        self::assertSame(
            [
                self::SQL_INSERT_ORDER,
                self::SQL_SELECT_PRODUCT,
                self::SQL_INSERT_ITEM,
                self::SQL_UPDATE_STOCK,
                self::SQL_SELECT_PRODUCT,
                self::SQL_INSERT_ITEM,
                self::SQL_UPDATE_STOCK,
                self::SQL_UPDATE_TOTAL,
                self::SQL_SELECT_ORDER,
                self::SQL_SELECT_ITEMS,
            ],
            $this->executedSql()
        );
        self::assertSame([[2], [1]], $this->pdo->paramsOf(self::SQL_SELECT_PRODUCT));
    }

    #[Test]
    public function create_order_uses_the_database_price_not_a_client_supplied_price(): void
    {
        $this->givenNewOrderId(42);
        $this->givenProduct(1, 'Widget', '10.00', 10);
        $this->givenStoredOrder(42, ['id' => 42], []);

        $this->request('POST', '/api/orders', '{"items":[{"product_id":1,"quantity":1,"price":0.01}]}');

        self::assertSame([[42, 1, 1, '10.00']], $this->pdo->paramsOf(self::SQL_INSERT_ITEM));
        self::assertSame([[10.0, 42]], $this->pdo->paramsOf(self::SQL_UPDATE_TOTAL));
    }

    #[Test]
    public function create_order_binds_string_product_id_and_quantity_exactly_as_sent(): void
    {
        $this->givenNewOrderId(42);
        $this->givenProduct(7, 'Widget', '10.50', 10);
        $this->givenStoredOrder(42, ['id' => 42], []);

        $this->request('POST', '/api/orders', '{"items":[{"product_id":"7","quantity":"2"}]}');

        self::assertSame([['7']], $this->pdo->paramsOf(self::SQL_SELECT_PRODUCT));
        self::assertSame([[42, '7', '2', '10.50']], $this->pdo->paramsOf(self::SQL_INSERT_ITEM));
        self::assertSame([['2', '7']], $this->pdo->paramsOf(self::SQL_UPDATE_STOCK));
        self::assertSame([[21.0, 42]], $this->pdo->paramsOf(self::SQL_UPDATE_TOTAL));
        self::assertSame([['products:7'], ['products:all']], $this->redis->callsTo('del'));
    }

    #[Test]
    public function create_order_casts_a_fractional_quantity_for_the_total_but_binds_it_as_sent(): void
    {
        $this->givenNewOrderId(42);
        $this->givenProduct(1, 'Widget', '10.00', 10);
        $this->givenStoredOrder(42, ['id' => 42], []);

        [$status] = $this->request('POST', '/api/orders', '{"items":[{"product_id":1,"quantity":2.9}]}');

        self::assertSame(201, $status);
        self::assertSame([[42, 1, 2.9, '10.00']], $this->pdo->paramsOf(self::SQL_INSERT_ITEM));
        self::assertSame([[20.0, 42]], $this->pdo->paramsOf(self::SQL_UPDATE_TOTAL));
    }

    #[Test]
    public function create_order_succeeds_when_stock_exactly_equals_the_quantity(): void
    {
        $this->givenNewOrderId(42);
        $this->givenProduct(1, 'Widget', '10.00', 3);
        $this->givenStoredOrder(42, ['id' => 42], []);

        [$status] = $this->request('POST', '/api/orders', '{"items":[{"product_id":1,"quantity":3}]}');

        self::assertSame(201, $status);
        self::assertSame([[3, 1]], $this->pdo->paramsOf(self::SQL_UPDATE_STOCK));
        self::assertCount(1, $this->amqp->published());
    }

    #[Test]
    public function create_order_ignores_extra_top_level_fields_in_the_body(): void
    {
        $this->givenNewOrderId(42);
        $this->givenProduct(1, 'Widget', '10.00', 10);
        $this->givenStoredOrder(42, ['id' => 42], []);

        [$status] = $this->request('POST', '/api/orders', [
            'customer' => 'someone',
            'total' => 1,
            'items' => [['product_id' => 1, 'quantity' => 1]],
        ]);

        self::assertSame(201, $status);
        self::assertSame([[10.0, 42]], $this->pdo->paramsOf(self::SQL_UPDATE_TOTAL));
    }

    #[Test]
    public function create_order_also_matches_with_a_trailing_slash_and_query_string(): void
    {
        $this->givenNewOrderId(42);
        $this->givenProduct(1, 'Widget', '10.00', 10);
        $this->givenStoredOrder(42, ['id' => 42], []);

        [$status] = $this->request('POST', '/api/orders/?source=web', '{"items":[{"product_id":1,"quantity":1}]}');

        self::assertSame(201, $status);
        self::assertTrue($this->pdo->committed());
    }

    #[Test]
    public function create_order_leaves_a_stale_cache_untouched_on_failure(): void
    {
        $this->givenNewOrderId(42);
        $this->givenProduct(1, 'Widget', '10.00', 1);
        $this->redis->seed('products:1', 'cached')->seed('products:all', 'cached-all');

        $this->request('POST', '/api/orders', '{"items":[{"product_id":1,"quantity":5}]}');

        self::assertSame('cached', $this->redis->stored('products:1'));
        self::assertSame('cached-all', $this->redis->stored('products:all'));
        self::assertSame([], $this->redis->calls());
    }
}
