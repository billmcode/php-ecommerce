<?php

namespace Tests\Controllers;

use App\Controllers\OrderController;
use ArrayObject;
use DateTimeImmutable;
use DomainException;
use InvalidArgumentException;
use LogicException;
use PDOException;
use PhpAmqpLib\Exception\AMQPRuntimeException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\HttpTestCase;
use Tests\Support\PublisherSpy;
use Tests\Support\QueueReset;
use Tests\Support\ScriptedPdo;
use Throwable;
use UnexpectedValueException;

/**
 * Controller-level tests: OrderController is built directly from the fakes (no Router)
 * and its list() / get() / create() actions are invoked one by one.
 *
 * The publisher seam is covered twice: with an injected publisher (no AMQP activity at
 * all) and with the default publisher, which must route through App\Queue::publish.
 */
final class OrderControllerTest extends HttpTestCase
{
    private const SQL_LIST = 'SELECT * FROM orders ORDER BY id DESC';
    private const SQL_INSERT_ORDER = 'INSERT INTO orders (total) VALUES (0)';
    private const SQL_SELECT_PRODUCT = 'SELECT * FROM products WHERE id = ? FOR UPDATE';
    private const SQL_INSERT_ITEM = 'INSERT INTO order_items (order_id, product_id, quantity, price) VALUES (?, ?, ?, ?)';
    private const SQL_UPDATE_STOCK = 'UPDATE products SET stock = stock - ? WHERE id = ?';
    private const SQL_UPDATE_TOTAL = 'UPDATE orders SET total = ? WHERE id = ?';
    private const SQL_SELECT_ORDER = 'SELECT * FROM orders WHERE id = ?';
    private const SQL_SELECT_ITEMS = 'SELECT oi.*, p.name as product_name FROM order_items oi JOIN products p ON p.id = oi.product_id WHERE oi.order_id = ?';
    private const SQL_ITEMS_FRAGMENT = 'FROM order_items oi JOIN products p';

    private const ONE_WIDGET = '{"items":[{"product_id":1,"quantity":1}]}';

