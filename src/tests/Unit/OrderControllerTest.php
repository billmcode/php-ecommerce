<?php

namespace Tests\Unit;

use App\Controllers\OrderController;
use PhpAmqpLib\Message\AMQPMessage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ControllerTestCase;
use Tests\Support\FakePdo;
use Tests\Support\PhpInputStream;

/**
 * Branch matrix for App\Controllers\OrderController, driven directly (no Router).
 * Publisher and request body are injected, so Queue::$instance is only touched by
 * the tests that exercise the default publisher.
 */
final class OrderControllerTest extends ControllerTestCase
{
    private const LIST_ORDERS = 'SELECT * FROM orders ORDER BY id DESC';
    private const SELECT_ORDER = 'SELECT * FROM orders WHERE id = ?';
    private const SELECT_ITEMS = 'SELECT oi.*, p.name as product_name FROM order_items oi JOIN products p ON p.id = oi.product_id WHERE oi.order_id = ?';
    private const INSERT_ORDER = 'INSERT INTO orders (total) VALUES (0)';
    private const SELECT_FOR_UPDATE = 'SELECT * FROM products WHERE id = ? FOR UPDATE';
    private const INSERT_ITEM = 'INSERT INTO order_items (order_id, product_id, quantity, price) VALUES (?, ?, ?, ?)';
    private const UPDATE_STOCK = 'UPDATE products SET stock = stock - ? WHERE id = ?';
    private const UPDATE_TOTAL = 'UPDATE orders SET total = ? WHERE id = ?';

    private const ISO_8601 = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(Z|[+-]\d{2}:\d{2})$/';

    private const PRODUCTS = [
        5 => ['id' => 5, 'name' => 'Widget', 'description' => 'A widget', 'price' => '19.99', 'stock' => 10],
        7 => ['id' => 7, 'name' => 'Gadget', 'description' => '', 'price' => '5.50', 'stock' => 3],
    ];

    private const ORDER = ['id' => 42, 'total' => '45.48', 'created_at' => '2026-01-01 10:00:00'];
    private const ITEMS = [
        ['id' => 1, 'order_id' => 42, 'product_id' => 5, 'quantity' => 2, 'price' => '19.99', 'product_name' => 'Widget'],
        ['id' => 2, 'order_id' => 42, 'product_id' => 7, 'quantity' => 1, 'price' => '5.50', 'product_name' => 'Gadget'],
    ];