    private PublisherSpy $publisher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->publisher = new PublisherSpy();
    }

    // ------------------------------------------------------------------
    // Fixtures and helpers
    // ------------------------------------------------------------------

    /** Controller with the injected publisher spy (or the given publisher). */
    private function controller(?callable $publisher = null): OrderController
    {
        return new OrderController($this->pdo, $this->redis, $publisher ?? $this->publisher);
    }

    /** @return array{0: int, 1: string} */
    private function callList(?OrderController $controller = null): array
    {
        $controller ??= $this->controller();

        return $this->capture(static function () use ($controller): void {
            $controller->list();
        });
    }

    /** @return array{0: int, 1: string} */
    private function callGet(int $id, ?OrderController $controller = null): array
    {
        $controller ??= $this->controller();

        return $this->capture(static function () use ($controller, $id): void {
            $controller->get($id);
        });
    }

    /**
     * @param string|array<mixed> $body
     *
     * @return array{0: int, 1: string}
     */
    private function callCreate(string|array $body, ?OrderController $controller = null): array
    {
        $controller ??= $this->controller();

        return $this->capture(static function () use ($controller): void {
            $controller->create();
        }, $body);
    }

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
     * @param array<string, mixed>       $order
     * @param list<array<string, mixed>> $items
     */
    private function givenStoredOrder(int $orderId, array $order, array $items): void
    {
        $this->pdo->onSql(self::SQL_SELECT_ORDER)->withParams([$orderId])->fetchesRow($order);
        $this->pdo->onSql(self::SQL_ITEMS_FRAGMENT)->withParams([$orderId])->fetchesRows($items);
    }

    /** A fully scripted single-product order 42 that can be created successfully. */
    private function givenCreatableOrder(): void
    {
        $this->givenNewOrderId(42);
        $this->givenProduct(1, 'Widget', '19.99', 10);
        $this->givenStoredOrder(
            42,
            ['id' => 42, 'total' => '19.99', 'created_at' => '2026-03-01 09:30:00'],
            [['id' => 100, 'order_id' => 42, 'product_id' => 1, 'quantity' => 1, 'price' => '19.99', 'product_name' => 'Widget']]
        );
    }

    /** @return list<string> */
    private function executedSql(): array
    {
        return array_column($this->pdo->executed(), 'sql');
    }

    private function assertNoAmqpActivity(): void
    {
        self::assertSame(0, $this->amqp->channelsOpened());
        self::assertSame(0, $this->amqp->channelsClosed());
        self::assertSame([], $this->amqp->declaredQueues());
        self::assertSame([], $this->amqp->published());
    }

    /** Nothing was touched: no SQL, no transaction, no cache call, no publish, no AMQP. */
    private function assertNothingTouched(): void
    {
        self::assertSame([], $this->pdo->executed());
        self::assertSame([], $this->pdo->transactionEvents());
        self::assertSame([], $this->redis->calls());
        self::assertSame([], $this->publisher->calls());
        $this->assertNoAmqpActivity();
    }

    /** Asserts a rolled-back 400 with no side effect outside the transaction. */
    private function assertRejectedAndRolledBack(int $status, string $body, string $message): void
    {
        self::assertSame(400, $status);
        self::assertSame(json_encode(['error' => $message]), $body);
        self::assertSame(['begin', 'rollback'], $this->pdo->transactionEvents());
        self::assertFalse($this->pdo->committed());
        self::assertSame([], $this->redis->calls());
        self::assertSame([], $this->publisher->calls());
        $this->assertNoAmqpActivity();
    }

    /** Asserts a rolled-back 500 where the failure happened before the commit. */
    private function assertFailedAndRolledBack(int $status, string $body): void
    {
        self::assertSame(500, $status);
        self::assertSame('{"error":"Order creation failed"}', $body);
        self::assertSame(['begin', 'rollback'], $this->pdo->transactionEvents());
        self::assertFalse($this->pdo->committed());
        self::assertSame([], $this->redis->calls());
        self::assertSame([], $this->publisher->calls());
        $this->assertNoAmqpActivity();
    }

    // ------------------------------------------------------------------
    // Construction
    // ------------------------------------------------------------------

    #[Test]
    public function constructing_with_an_injected_publisher_performs_no_io(): void
    {
        $controller = $this->controller();

        self::assertInstanceOf(OrderController::class, $controller);
        $this->assertNothingTouched();
        self::assertSame('', (string) ob_get_contents());
    }

    #[Test]
    public function constructing_with_the_default_publisher_performs_no_io_and_does_not_connect_to_the_queue(): void
    {
        QueueReset::install(null);

        $controller = new OrderController($this->pdo, $this->redis);

        self::assertInstanceOf(OrderController::class, $controller);
        self::assertNull(QueueReset::current());
        $this->assertNothingTouched();
        self::assertSame('', (string) ob_get_contents());
    }

    #[Test]
    public function constructing_with_an_explicit_null_publisher_also_performs_no_io(): void
    {
        $controller = new OrderController($this->pdo, $this->redis, null);

        self::assertInstanceOf(OrderController::class, $controller);
        $this->assertNothingTouched();
    }

    // ------------------------------------------------------------------
    // list()
    // ------------------------------------------------------------------

    #[Test]
    public function list_echoes_every_order_row_as_json_in_database_order(): void
    {
        $rows = [
            ['id' => 3, 'total' => '59.97', 'created_at' => '2026-01-03 10:00:00'],
            ['id' => 2, 'total' => '10.00', 'created_at' => '2026-01-02 10:00:00'],
            ['id' => 1, 'total' => '0.00', 'created_at' => '2026-01-01 10:00:00'],
        ];
        $this->pdo->onSql(self::SQL_LIST)->fetchesRows($rows);

        [$status, $body] = $this->callList();

        self::assertSame(200, $status);
        self::assertSame(json_encode($rows), $body);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    #[Test]
    #[DataProvider('orderRowSets')]
    public function list_serializes_the_fetched_rows_verbatim(array $rows, string $expectedJson): void
    {
        $this->pdo->onSql(self::SQL_LIST)->fetchesRows($rows);

        [$status, $body] = $this->callList();

        self::assertSame(200, $status);
        self::assertSame($expectedJson, $body);
    }

    /** @return array<string, array{list<array<string, mixed>>, string}> */
    public static function orderRowSets(): array
    {
        return [
            'no orders' => [[], '[]'],
            'single order' => [[['id' => 1, 'total' => '5.00']], '[{"id":1,"total":"5.00"}]'],
            'string and int columns keep their types' => [
                [['id' => '7', 'total' => 12.5, 'note' => null]],
                '[{"id":"7","total":12.5,"note":null}]',
            ],
            'unicode and slashes are escaped by json_encode' => [
                [['id' => 1, 'note' => "caf\u{e9}/x"]],
                json_encode([['id' => 1, 'note' => "caf\u{e9}/x"]]),
            ],
            'many orders keep their order' => [
                [['id' => 5], ['id' => 4], ['id' => 3], ['id' => 2], ['id' => 1]],
                '[{"id":5},{"id":4},{"id":3},{"id":2},{"id":1}]',
            ],
        ];
    }

    #[Test]
    public function list_runs_one_unbound_query_and_never_touches_the_cache_queue_or_a_transaction(): void
    {
        $this->pdo->onSql(self::SQL_LIST)->fetchesRows([['id' => 1]]);

        $this->callList();

        self::assertSame([['sql' => self::SQL_LIST, 'params' => []]], $this->pdo->executed());
        self::assertSame([], $this->pdo->transactionEvents());
        self::assertSame([], $this->redis->calls());
        self::assertSame([], $this->publisher->calls());
        $this->assertNoAmqpActivity();
    }

    #[Test]
    public function list_does_not_attach_items_to_the_orders(): void
    {
        $this->pdo->onSql(self::SQL_LIST)->fetchesRows([['id' => 1, 'total' => '5.00']]);

        [, $body] = $this->callList();

        self::assertSame('[{"id":1,"total":"5.00"}]', $body);
        self::assertFalse($this->pdo->wasExecuted('order_items'));
    }

    #[Test]
    public function list_leaves_the_status_code_alone_so_the_response_stays_200(): void
    {
        [$status] = $this->callList();

        self::assertSame(200, $status);
    }

    #[Test]
    public function list_lets_a_database_failure_propagate_without_output(): void
    {
        $this->pdo->onSql(self::SQL_LIST)->throws(new PDOException('connection lost'));
        $controller = $this->controller();

        $caught = null;
        $output = null;
        try {
            [, $output] = $this->callList($controller);
        } catch (PDOException $e) {
            $caught = $e;
        }

        self::assertNotNull($caught);
        self::assertSame('connection lost', $caught->getMessage());
        self::assertNull($output);
    }

    // ------------------------------------------------------------------
    // get()
    // ------------------------------------------------------------------

    #[Test]
    public function get_returns_the_order_with_its_items_and_their_product_names(): void
    {
        $order = ['id' => 5, 'total' => '39.98', 'created_at' => '2026-02-01 12:00:00'];
        $items = [
            ['id' => 10, 'order_id' => 5, 'product_id' => 1, 'quantity' => 2, 'price' => '19.99', 'product_name' => 'Widget'],
            ['id' => 11, 'order_id' => 5, 'product_id' => 2, 'quantity' => 1, 'price' => '5.00', 'product_name' => 'Gadget'],
        ];
        $this->givenStoredOrder(5, $order, $items);

        [$status, $body] = $this->callGet(5);

        self::assertSame(200, $status);
        self::assertSame(json_encode($order + ['items' => $items]), $body);
        $decoded = json_decode($body, true);
        self::assertSame(['Widget', 'Gadget'], array_column($decoded['items'], 'product_name'));
        self::assertSame(['id', 'total', 'created_at', 'items'], array_keys($decoded));
    }

    #[Test]
    public function get_returns_an_empty_items_array_for_an_order_without_items(): void
    {
        $this->givenStoredOrder(5, ['id' => 5, 'total' => '0.00'], []);

        [$status, $body] = $this->callGet(5);

        self::assertSame(200, $status);
        self::assertSame('{"id":5,"total":"0.00","items":[]}', $body);
    }

    #[Test]
    public function get_queries_the_order_then_its_items_joined_with_products_binding_the_id(): void
    {
        $this->givenStoredOrder(7, ['id' => 7], []);

        $this->callGet(7);

        self::assertSame(
            [
                ['sql' => self::SQL_SELECT_ORDER, 'params' => [7]],
                ['sql' => self::SQL_SELECT_ITEMS, 'params' => [7]],
            ],
            $this->pdo->executed()
        );
        self::assertSame([], $this->pdo->transactionEvents());
        self::assertSame([], $this->redis->calls());
        self::assertSame([], $this->publisher->calls());
    }

    #[Test]
    public function get_responds_404_with_order_not_found_when_the_order_is_missing(): void
    {
        $this->pdo->onSql(self::SQL_SELECT_ORDER)->fetchesRow(false);

        [$status, $body] = $this->callGet(99);

        self::assertSame(404, $status);
        self::assertSame('{"error":"Order not found"}', $body);
    }

    #[Test]
    public function get_does_not_look_up_items_for_a_missing_order(): void
    {
        $this->callGet(99);

        self::assertSame([['sql' => self::SQL_SELECT_ORDER, 'params' => [99]]], $this->pdo->executed());
        self::assertFalse($this->pdo->wasExecuted('order_items'));
    }

    #[Test]
    #[DataProvider('orderIds')]
    public function get_binds_whatever_integer_id_it_is_given(int $id): void
    {
        $this->givenStoredOrder($id, ['id' => $id], []);

        [$status, $body] = $this->callGet($id);

        self::assertSame(200, $status);
        self::assertSame('{"id":' . $id . ',"items":[]}', $body);
        self::assertSame([[$id], [$id]], array_column($this->pdo->executed(), 'params'));
    }

    /** @return array<string, array{int}> */
    public static function orderIds(): array
    {
        return [
            'one' => [1],
            'two digits' => [12],
            'large' => [2147483647],
            'zero' => [0],
            'negative' => [-5],
        ];
    }

    #[Test]
    public function get_lets_a_database_failure_propagate(): void
    {
        $this->pdo->onSql(self::SQL_SELECT_ORDER)->throws(new PDOException('gone away'));

        $this->expectException(PDOException::class);
        $this->expectExceptionMessage('gone away');

        $this->callGet(1);
    }

    // ------------------------------------------------------------------
    // create(): request body validation (before the transaction starts)
    // ------------------------------------------------------------------

    #[Test]
    #[DataProvider('bodiesWithoutAnItemsArray')]
    public function create_rejects_a_body_without_a_non_empty_items_array_before_touching_anything(string $rawBody): void
    {
        [$status, $body] = $this->callCreate($rawBody);

        self::assertSame(400, $status);
        self::assertSame('{"error":"items array is required"}', $body);
        $this->assertNothingTouched();
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
    public function create_accepts_an_array_body_given_as_php_data(): void
    {
        $this->givenCreatableOrder();

        [$status] = $this->callCreate(['items' => [['product_id' => 1, 'quantity' => 1]]]);

        self::assertSame(201, $status);
    }

    // ------------------------------------------------------------------
    // create(): validation failures inside the transaction
    // ------------------------------------------------------------------

    #[Test]
    #[DataProvider('itemsMissingProductIdOrQuantity')]
    public function create_rejects_an_item_missing_product_id_or_quantity_and_rolls_back(string $rawBody): void
    {
        $this->givenNewOrderId(42);

        [$status, $body] = $this->callCreate($rawBody);

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
            'scalar item' => ['{"items":[5]}'],
            'null item' => ['{"items":[null]}'],
            'wrong key names' => ['{"items":[{"id":1,"qty":1}]}'],
        ];
    }

    #[Test]
    public function create_inserts_the_empty_order_inside_a_transaction_before_validating_items(): void
    {
        $this->givenNewOrderId(42);

        $this->callCreate('{"items":[{"quantity":1}]}');

        self::assertSame(['begin', 'rollback'], $this->pdo->transactionEvents());
        self::assertSame([['sql' => self::SQL_INSERT_ORDER, 'params' => []]], $this->pdo->executed());
    }

    #[Test]
    #[DataProvider('quantitiesBelowOne')]
    public function create_rejects_a_quantity_below_one_without_looking_the_product_up(string $quantityJson): void
    {
        $this->givenNewOrderId(42);
        $this->givenProduct(1, 'Widget', '10.00', 100);

        [$status, $body] = $this->callCreate('{"items":[{"product_id":1,"quantity":' . $quantityJson . '}]}');

        $this->assertRejectedAndRolledBack($status, $body, 'Quantity must be at least 1');
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
    public function create_rejects_an_unknown_product_naming_the_id_as_given_and_rolls_back(
        string $productIdJson,
        string $expectedMessage
    ): void {
        $this->givenNewOrderId(42);

        [$status, $body] = $this->callCreate('{"items":[{"product_id":' . $productIdJson . ',"quantity":1}]}');

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
        ];
    }

    #[Test]
    public function create_looks_the_product_up_with_a_row_lock_and_the_id_exactly_as_given(): void
    {
        $this->givenNewOrderId(42);

        $this->callCreate('{"items":[{"product_id":"7","quantity":1}]}');

        self::assertSame([['7']], $this->pdo->paramsOf(self::SQL_SELECT_PRODUCT));
        self::assertStringEndsWith('FOR UPDATE', self::SQL_SELECT_PRODUCT);
    }

    #[Test]
    public function create_rejects_insufficient_stock_naming_the_product(): void
    {
        $this->givenNewOrderId(42);
        $this->givenProduct(1, 'Widget', '10.00', 2);

        [$status, $body] = $this->callCreate('{"items":[{"product_id":1,"quantity":3}]}');

        $this->assertRejectedAndRolledBack($status, $body, 'Insufficient stock for Widget');
        self::assertSame([self::SQL_INSERT_ORDER, self::SQL_SELECT_PRODUCT], $this->executedSql());
        self::assertFalse($this->pdo->committed());
    }

    #[Test]
    public function create_rejects_an_out_of_stock_product(): void
    {
        $this->givenNewOrderId(42);
        $this->givenProduct(1, 'Widget', '10.00', 0);

        [$status, $body] = $this->callCreate(self::ONE_WIDGET);

        $this->assertRejectedAndRolledBack($status, $body, 'Insufficient stock for Widget');
    }

    #[Test]
    #[DataProvider('stockComparisons')]
    public function create_compares_stock_to_quantity_loosely(int|string $stock, string $quantityJson, bool $accepted): void
    {
        $this->givenNewOrderId(42);
        $this->givenProduct(1, 'Widget', '10.00', $stock);
        $this->givenStoredOrder(42, ['id' => 42], []);

        [$status, $body] = $this->callCreate('{"items":[{"product_id":1,"quantity":' . $quantityJson . '}]}');

        if ($accepted) {
            self::assertSame(201, $status);
            self::assertSame(['begin', 'commit'], $this->pdo->transactionEvents());
            self::assertCount(1, $this->publisher->calls());
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
            'string stock equals int quantity' => ['5', '5', true],
            'string stock below int quantity' => ['4', '5', false],
            'int stock below string quantity' => [5, '"6"', false],
            'numeric strings compare numerically (10 >= 9)' => ['10', '"9"', true],
            'numeric strings compare numerically (9 < 10)' => ['9', '"10"', false],
            'fractional quantity above stock is rejected' => [5, '5.5', false],
            'fractional quantity below stock is accepted' => [5, '4.5', true],
        ];
    }

    #[Test]
    public function create_rolls_everything_back_when_a_later_item_is_insufficient_without_publishing_or_invalidating(): void
    {
        $this->givenNewOrderId(42);
        $this->givenProduct(1, 'Widget', '10.00', 10);
        $this->givenProduct(2, 'Gadget', '5.00', 1);

        [$status, $body] = $this->callCreate([
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
        self::assertSame([], $this->redis->callsTo('del'));
    }

    /**
     * @param array<string, mixed> $secondItem
     */
    #[Test]
    #[DataProvider('failingSecondItems')]
    public function create_rolls_back_when_the_second_item_is_invalid(array $secondItem, string $expectedMessage): void
    {
        $this->givenNewOrderId(42);
        $this->givenProduct(1, 'Widget', '10.00', 10);

        [$status, $body] = $this->callCreate(['items' => [['product_id' => 1, 'quantity' => 1], $secondItem]]);

        $this->assertRejectedAndRolledBack($status, $body, $expectedMessage);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function failingSecondItems(): array
    {
        return [
            'unknown product' => [['product_id' => 77, 'quantity' => 1], 'Product 77 not found'],
            'missing quantity' => [['product_id' => 1], 'Each item needs product_id and quantity'],
            'zero quantity' => [['product_id' => 1, 'quantity' => 0], 'Quantity must be at least 1'],
            'too many' => [['product_id' => 1, 'quantity' => 11], 'Insufficient stock for Widget'],
        ];
    }

    #[Test]
    public function create_reports_only_the_first_failing_item(): void
    {
        $this->givenNewOrderId(42);

        [$status, $body] = $this->callCreate([
            'items' => [
                ['product_id' => 1, 'quantity' => 0],
                ['product_id' => 2],
            ],
        ]);

        $this->assertRejectedAndRolledBack($status, $body, 'Quantity must be at least 1');
    }

    #[Test]
    public function create_json_encodes_the_product_name_in_the_error_message(): void
    {
        $this->givenNewOrderId(42);
        $this->givenProduct(1, 'Caf' . "\u{e9}" . ' "Deluxe"/XL', '10.00', 0);

        [$status, $body] = $this->callCreate(self::ONE_WIDGET);

        self::assertSame(400, $status);
        self::assertSame(
            json_encode(['error' => 'Insufficient stock for Caf' . "\u{e9}" . ' "Deluxe"/XL']),
            $body
        );
    }

    #[Test]
    public function create_treats_an_invalid_argument_exception_from_the_database_layer_as_a_400(): void
    {
        $this->givenNewOrderId(42);
        $this->givenProduct(1, 'Widget', '10.00', 10);
        $this->pdo->onSql(self::SQL_UPDATE_STOCK)->throws(new InvalidArgumentException('driver says no'));

        [$status, $body] = $this->callCreate(self::ONE_WIDGET);

        $this->assertRejectedAndRolledBack($status, $body, 'driver says no');
    }

    // ------------------------------------------------------------------
    // create(): unexpected failures (500 'Order creation failed')
    // ------------------------------------------------------------------

    #[Test]
    public function create_returns_500_and_rolls_back_when_inserting_the_order_row_fails(): void
    {
        $this->pdo->onSql(self::SQL_INSERT_ORDER)->throws(new RuntimeException('connection lost'));

        [$status, $body] = $this->callCreate(self::ONE_WIDGET);

        $this->assertFailedAndRolledBack($status, $body);
    }

    #[Test]
    public function create_does_not_leak_the_message_of_a_database_error(): void
    {
        $this->givenNewOrderId(42);
        $this->givenProduct(1, 'Widget', '10.00', 10);
        $this->pdo->onSql(self::SQL_INSERT_ITEM)->throws(
            ScriptedPdo::sqlStateException('23000', 'Integrity constraint violation: secret detail')
        );

        [$status, $body] = $this->callCreate(self::ONE_WIDGET);

        $this->assertFailedAndRolledBack($status, $body);
        self::assertStringNotContainsString('secret', $body);
    }

    #[Test]
    #[DataProvider('failingStatements')]
    public function create_returns_500_and_rolls_back_when_any_statement_in_the_transaction_throws(string $failingSql): void
    {
        $this->givenNewOrderId(42);
        $this->givenProduct(1, 'Widget', '10.00', 10);
        $this->pdo->onSql($failingSql)->throws(new PDOException('boom'));

        [$status, $body] = $this->callCreate(self::ONE_WIDGET);

        $this->assertFailedAndRolledBack($status, $body);
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
    #[DataProvider('unexpectedExceptions')]
    public function create_maps_any_non_argument_exception_inside_the_transaction_to_a_generic_500(Throwable $failure): void
    {
        $this->givenNewOrderId(42);
        $this->givenProduct(1, 'Widget', '10.00', 10);
        $this->pdo->onSql(self::SQL_UPDATE_STOCK)->throws($failure);

        [$status, $body] = $this->callCreate(self::ONE_WIDGET);

        $this->assertFailedAndRolledBack($status, $body);
        self::assertStringNotContainsString($failure->getMessage(), $body);
    }

    /** @return array<string, array{Throwable}> */
    public static function unexpectedExceptions(): array
    {
        return [
            'runtime exception' => [new RuntimeException('runtime detail')],
            'logic exception' => [new LogicException('logic detail')],
            'domain exception' => [new DomainException('domain detail')],
            'unexpected value exception' => [new UnexpectedValueException('value detail')],
            'pdo exception' => [new PDOException('pdo detail')],
        ];
    }

    #[Test]
    public function create_returns_500_and_rolls_back_after_a_failed_commit_without_touching_cache_or_publisher(): void
    {
        $this->givenNewOrderId(42);
        $this->givenProduct(1, 'Widget', '10.00', 10);
        $this->pdo->failCommitWith(new PDOException('commit failed'));

        [$status, $body] = $this->callCreate(self::ONE_WIDGET);

        self::assertSame(500, $status);
        self::assertSame('{"error":"Order creation failed"}', $body);
        self::assertSame(['begin', 'commit', 'rollback'], $this->pdo->transactionEvents());
        self::assertSame([], $this->redis->calls());
        self::assertSame([], $this->publisher->calls());
        $this->assertNoAmqpActivity();
    }

    // ------------------------------------------------------------------
    // create(): success path
    // ------------------------------------------------------------------

    #[Test]
    public function create_runs_the_exact_statement_sequence_for_a_single_item_then_reads_the_order_back(): void
    {
        $this->givenCreatableOrder();

        $this->callCreate('{"items":[{"product_id":1,"quantity":2}]}');

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
    public function create_commits_once_and_never_rolls_back_on_success(): void
    {
        $this->givenCreatableOrder();

        $this->callCreate(self::ONE_WIDGET);

        self::assertSame(['begin', 'commit'], $this->pdo->transactionEvents());
        self::assertTrue($this->pdo->committed());
        self::assertFalse($this->pdo->rolledBack());
    }

    #[Test]
    public function create_responds_201_with_exactly_the_output_of_get_for_the_new_order(): void
    {
        $order = ['id' => 42, 'total' => '39.98', 'created_at' => '2026-03-01 09:30:00'];
        $items = [
            ['id' => 100, 'order_id' => 42, 'product_id' => 1, 'quantity' => 2, 'price' => '19.99', 'product_name' => 'Widget'],
        ];
        $this->givenNewOrderId(42);
        $this->givenProduct(1, 'Widget', '19.99', 10);
        $this->givenStoredOrder(42, $order, $items);
        $controller = $this->controller();

        [$createStatus, $createBody] = $this->callCreate('{"items":[{"product_id":1,"quantity":2}]}', $controller);
        [$getStatus, $getBody] = $this->callGet(42, $controller);

        self::assertSame(201, $createStatus);
        self::assertSame(200, $getStatus);
        self::assertSame($getBody, $createBody);
        self::assertSame(json_encode($order + ['items' => $items]), $createBody);
    }

    #[Test]
    public function create_binds_the_order_id_returned_by_the_database_as_an_int(): void
    {
        $this->givenNewOrderId(1234);
        $this->givenProduct(1, 'Widget', '10.00', 10);
        $this->givenStoredOrder(1234, ['id' => 1234], []);

        $this->callCreate(self::ONE_WIDGET);

        self::assertSame([[1234, 1, 1, '10.00']], $this->pdo->paramsOf(self::SQL_INSERT_ITEM));
        self::assertSame([[10.0, 1234]], $this->pdo->paramsOf(self::SQL_UPDATE_TOTAL));
        self::assertSame([[1234]], $this->pdo->paramsOf(self::SQL_SELECT_ORDER));
        self::assertSame([[1234]], $this->pdo->paramsOf(self::SQL_ITEMS_FRAGMENT));
        self::assertSame(1234, $this->publisher->calls()[0][1]['order_id']);
    }

    #[Test]
    public function create_responds_404_when_the_committed_order_cannot_be_read_back_but_still_publishes(): void
    {
        $this->givenNewOrderId(42);
        $this->givenProduct(1, 'Widget', '10.00', 10);

        [$status, $body] = $this->callCreate(self::ONE_WIDGET);

        self::assertSame(404, $status);
        self::assertSame('{"error":"Order not found"}', $body);
        self::assertSame(['begin', 'commit'], $this->pdo->transactionEvents());
        self::assertCount(1, $this->publisher->calls());
    }

    #[Test]
    public function create_sums_the_float_line_totals_using_database_prices_across_items(): void
    {
        $this->givenNewOrderId(42);
        $this->givenProduct(1, 'Widget', '19.99', 10);
        $this->givenProduct(2, 'Gadget', '5.50', 10);
        $this->givenProduct(3, 'Gizmo', 0.1, 10);
        $this->givenStoredOrder(42, ['id' => 42], []);

        $this->callCreate([
            'items' => [
                ['product_id' => 1, 'quantity' => 2, 'price' => 0.01],
                ['product_id' => 2, 'quantity' => 3],
                ['product_id' => 3, 'quantity' => 7],
            ],
        ]);

        $expected = 0 + (float) '19.99' * 2;
        $expected += (float) '5.50' * 3;
        $expected += 0.1 * 7;

        self::assertSame([[$expected, 42]], $this->pdo->paramsOf(self::SQL_UPDATE_TOTAL));
        self::assertSame($expected, $this->publisher->calls()[0][1]['total']);
        self::assertSame(
            [[42, 1, 2, '19.99'], [42, 2, 3, '5.50'], [42, 3, 7, 0.1]],
            $this->pdo->paramsOf(self::SQL_INSERT_ITEM)
        );
        self::assertSame([[2, 1], [3, 2], [7, 3]], $this->pdo->paramsOf(self::SQL_UPDATE_STOCK));
    }

    #[Test]
    public function create_processes_items_one_at_a_time_in_request_order(): void
    {
        $this->givenNewOrderId(42);
        $this->givenProduct(2, 'Gadget', '5.00', 10);
        $this->givenProduct(1, 'Widget', '10.00', 10);
        $this->givenStoredOrder(42, ['id' => 42], []);

        $this->callCreate(['items' => [['product_id' => 2, 'quantity' => 1], ['product_id' => 1, 'quantity' => 1]]]);

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
    public function create_accepts_items_encoded_as_a_json_object(): void
    {
        $this->givenCreatableOrder();

        [$status] = $this->callCreate('{"items":{"a":{"product_id":1,"quantity":1}}}');

        self::assertSame(201, $status);
        self::assertSame([[42, 1, 1, '19.99']], $this->pdo->paramsOf(self::SQL_INSERT_ITEM));
    }

    // ------------------------------------------------------------------
    // create(): cache invalidation after the commit
    // ------------------------------------------------------------------

    #[Test]
    public function create_deletes_one_product_cache_key_per_item_then_the_list_key(): void
    {
        $this->givenNewOrderId(42);
        $this->givenProduct(3, 'Widget', '10.00', 10);
        $this->givenProduct(9, 'Gadget', '5.00', 10);
        $this->givenStoredOrder(42, ['id' => 42], []);

        $this->callCreate(['items' => [['product_id' => 9, 'quantity' => 1], ['product_id' => 3, 'quantity' => 1]]]);

        self::assertSame([['products:9'], ['products:3'], ['products:all']], $this->redis->callsTo('del'));
        self::assertSame(['del', 'del', 'del'], array_column($this->redis->calls(), 0));
    }

    #[Test]
    public function create_deletes_the_product_key_once_per_item_when_a_product_repeats(): void
    {
        $this->givenNewOrderId(42);
        $this->givenProduct(1, 'Widget', '10.00', 10);
        $this->givenStoredOrder(42, ['id' => 42], []);

        $this->callCreate(['items' => [['product_id' => 1, 'quantity' => 1], ['product_id' => 1, 'quantity' => 2]]]);

        self::assertSame([['products:1'], ['products:1'], ['products:all']], $this->redis->callsTo('del'));
    }

    #[Test]
    public function create_removes_stale_cache_entries_and_never_reads_or_writes_the_cache(): void
    {
        $this->givenCreatableOrder();
        $this->redis->seed('products:1', 'stale')->seed('products:all', 'stale')->seed('products:2', 'untouched');

        $this->callCreate(self::ONE_WIDGET);

        self::assertNull($this->redis->stored('products:1'));
        self::assertNull($this->redis->stored('products:all'));
        self::assertSame('untouched', $this->redis->stored('products:2'));
        self::assertSame([], $this->redis->callsTo('setex'));
        self::assertSame([], $this->redis->callsTo('get'));
    }

    #[Test]
    public function create_invalidates_the_caches_only_after_the_commit_and_before_publishing(): void
    {
        $this->givenNewOrderId(42);
        $this->givenProduct(1, 'Widget', '10.00', 10);
        $this->givenProduct(2, 'Gadget', '5.00', 10);
        $this->givenStoredOrder(42, ['id' => 42], []);

        $atPublish = null;
        $publisher = function (string $queue, array $payload) use (&$atPublish): void {
            $atPublish = [
                'transaction' => $this->pdo->transactionEvents(),
                'del' => $this->redis->callsTo('del'),
                'readBack' => $this->pdo->wasExecuted(self::SQL_SELECT_ORDER),
            ];
            ($this->publisher)($queue, $payload);
        };

        $this->callCreate(
            ['items' => [['product_id' => 1, 'quantity' => 1], ['product_id' => 2, 'quantity' => 1]]],
            $this->controller($publisher)
        );

        self::assertSame(
            [
                'transaction' => ['begin', 'commit'],
                'del' => [['products:1'], ['products:2'], ['products:all']],
                'readBack' => false,
            ],
            $atPublish
        );
        self::assertCount(1, $this->publisher->calls());
    }

    #[Test]
    public function create_does_not_invalidate_the_cache_when_the_commit_fails(): void
    {
        $this->givenNewOrderId(42);
        $this->givenProduct(1, 'Widget', '10.00', 10);
        $this->pdo->failCommitWith(new RuntimeException('commit failed'));
        $this->redis->seed('products:1', 'cached')->seed('products:all', 'cached');

        $this->callCreate(self::ONE_WIDGET);

        self::assertSame('cached', $this->redis->stored('products:1'));
        self::assertSame('cached', $this->redis->stored('products:all'));
        self::assertSame([], $this->redis->callsTo('del'));
    }

    #[Test]
    public function create_rolls_back_and_skips_the_publish_when_cache_invalidation_fails_after_commit(): void
    {
        $this->givenNewOrderId(42);
        $this->givenProduct(1, 'Widget', '10.00', 10);
        $this->redis->failOn('del', new RuntimeException('redis down'));

        [$status, $body] = $this->callCreate(self::ONE_WIDGET);

        self::assertSame(500, $status);
        self::assertSame('{"error":"Order creation failed"}', $body);
        self::assertSame(['begin', 'commit', 'rollback'], $this->pdo->transactionEvents());
        self::assertSame([], $this->publisher->calls());
        $this->assertNoAmqpActivity();
    }

    // ------------------------------------------------------------------
    // create(): the injected publisher
    // ------------------------------------------------------------------

    #[Test]
    public function create_calls_the_injected_publisher_exactly_once_with_the_order_created_payload(): void
    {
        $this->givenCreatableOrder();

        $before = new DateTimeImmutable('now');
        $this->callCreate('{"items":[{"product_id":1,"quantity":2}]}');
        $after = new DateTimeImmutable('now');

        $calls = $this->publisher->calls();
        self::assertCount(1, $calls);
        [$queue, $payload] = $calls[0];

        self::assertSame('order_created', $queue);
        self::assertSame(['order_id', 'total', 'created_at'], array_keys($payload));
        self::assertSame(42, $payload['order_id']);
        self::assertIsFloat($payload['total']);
        self::assertSame(0 + (float) '19.99' * 2, $payload['total']);
        self::assertIsString($payload['created_at']);

        $createdAt = DateTimeImmutable::createFromFormat(DATE_ATOM, $payload['created_at']);
        self::assertInstanceOf(DateTimeImmutable::class, $createdAt);
        self::assertSame($payload['created_at'], $createdAt->format('c'));
        self::assertGreaterThanOrEqual($before->getTimestamp() - 1, $createdAt->getTimestamp());
        self::assertLessThanOrEqual($after->getTimestamp() + 1, $createdAt->getTimestamp());
    }

    #[Test]
    public function create_publishes_only_after_the_transaction_has_been_committed(): void
    {
        $this->givenCreatableOrder();

        $eventsAtPublish = null;
        $executedAtPublish = null;
        $publisher = function (string $queue, array $payload) use (&$eventsAtPublish, &$executedAtPublish): void {
            $eventsAtPublish = $this->pdo->transactionEvents();
            $executedAtPublish = $this->executedSql();
            ($this->publisher)($queue, $payload);
        };

        $this->callCreate(self::ONE_WIDGET, $this->controller($publisher));

        self::assertSame(['begin', 'commit'], $eventsAtPublish);
        self::assertSame(
            [
                self::SQL_INSERT_ORDER,
                self::SQL_SELECT_PRODUCT,
                self::SQL_INSERT_ITEM,
                self::SQL_UPDATE_STOCK,
                self::SQL_UPDATE_TOTAL,
            ],
            $executedAtPublish
        );
    }

    #[Test]
    public function create_publishes_before_reading_the_order_back_for_the_response(): void
    {
        $this->givenCreatableOrder();

        $readBackAtPublish = null;
        $publisher = function (string $queue, array $payload) use (&$readBackAtPublish): void {
            $readBackAtPublish = $this->pdo->wasExecuted(self::SQL_SELECT_ORDER);
            ($this->publisher)($queue, $payload);
        };

        $this->callCreate(self::ONE_WIDGET, $this->controller($publisher));

        self::assertFalse($readBackAtPublish);
        self::assertTrue($this->pdo->wasExecuted(self::SQL_SELECT_ORDER));
    }

    #[Test]
    public function create_publishes_nothing_for_rejected_requests(): void
    {
        $this->givenNewOrderId(42);
        $this->givenProduct(1, 'Widget', '10.00', 1);

        $this->callCreate('{"items":[]}');
        $this->callCreate('{"items":[{"product_id":1,"quantity":5}]}');
        $this->callCreate('{"items":[{"product_id":404,"quantity":1}]}');

        self::assertSame([], $this->publisher->calls());
        self::assertSame([], $this->redis->calls());
    }

    #[Test]
    public function create_uses_the_injected_publisher_and_never_opens_an_amqp_connection_or_channel(): void
    {
        $this->givenCreatableOrder();
        QueueReset::install(null);

        [$status] = $this->callCreate(self::ONE_WIDGET);

        self::assertSame(201, $status);
        self::assertCount(1, $this->publisher->calls());
        // Queue::connect() was never reached: it would have created a connection and stored it.
        self::assertNull(QueueReset::current());
        $this->assertNoAmqpActivity();
    }

    #[Test]
    public function create_leaves_the_queue_static_untouched_when_a_publisher_is_injected(): void
    {
        $this->givenCreatableOrder();
        $installed = QueueReset::current();

        $this->callCreate(self::ONE_WIDGET);

        self::assertSame($installed, QueueReset::current());
        self::assertSame($this->amqp->connection(), QueueReset::current());
        $this->assertNoAmqpActivity();
    }

    /** @param callable(ArrayObject<int, array{0: string, 1: array<mixed>}>): callable $factory */
    #[Test]
    #[DataProvider('publisherCallableKinds')]
    public function create_accepts_any_php_callable_as_the_publisher(callable $factory): void
    {
        $this->givenCreatableOrder();
        /** @var ArrayObject<int, array{0: string, 1: array<mixed>}> $received */
        $received = new ArrayObject();

        [$status] = $this->callCreate(self::ONE_WIDGET, $this->controller($factory($received)));

        self::assertSame(201, $status);
        self::assertCount(1, $received);
        self::assertSame('order_created', $received[0][0]);
        self::assertSame(42, $received[0][1]['order_id']);
        $this->assertNoAmqpActivity();
    }

    /** @return array<string, array{callable}> */
    public static function publisherCallableKinds(): array
    {
        return [
            'closure' => [static fn (ArrayObject $received): callable => static function (string $queue, array $payload) use ($received): void {
                $received[] = [$queue, $payload];
            }],
            'invokable object' => [static fn (ArrayObject $received): callable => new class ($received) {
                public function __construct(private readonly ArrayObject $received)
                {
                }

                public function __invoke(string $queue, array $payload): void
                {
                    $this->received[] = [$queue, $payload];
                }
            }],
            'array callable' => [static fn (ArrayObject $received): callable => [new class ($received) {
                public function __construct(private readonly ArrayObject $received)
                {
                }

                public function record(string $queue, array $payload): void
                {
                    $this->received[] = [$queue, $payload];
                }
            }, 'record']],
            'first class callable' => [static fn (ArrayObject $received): callable => (new class ($received) {
                public function __construct(private readonly ArrayObject $received)
                {
                }

                public function record(string $queue, array $payload): void
                {
                    $this->received[] = [$queue, $payload];
                }
            })->record(...)],
        ];
    }

    // ------------------------------------------------------------------
    // create(): publisher failures after the commit (quirks, kept as-is)
    // ------------------------------------------------------------------

    #[Test]
    public function create_returns_500_with_a_rollback_after_the_commit_when_the_publisher_throws(): void
    {
        $this->givenCreatableOrder();
        $failing = (new PublisherSpy())->failWith(new RuntimeException('broker down'));

        [$status, $body] = $this->callCreate(self::ONE_WIDGET, $this->controller($failing));

        self::assertSame(500, $status);
        self::assertSame('{"error":"Order creation failed"}', $body);
        self::assertSame(['begin', 'commit', 'rollback'], $this->pdo->transactionEvents());
        self::assertCount(1, $failing->calls());
        self::assertStringNotContainsString('broker down', $body);
    }

    #[Test]
    public function create_has_already_invalidated_the_caches_when_the_publisher_throws(): void
    {
        $this->givenNewOrderId(42);
        $this->givenProduct(1, 'Widget', '10.00', 10);
        $this->givenProduct(2, 'Gadget', '5.00', 10);
        $this->redis->seed('products:1', 'cached')->seed('products:2', 'cached')->seed('products:all', 'cached');
        $failing = (new PublisherSpy())->failWith(new RuntimeException('broker down'));

        $this->callCreate(
            ['items' => [['product_id' => 1, 'quantity' => 1], ['product_id' => 2, 'quantity' => 1]]],
            $this->controller($failing)
        );

        self::assertSame([['products:1'], ['products:2'], ['products:all']], $this->redis->callsTo('del'));
        self::assertNull($this->redis->stored('products:1'));
        self::assertNull($this->redis->stored('products:2'));
        self::assertNull($this->redis->stored('products:all'));
        self::assertTrue($this->pdo->wasExecuted(self::SQL_UPDATE_TOTAL));
    }

    #[Test]
    public function create_never_reads_the_order_back_when_the_publisher_throws(): void
    {
        $this->givenCreatableOrder();
        $failing = (new PublisherSpy())->failWith(new RuntimeException('broker down'));

        [, $body] = $this->callCreate(self::ONE_WIDGET, $this->controller($failing));

        self::assertFalse($this->pdo->wasExecuted(self::SQL_SELECT_ORDER));
        self::assertStringNotContainsString('"id":42', $body);
    }

    #[Test]
    #[DataProvider('publisherFailures')]
    public function create_maps_a_publisher_exception_like_any_other_exception_after_the_commit(Throwable $failure, int $expectedStatus, string $expectedBody): void
    {
        $this->givenCreatableOrder();
        $failing = (new PublisherSpy())->failWith($failure);

        [$status, $body] = $this->callCreate(self::ONE_WIDGET, $this->controller($failing));

        self::assertSame($expectedStatus, $status);
        self::assertSame($expectedBody, $body);
        self::assertSame(['begin', 'commit', 'rollback'], $this->pdo->transactionEvents());
        self::assertSame([['products:1'], ['products:all']], $this->redis->callsTo('del'));
    }

    /** @return array<string, array{Throwable, int, string}> */
    public static function publisherFailures(): array
    {
        $generic = '{"error":"Order creation failed"}';

        return [
            'runtime exception' => [new RuntimeException('x'), 500, $generic],
            'amqp runtime exception' => [new AMQPRuntimeException('x'), 500, $generic],
            'pdo exception' => [new PDOException('x'), 500, $generic],
            'logic exception' => [new LogicException('x'), 500, $generic],
            'invalid argument exception' => [new InvalidArgumentException('bad payload'), 400, '{"error":"bad payload"}'],
        ];
    }

    #[Test]
    public function create_lets_a_php_error_from_the_publisher_escape_after_the_commit(): void
    {
        $this->givenCreatableOrder();
        $failing = (new PublisherSpy())->failWith(new \Error('not an Exception'));

        $escaped = null;
        try {
            $this->callCreate(self::ONE_WIDGET, $this->controller($failing));
        } catch (\Error $e) {
            $escaped = $e;
        }

        self::assertNotNull($escaped);
        self::assertSame('not an Exception', $escaped->getMessage());
        self::assertSame(['begin', 'commit'], $this->pdo->transactionEvents());
    }

    // ------------------------------------------------------------------
    // create(): the default publisher routes through App\Queue::publish
    // ------------------------------------------------------------------

    #[Test]
    public function the_default_publisher_declares_a_durable_queue_and_publishes_to_order_created(): void
    {
        $this->givenCreatableOrder();

        [$status] = $this->callCreate(self::ONE_WIDGET, new OrderController($this->pdo, $this->redis));

        self::assertSame(201, $status);
        // queue_declare($queue, passive: false, durable: true, exclusive: false, auto_delete: false)
        self::assertSame([['order_created', false, true, false, false]], $this->amqp->declaredQueues());
        self::assertCount(1, $this->amqp->published());
        $message = $this->amqp->published()[0];
        self::assertSame('order_created', $message['queue']);
        self::assertSame('', $message['exchange']);
        self::assertSame(2, $message['properties']['delivery_mode']);
        self::assertSame(1, $this->amqp->channelsOpened());
        self::assertSame(1, $this->amqp->channelsClosed());
    }

    #[Test]
    public function the_default_publisher_sends_the_json_payload_of_the_order(): void
    {
        $this->givenCreatableOrder();

        $this->callCreate('{"items":[{"product_id":1,"quantity":2}]}', new OrderController($this->pdo, $this->redis));

        $message = $this->amqp->published()[0];
        $total = 0 + (float) '19.99' * 2;
        self::assertMatchesRegularExpression(
            '/^\{"order_id":42,"total":' . preg_quote(json_encode($total), '/') . ',"created_at":"[^"]+"\}$/',
            $message['body']
        );
        $payload = $message['payload'];
        self::assertSame(['order_id', 'total', 'created_at'], array_keys($payload));
        self::assertSame(42, $payload['order_id']);
        self::assertSame($total, $payload['total']);
        $createdAt = DateTimeImmutable::createFromFormat(DATE_ATOM, $payload['created_at']);
        self::assertInstanceOf(DateTimeImmutable::class, $createdAt);
        self::assertSame($payload['created_at'], $createdAt->format('c'));
    }

    #[Test]
    public function the_default_publisher_is_also_used_when_null_is_passed_explicitly(): void
    {
        $this->givenCreatableOrder();

        $this->callCreate(self::ONE_WIDGET, new OrderController($this->pdo, $this->redis, null));

        self::assertCount(1, $this->amqp->published());
        self::assertSame('order_created', $this->amqp->published()[0]['queue']);
    }

    #[Test]
    public function the_default_publisher_publishes_after_the_commit_and_the_cache_invalidation(): void
    {
        $this->givenCreatableOrder();
        $atPublish = null;
        $this->amqp->beforePublish(function () use (&$atPublish): void {
            $atPublish = [
                'transaction' => $this->pdo->transactionEvents(),
                'del' => $this->redis->callsTo('del'),
            ];
        });

        $this->callCreate(self::ONE_WIDGET, new OrderController($this->pdo, $this->redis));

        self::assertSame(
            ['transaction' => ['begin', 'commit'], 'del' => [['products:1'], ['products:all']]],
            $atPublish
        );
    }

    #[Test]
    public function the_default_publisher_resolves_the_queue_connection_lazily_at_publish_time(): void
    {
        $this->givenCreatableOrder();
        QueueReset::install(null);
        $controller = new OrderController($this->pdo, $this->redis);
        // The connection only appears after construction; a publisher bound at construction would miss it.
        QueueReset::install($this->amqp->connection());

        $this->callCreate(self::ONE_WIDGET, $controller);

        self::assertCount(1, $this->amqp->published());
    }

    #[Test]
    public function the_default_publisher_does_not_publish_for_rejected_orders(): void
    {
        $this->givenNewOrderId(42);
        $this->givenProduct(1, 'Widget', '10.00', 1);
        $controller = new OrderController($this->pdo, $this->redis);

        $this->callCreate('{"items":[]}', $controller);
        $this->callCreate('{"items":[{"product_id":1,"quantity":2}]}', $controller);

        $this->assertNoAmqpActivity();
    }

    #[Test]
    public function the_default_publisher_failing_to_open_a_channel_commits_then_rolls_back_and_returns_500(): void
    {
        $this->givenCreatableOrder();
        $this->amqp->failChannelWith(new AMQPRuntimeException('broker down'));

        [$status, $body] = $this->callCreate(self::ONE_WIDGET, new OrderController($this->pdo, $this->redis));

        self::assertSame(500, $status);
        self::assertSame('{"error":"Order creation failed"}', $body);
        self::assertSame(['begin', 'commit', 'rollback'], $this->pdo->transactionEvents());
        self::assertSame([['products:1'], ['products:all']], $this->redis->callsTo('del'));
        self::assertSame([], $this->amqp->published());
        self::assertSame([], $this->amqp->declaredQueues());
    }

    #[Test]
    public function the_default_publisher_failing_to_publish_commits_then_rolls_back_and_returns_500(): void
    {
        $this->givenCreatableOrder();
        $this->amqp->failPublishWith(new AMQPRuntimeException('publish failed'));

        [$status, $body] = $this->callCreate(self::ONE_WIDGET, new OrderController($this->pdo, $this->redis));

        self::assertSame(500, $status);
        self::assertSame('{"error":"Order creation failed"}', $body);
        self::assertSame(['begin', 'commit', 'rollback'], $this->pdo->transactionEvents());
        self::assertSame([['order_created', false, true, false, false]], $this->amqp->declaredQueues());
        self::assertSame([], $this->amqp->published());
        self::assertSame(1, $this->amqp->channelsOpened());
        self::assertSame(0, $this->amqp->channelsClosed());
    }

    #[Test]
    public function the_default_publisher_produces_the_same_message_content_as_the_injected_one(): void
    {
        $this->givenCreatableOrder();

        $this->callCreate(self::ONE_WIDGET, new OrderController($this->pdo, $this->redis));
        $this->callCreate(self::ONE_WIDGET);

        $viaQueue = $this->amqp->published()[0]['payload'];
        [$queue, $viaSpy] = $this->publisher->calls()[0];

        self::assertSame('order_created', $queue);
        self::assertSame(array_keys($viaSpy), array_keys($viaQueue));
        self::assertSame($viaSpy['order_id'], $viaQueue['order_id']);
        // 19.99 * 1 round-trips through json as the same float.
        self::assertSame($viaSpy['total'], $viaQueue['total']);
    }
}