    private FakePdo $db;
    /** @var list<array{0: string, 1: array}> every publisher invocation */
    private array $published = [];
    /** @var list<array{events: array, redis: array, status: int, output: string}> state observed when the publisher ran */
    private array $atPublish = [];
    private ?\Throwable $publishError = null;
    private \Closure $publisher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = new FakePdo($this->mockPdo(), fn () => $this->createMock(\PDOStatement::class));
        $this->published = [];
        $this->atPublish = [];
        $this->publishError = null;
        $this->publisher = function (string $queue, array $payload): void {
            $this->published[] = [$queue, $payload];
            $this->atPublish[] = [
                'events' => $this->db->events,
                'redis' => $this->redis->calls,
                'status' => http_response_code() ?: 200,
                'output' => (string) ob_get_contents(),
            ];
            if ($this->publishError !== null) {
                throw $this->publishError;
            }
        };
    }

    protected function tearDown(): void
    {
        PhpInputStream::restore();
        $this->assertSame([], $this->db->unmatched, 'SQL that no test rule matched');
        parent::tearDown();
    }

    /** Controller whose request body is the given string and whose publisher records its calls. */
    private function controller(string $body = ''): OrderController
    {
        return new OrderController($this->db->pdo, $this->redis, $this->publisher, fn () => $body);
    }

    private function body(array $items): string
    {
        return json_encode(['items' => $items]);
    }

    /**
     * Registers every statement create() can issue. Product rows are served from PRODUCTS
     * with stock reduced by the stock UPDATEs already executed (like FOR UPDATE would see).
     */
    private function stubCreateStatements(): void
    {
        $this->db->withInsertIds('42');
        $this->db->onSql(self::INSERT_ORDER);
        $this->db->onSql(self::SELECT_FOR_UPDATE, fetch: function (array $params): mixed {
            $id = $params[0];
            $product = self::PRODUCTS[$id] ?? false;
            if ($product === false) {
                return false;
            }
            $decremented = 0;
            foreach ($this->db->paramsFor(self::UPDATE_STOCK) as [$quantity, $productId]) {
                if ((string) $productId === (string) $id) {
                    $decremented += (int) $quantity;
                }
            }
            $product['stock'] -= $decremented;
            return $product;
        });
        $this->db->onSql(self::INSERT_ITEM);
        $this->db->onSql(self::UPDATE_STOCK);
        $this->db->onSql(self::UPDATE_TOTAL);
        $this->db->onSql(self::SELECT_ORDER, fetch: self::ORDER);
        $this->db->onSql(self::SELECT_ITEMS, rows: self::ITEMS);
    }

    private function assertNothingTouched(): void
    {
        $this->assertFalse($this->db->hasInteractions());
        $this->assertSame([], $this->redis->calls);
        $this->assertSame([], $this->published);
    }

    /** Failure before commit: rolled back once, never committed, no cache invalidation, nothing published. */
    private function assertRolledBackBeforeCommit(int $status, string $error): void
    {
        $this->assertSame($status, $this->httpStatus());
        $this->assertSame(['error' => $error], $this->json());
        $this->assertSame(['beginTransaction', 'rollBack'], $this->db->events);
        $this->assertSame([], $this->redis->calls);
        $this->assertSame([], $this->published);
        $this->assertSame([], $this->db->paramsFor(self::UPDATE_TOTAL));
    }

    // --- list ---

    public function testListReturnsOrdersNewestFirst(): void
    {
        $rows = [
            ['id' => 2, 'total' => '5.50', 'created_at' => '2026-01-02 10:00:00'],
            ['id' => 1, 'total' => '45.48', 'created_at' => '2026-01-01 10:00:00'],
        ];
        $this->db->onSql(self::LIST_ORDERS, rows: $rows);

        $this->controller()->list();

        $this->assertSame(200, $this->httpStatus());
        $this->assertSame(json_encode($rows), $this->capturedOutput());
        $this->assertSame([self::LIST_ORDERS], $this->db->queried);
        $this->assertSame([], $this->db->prepared);
        $this->assertSame([], $this->redis->calls);
        $this->assertSame([], $this->published);
    }

    public function testListWithNoOrdersReturnsEmptyArray(): void
    {
        $this->db->onSql(self::LIST_ORDERS, rows: []);

        $this->controller()->list();

        $this->assertSame(200, $this->httpStatus());
        $this->assertSame('[]', $this->capturedOutput());
    }

    // --- get ---

    public function testGetUnknownOrderReturns404WithoutLoadingItems(): void
    {
        $this->db->onSql(self::SELECT_ORDER, fetch: false);

        $this->controller()->get(7);

        $this->assertSame(404, $this->httpStatus());
        $this->assertSame('{"error":"Order not found"}', $this->capturedOutput());
        $this->assertSame([self::SELECT_ORDER], $this->db->prepared);
        $this->assertSame([[7]], $this->db->paramsFor(self::SELECT_ORDER));
        $this->assertSame([], $this->redis->calls);
    }

    public function testGetReturnsOrderWithItemsJoinedWithProductName(): void
    {
        $this->db->onSql(self::SELECT_ORDER, fetch: self::ORDER);
        $this->db->onSql(self::SELECT_ITEMS, rows: self::ITEMS);

        $this->controller()->get(42);

        $this->assertSame(200, $this->httpStatus());
        $this->assertSame(json_encode(self::ORDER + ['items' => self::ITEMS]), $this->capturedOutput());
        $this->assertSame([self::SELECT_ORDER, self::SELECT_ITEMS], $this->db->prepared);
        $this->assertSame([[42]], $this->db->paramsFor(self::SELECT_ORDER));
        $this->assertSame([[42]], $this->db->paramsFor(self::SELECT_ITEMS));
        $this->assertSame([], $this->redis->calls);
        $this->assertSame([], $this->published);
    }

    public function testGetOrderWithoutItemsHasEmptyItemsArray(): void
    {
        $this->db->onSql(self::SELECT_ORDER, fetch: self::ORDER);
        $this->db->onSql(self::SELECT_ITEMS, rows: []);

        $this->controller()->get(42);

        $this->assertSame(200, $this->httpStatus());
        $this->assertSame(json_encode(self::ORDER + ['items' => []]), $this->capturedOutput());
    }

    // --- create: "items array is required" (before any transaction) ---

    public static function missingItemsBodyProvider(): array
    {
        return [
            'empty body' => [''],
            'invalid JSON' => ['not json'],
            'truncated JSON' => ['{"items":[{"product_id":5'],
            'JSON null' => ['null'],
            'JSON scalar number' => ['123'],
            'JSON scalar string' => ['"items"'],
            'empty object' => ['{}'],
            'empty list' => ['[]'],
            'items key missing' => ['{"other":[{"product_id":5,"quantity":1}]}'],
            'items null' => ['{"items":null}'],
            'items string' => ['{"items":"x"}'],
            'items number' => ['{"items":5}'],
            'items true' => ['{"items":true}'],
            'items empty array' => ['{"items":[]}'],
            'items empty object' => ['{"items":{}}'],
        ];
    }

    #[DataProvider('missingItemsBodyProvider')]
    public function testCreateRequiresItemsArrayBeforeAnyTransaction(string $body): void
    {
        $this->controller($body)->create();

        $this->assertSame(400, $this->httpStatus());
        $this->assertSame('{"error":"items array is required"}', $this->capturedOutput());
        $this->assertNothingTouched();
        $this->assertSame([], $this->db->events);
    }

    // --- create: validation failures inside the transaction ---

    public static function incompleteItemProvider(): array
    {
        return [
            'missing product_id' => ['[{"quantity":1}]'],
            'missing quantity' => ['[{"product_id":5}]'],
            'empty item' => ['[{}]'],
            'null product_id' => ['[{"product_id":null,"quantity":1}]'],
            'null quantity' => ['[{"product_id":5,"quantity":null}]'],
            'both null' => ['[{"product_id":null,"quantity":null}]'],
            'scalar item' => ['[5]'],
            'string item' => ['["abc"]'],
            'null item' => ['[null]'],
        ];
    }

    #[DataProvider('incompleteItemProvider')]
    public function testCreateItemWithoutProductIdAndQuantityRollsBackWith400(string $items): void
    {
        $this->stubCreateStatements();

        $this->controller('{"items":' . $items . '}')->create();

        $this->assertRolledBackBeforeCommit(400, 'Each item needs product_id and quantity');
        $this->assertSame([self::INSERT_ORDER], $this->db->prepared);
    }

    public function testCreateIncompleteSecondItemRollsBackAfterFirstItemWasProcessed(): void
    {
        $this->stubCreateStatements();

        $this->controller($this->body([['product_id' => 5, 'quantity' => 2], ['product_id' => 7]]))->create();

        $this->assertRolledBackBeforeCommit(400, 'Each item needs product_id and quantity');
        $this->assertSame([[42, 5, 2, '19.99']], $this->db->paramsFor(self::INSERT_ITEM));
        $this->assertSame([[2, 5]], $this->db->paramsFor(self::UPDATE_STOCK));
    }

    public static function invalidQuantityProvider(): array
    {
        return [
            'zero' => ['0'],
            'negative' => ['-1'],
            'large negative' => ['-100'],
            'string zero' => ['"0"'],
            'non-numeric string' => ['"abc"'],
            'fraction below one' => ['0.5'],
            'false' => ['false'],
        ];
    }

    #[DataProvider('invalidQuantityProvider')]
    public function testCreateQuantityBelowOneRollsBackWith400BeforeLockingProduct(string $quantity): void
    {
        $this->stubCreateStatements();

        // Product 99 does not exist: the quantity check must win over the product lookup.
        $this->controller('{"items":[{"product_id":99,"quantity":' . $quantity . '}]}')->create();

        $this->assertRolledBackBeforeCommit(400, 'Quantity must be at least 1');
        $this->assertSame([self::INSERT_ORDER], $this->db->prepared);
    }

    public static function unknownProductProvider(): array
    {
        return [
            'unknown id' => [99, 'Product 99 not found'],
            'zero id' => [0, 'Product 0 not found'],
            'string id' => ['abc', 'Product abc not found'],
        ];
    }

    #[DataProvider('unknownProductProvider')]
    public function testCreateUnknownProductRollsBackWith400(int|string $productId, string $message): void
    {
        $this->stubCreateStatements();

        $this->controller($this->body([['product_id' => $productId, 'quantity' => 1]]))->create();

        $this->assertRolledBackBeforeCommit(400, $message);
        $this->assertSame([self::INSERT_ORDER, self::SELECT_FOR_UPDATE], $this->db->prepared);
        $this->assertSame([[$productId]], $this->db->paramsFor(self::SELECT_FOR_UPDATE));
        $this->assertSame([], $this->db->paramsFor(self::INSERT_ITEM));
    }

    public function testCreateInsufficientStockRollsBackWith400NamingTheProduct(): void
    {
        $this->stubCreateStatements();

        $this->controller($this->body([['product_id' => 7, 'quantity' => 4]]))->create();

        $this->assertRolledBackBeforeCommit(400, 'Insufficient stock for Gadget');
        $this->assertSame([self::INSERT_ORDER, self::SELECT_FOR_UPDATE], $this->db->prepared);
        $this->assertSame([], $this->db->paramsFor(self::INSERT_ITEM));
        $this->assertSame([], $this->db->paramsFor(self::UPDATE_STOCK));
    }

    public function testCreateFailureOnLaterItemRollsBackAfterEarlierItemsWereProcessed(): void
    {
        $this->stubCreateStatements();

        $this->controller($this->body([
            ['product_id' => 5, 'quantity' => 2],
            ['product_id' => 7, 'quantity' => 1],
            ['product_id' => 99, 'quantity' => 1],
        ]))->create();

        $this->assertRolledBackBeforeCommit(400, 'Product 99 not found');
        $this->assertSame([[42, 5, 2, '19.99'], [42, 7, 1, '5.50']], $this->db->paramsFor(self::INSERT_ITEM));
        $this->assertSame([[2, 5], [1, 7]], $this->db->paramsFor(self::UPDATE_STOCK));
        $this->assertSame([[5], [7], [99]], $this->db->paramsFor(self::SELECT_FOR_UPDATE));
    }

    public function testCreateInsufficientStockOnLaterItemRollsBackAfterEarlierItemsWereProcessed(): void
    {
        $this->stubCreateStatements();

        $this->controller($this->body([
            ['product_id' => 5, 'quantity' => 2],
            ['product_id' => 7, 'quantity' => 4],
        ]))->create();

        $this->assertRolledBackBeforeCommit(400, 'Insufficient stock for Gadget');
        $this->assertSame([[42, 5, 2, '19.99']], $this->db->paramsFor(self::INSERT_ITEM));
        $this->assertSame([[2, 5]], $this->db->paramsFor(self::UPDATE_STOCK));
    }

    public function testCreateDuplicateProductIdsCannotOverdrawStock(): void
    {
        $this->stubCreateStatements();

        // Stock is 10: the second line sees the stock left by the first one.
        $this->controller($this->body([
            ['product_id' => 5, 'quantity' => 6],
            ['product_id' => 5, 'quantity' => 6],
        ]))->create();

        $this->assertRolledBackBeforeCommit(400, 'Insufficient stock for Widget');
        $this->assertSame([[42, 5, 6, '19.99']], $this->db->paramsFor(self::INSERT_ITEM));
        $this->assertSame([[6, 5]], $this->db->paramsFor(self::UPDATE_STOCK));
    }

    // --- create: unexpected failures ---

    public static function midFlightFailureProvider(): array
    {
        return [
            'order insert PDOException' => [self::INSERT_ORDER, new \PDOException('SQLSTATE[HY000]: secret detail')],
            'product lock RuntimeException' => [self::SELECT_FOR_UPDATE, new \RuntimeException('lock wait timeout')],
            'item insert PDOException' => [self::INSERT_ITEM, new \PDOException('SQLSTATE[23000]: constraint')],
            'stock update Exception' => [self::UPDATE_STOCK, new \Exception('deadlock')],
            'total update RuntimeException' => [self::UPDATE_TOTAL, new \RuntimeException('connection lost')],
        ];
    }

    #[DataProvider('midFlightFailureProvider')]
    public function testCreateUnexpectedExceptionRollsBackWith500AndHidesTheMessage(string $failingSql, \Throwable $error): void
    {
        $this->stubCreateStatements();
        $this->db->onSql($failingSql, executeThrows: $error);

        $this->controller($this->body([['product_id' => 5, 'quantity' => 2]]))->create();

        $this->assertSame(500, $this->httpStatus());
        $this->assertSame('{"error":"Order creation failed"}', $this->capturedOutput());
        $this->assertSame(['beginTransaction', 'rollBack'], $this->db->events);
        $this->assertSame([], $this->redis->calls);
        $this->assertSame([], $this->published);
    }

    public function testCreateUnexpectedExceptionPropagatesRollBackFailure(): void
    {
        $this->stubCreateStatements();
        $this->db->onSql(self::INSERT_ITEM, executeThrows: new \RuntimeException('boom'));
        $this->db->rollBackThrows(new \PDOException('There is no active transaction'));

        try {
            $this->controller($this->body([['product_id' => 5, 'quantity' => 2]]))->create();
            $this->fail('rollBack() failure must propagate out of create()');
        } catch (\PDOException $e) {
            $this->assertSame('There is no active transaction', $e->getMessage());
        }

        $this->assertSame(['beginTransaction', 'rollBack'], $this->db->events);
        $this->assertSame('', $this->capturedOutput());
    }

    // --- create: success ---

    public function testCreateSuccessProcessesEveryItemAndReturnsTheNewOrder(): void
    {
        $this->stubCreateStatements();
        $expectedTotal = 0 + (float) '19.99' * 2 + (float) '5.50' * 1;
        $this->assertEqualsWithDelta(45.48, $expectedTotal, 0.0000001);

        $before = time();
        $this->controller($this->body([
            ['product_id' => 5, 'quantity' => 2],
            ['product_id' => 7, 'quantity' => 1],
        ]))->create();
        $after = time();

        // Response: 201 and exactly the get() body for the new order (with items).
        $this->assertSame(201, $this->httpStatus());
        $this->assertSame(json_encode(self::ORDER + ['items' => self::ITEMS]), $this->capturedOutput());

        // Statement sequence and bound parameters.
        $this->assertSame([
            self::INSERT_ORDER,
            self::SELECT_FOR_UPDATE,
            self::INSERT_ITEM,
            self::UPDATE_STOCK,
            self::SELECT_FOR_UPDATE,
            self::INSERT_ITEM,
            self::UPDATE_STOCK,
            self::UPDATE_TOTAL,
            self::SELECT_ORDER,
            self::SELECT_ITEMS,
        ], $this->db->prepared);
        $this->assertSame([[]], $this->db->paramsFor(self::INSERT_ORDER));
        $this->assertSame([[5], [7]], $this->db->paramsFor(self::SELECT_FOR_UPDATE));
        $this->assertSame([[42, 5, 2, '19.99'], [42, 7, 1, '5.50']], $this->db->paramsFor(self::INSERT_ITEM));
        $this->assertSame([[2, 5], [1, 7]], $this->db->paramsFor(self::UPDATE_STOCK));
        $this->assertSame([[$expectedTotal, 42]], $this->db->paramsFor(self::UPDATE_TOTAL));
        $this->assertSame([[42]], $this->db->paramsFor(self::SELECT_ORDER));
        $this->assertSame([[42]], $this->db->paramsFor(self::SELECT_ITEMS));
        $this->assertSame(['beginTransaction', 'commit'], $this->db->events);

        // Cache invalidation: one del per product id in order, then products:all.
        $this->assertSame([
            ['del', ['products:5']],
            ['del', ['products:7']],
            ['del', ['products:all']],
        ], $this->redis->calls);

        // Publish: exactly once, after commit and after cache invalidation, before the response.
        $this->assertCount(1, $this->published);
        [$queue, $payload] = $this->published[0];
        $this->assertSame('order_created', $queue);
        $this->assertSame(['order_id', 'total', 'created_at'], array_keys($payload));
        $this->assertSame(42, $payload['order_id']);
        $this->assertSame($expectedTotal, $payload['total']);
        $this->assertMatchesRegularExpression(self::ISO_8601, $payload['created_at']);
        $this->assertGreaterThanOrEqual($before, strtotime($payload['created_at']));
        $this->assertLessThanOrEqual($after, strtotime($payload['created_at']));

        $this->assertSame(['beginTransaction', 'commit'], $this->atPublish[0]['events']);
        $this->assertSame($this->redis->calls, $this->atPublish[0]['redis']);
        $this->assertSame(200, $this->atPublish[0]['status']);
        $this->assertSame('', $this->atPublish[0]['output']);
    }

    public function testCreateCommitsBeforeInvalidatingEachCacheKey(): void
    {
        $this->stubCreateStatements();
        $eventsAtDel = [];
        $this->redis->onCall = function (string $name, array $args) use (&$eventsAtDel): void {
            $eventsAtDel[] = [$name, $args, $this->db->events];
        };

        $this->controller($this->body([
            ['product_id' => 5, 'quantity' => 1],
            ['product_id' => 7, 'quantity' => 1],
        ]))->create();

        $this->assertCount(3, $eventsAtDel);
        foreach ($eventsAtDel as [$name, , $events]) {
            $this->assertSame('del', $name);
            $this->assertSame(['beginTransaction', 'commit'], $events);
        }
    }

    public function testCreateSingleItemExactTotal(): void
    {
        $this->stubCreateStatements();

        $this->controller($this->body([['product_id' => 7, 'quantity' => 2]]))->create();

        $this->assertSame(201, $this->httpStatus());
        $this->assertSame([[11.0, 42]], $this->db->paramsFor(self::UPDATE_TOTAL));
        $this->assertSame([['order_created', ['order_id' => 42, 'total' => 11.0, 'created_at' => $this->published[0][1]['created_at']]]], $this->published);
        $this->assertSame([['del', ['products:7']], ['del', ['products:all']]], $this->redis->calls);
    }

    public function testCreateAllowsQuantityEqualToStock(): void
    {
        $this->stubCreateStatements();

        $this->controller($this->body([['product_id' => 7, 'quantity' => 3]]))->create();

        $this->assertSame(201, $this->httpStatus());
        $this->assertSame(['beginTransaction', 'commit'], $this->db->events);
        $this->assertSame([[3, 7]], $this->db->paramsFor(self::UPDATE_STOCK));
    }

    public function testCreateBindsRawRequestValuesAndCastsQuantityOnlyForTheTotal(): void
    {
        $this->stubCreateStatements();

        $this->controller('{"items":[{"product_id":"5","quantity":"2"},{"product_id":7,"quantity":2.5}]}')->create();

        $this->assertSame(201, $this->httpStatus());
        $this->assertSame([['5'], [7]], $this->db->paramsFor(self::SELECT_FOR_UPDATE));
        $this->assertSame([[42, '5', '2', '19.99'], [42, 7, 2.5, '5.50']], $this->db->paramsFor(self::INSERT_ITEM));
        $this->assertSame([['2', '5'], [2.5, 7]], $this->db->paramsFor(self::UPDATE_STOCK));
        // (int) quantity: 19.99 * 2 + 5.50 * 2
        $this->assertSame([[0 + (float) '19.99' * 2 + (float) '5.50' * 2, 42]], $this->db->paramsFor(self::UPDATE_TOTAL));
        $this->assertSame([['del', ['products:5']], ['del', ['products:7']], ['del', ['products:all']]], $this->redis->calls);
    }

    public function testCreateWithDuplicateProductIdsAccumulatesAndInvalidatesEachOccurrence(): void
    {
        $this->stubCreateStatements();
        $expectedTotal = 0 + (float) '19.99' * 4 + (float) '19.99' * 3;

        $this->controller($this->body([
            ['product_id' => 5, 'quantity' => 4],
            ['product_id' => 5, 'quantity' => 3],
        ]))->create();

        $this->assertSame(201, $this->httpStatus());
        $this->assertSame([[42, 5, 4, '19.99'], [42, 5, 3, '19.99']], $this->db->paramsFor(self::INSERT_ITEM));
        $this->assertSame([[4, 5], [3, 5]], $this->db->paramsFor(self::UPDATE_STOCK));
        $this->assertSame([[$expectedTotal, 42]], $this->db->paramsFor(self::UPDATE_TOTAL));
        $this->assertSame([
            ['del', ['products:5']],
            ['del', ['products:5']],
            ['del', ['products:all']],
        ], $this->redis->calls);
        $this->assertCount(1, $this->published);
        $this->assertSame($expectedTotal, $this->published[0][1]['total']);
    }

    public function testCreateAcceptsItemsObjectKeyedByName(): void
    {
        $this->stubCreateStatements();

        $this->controller('{"items":{"a":{"product_id":5,"quantity":1}}}')->create();

        $this->assertSame(201, $this->httpStatus());
        $this->assertSame([[42, 5, 1, '19.99']], $this->db->paramsFor(self::INSERT_ITEM));
    }

    // --- create: publisher failure after commit (latent defect preserved) ---

    public function testCreatePublishFailureAfterCommitAttemptsRollBackWhichThrowsInRealPdo(): void
    {
        $this->stubCreateStatements();
        $this->publishError = new \RuntimeException('amqp down');
        $this->db->rollBackThrows(new \PDOException('There is no active transaction'));

        try {
            $this->controller($this->body([['product_id' => 5, 'quantity' => 2]]))->create();
            $this->fail('the rollBack() PDOException must propagate');
        } catch (\PDOException $e) {
            $this->assertSame('There is no active transaction', $e->getMessage());
        }

        $this->assertSame(['beginTransaction', 'commit', 'rollBack'], $this->db->events);
        $this->assertSame([['del', ['products:5']], ['del', ['products:all']]], $this->redis->calls);
        $this->assertCount(1, $this->published);
        // Neither 201 nor an error body was produced.
        $this->assertSame(200, $this->httpStatus());
        $this->assertSame('', $this->capturedOutput());
    }

    public function testCreatePublishFailureAfterCommitReturns500WhenRollBackReturns(): void
    {
        $this->stubCreateStatements();
        $this->publishError = new \RuntimeException('amqp down');
        $this->db->rollBackReturns(true);

        $this->controller($this->body([['product_id' => 5, 'quantity' => 2]]))->create();

        $this->assertSame(500, $this->httpStatus());
        $this->assertSame('{"error":"Order creation failed"}', $this->capturedOutput());
        $this->assertSame(['beginTransaction', 'commit', 'rollBack'], $this->db->events);
        $this->assertSame([['del', ['products:5']], ['del', ['products:all']]], $this->redis->calls);
        $this->assertCount(1, $this->published);
        // The order is not re-read after a failed publish.
        $this->assertNotContains(self::SELECT_ORDER, $this->db->prepared);
    }

    public function testCreatePublisherInvalidArgumentExceptionIsReportedAs400AfterCommit(): void
    {
        $this->stubCreateStatements();
        $this->publishError = new \InvalidArgumentException('bad payload');

        $this->controller($this->body([['product_id' => 5, 'quantity' => 2]]))->create();

        $this->assertSame(400, $this->httpStatus());
        $this->assertSame('{"error":"bad payload"}', $this->capturedOutput());
        $this->assertSame(['beginTransaction', 'commit', 'rollBack'], $this->db->events);
    }

    // --- default seams ---

    public function testDefaultPublisherPublishesThroughQueueResolvedAtCallTime(): void
    {
        $this->stubCreateStatements();
        // Controller is built BEFORE the queue connection exists: Queue::publish must be resolved lazily.
        $controller = new OrderController(
            $this->db->pdo,
            $this->redis,
            null,
            fn () => $this->body([['product_id' => 5, 'quantity' => 2]])
        );
        [, $channel] = $this->useMockQueueConnection();

        $published = [];
        $channel->expects($this->once())
            ->method('queue_declare')
            ->with('order_created', false, true, false, false);
        $channel->expects($this->once())
            ->method('basic_publish')
            ->willReturnCallback(function ($message, $exchange = '', $routingKey = '') use (&$published): void {
                $published = [$message, $exchange, $routingKey];
            });
        $channel->expects($this->once())->method('close');

        $controller->create();

        $this->assertSame(201, $this->httpStatus());
        $this->assertSame(json_encode(self::ORDER + ['items' => self::ITEMS]), $this->capturedOutput());
        $this->assertSame(['beginTransaction', 'commit'], $this->db->events);

        [$message, $exchange, $routingKey] = $published;
        $this->assertInstanceOf(AMQPMessage::class, $message);
        $this->assertSame('', $exchange);
        $this->assertSame('order_created', $routingKey);
        $this->assertSame(AMQPMessage::DELIVERY_MODE_PERSISTENT, $message->get('delivery_mode'));
        $payload = json_decode($message->getBody(), true);
        $this->assertSame(['order_id', 'total', 'created_at'], array_keys($payload));
        $this->assertSame(42, $payload['order_id']);
        $this->assertEqualsWithDelta(39.98, $payload['total'], 0.0000001);
        $this->assertMatchesRegularExpression(self::ISO_8601, $payload['created_at']);
    }

    public function testDefaultInputReaderReadsPhpInput(): void
    {
        PhpInputStream::register();
        PhpInputStream::setBody($this->body([['product_id' => 5, 'quantity' => 2]]));
        $this->stubCreateStatements();

        (new OrderController($this->db->pdo, $this->redis, $this->publisher))->create();

        $this->assertSame(201, $this->httpStatus());
        $this->assertSame([[42, 5, 2, '19.99']], $this->db->paramsFor(self::INSERT_ITEM));
        $this->assertCount(1, $this->published);
    }

    public function testDefaultInputReaderWithEmptyPhpInputReturns400(): void
    {
        PhpInputStream::register();
        PhpInputStream::setBody('');

        (new OrderController($this->db->pdo, $this->redis, $this->publisher))->create();

        $this->assertSame(400, $this->httpStatus());
        $this->assertSame('{"error":"items array is required"}', $this->capturedOutput());
        $this->assertNothingTouched();
    }

    public function testInjectedCallablesMayBeAnyPhpCallable(): void
    {
        $this->stubCreateStatements();
        $reader = new class {
            public function __invoke(): string
            {
                return '{"items":[{"product_id":5,"quantity":1}]}';
            }
        };
        $publisher = new class {
            /** @var list<string> */
            public array $calls = [];

            public function record(string $queue, array $payload): void
            {
                $this->calls[] = $queue;
            }
        };

        (new OrderController($this->db->pdo, $this->redis, [$publisher, 'record'], $reader))->create();

        $this->assertSame(201, $this->httpStatus());
        $this->assertSame(['order_created'], $publisher->calls);
    }
}
