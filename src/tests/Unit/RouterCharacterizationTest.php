<?php

namespace Tests\Unit;

use App\Router;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exception\AMQPRuntimeException;
use PhpAmqpLib\Message\AMQPMessage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ControllerTestCase;
use Tests\Support\FakePdo;
use Tests\Support\PhpInputStream;

/**
 * Locks the observable behaviour of the unmodified App\Router through its public
 * entry point only. Must keep passing, unedited, after handlers move to controllers.
 */
final class RouterCharacterizationTest extends ControllerTestCase
{
    private const PRODUCT = ['id' => 5, 'name' => 'Widget', 'description' => 'A widget', 'price' => '19.99', 'stock' => 10];
    private const PRODUCT_JSON = '{"id":5,"name":"Widget","description":"A widget","price":"19.99","stock":10}';
    private const NOT_FOUND = '{"error":"Not found"}';

    private FakePdo $db;
    private AMQPStreamConnection $amqp;
    private \PhpAmqpLib\Channel\AMQPChannel&\PHPUnit\Framework\MockObject\MockObject $channel;

    protected function setUp(): void
    {
        parent::setUp();
        PhpInputStream::register();
        PhpInputStream::setBody('');
        $this->db = new FakePdo($this->mockPdo(), fn () => $this->createMock(\PDOStatement::class));
        $this->amqp = $this->createMock(AMQPStreamConnection::class);
        [, $this->channel] = $this->useMockQueueConnection();
    }

    protected function tearDown(): void
    {
        PhpInputStream::restore();
        $this->assertSame([], $this->db->unmatched, 'SQL that no test rule matched');
        parent::tearDown();
    }

    private function request(string $method, string $uri, ?string $body = null): string
    {
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI'] = $uri;
        PhpInputStream::setBody($body ?? '');
        (new Router($this->db->pdo, $this->redis, $this->amqp))->handleRequest();
        return $this->capturedOutput();
    }

    private function assertNoSideEffects(): void
    {
        $this->assertFalse($this->db->hasInteractions());
        $this->assertSame([], $this->redis->calls);
    }

    // --- PhpInputStream sanity (guards the test seam itself) ---

    public function testPhpInputSeamServesBodyAndKeepsOtherPhpStreamsWorking(): void
    {
        PhpInputStream::setBody('{"a":1}');
        $this->assertSame('{"a":1}', file_get_contents('php://input'));
        $this->assertSame('{"a":1}', file_get_contents('php://input'));

        $memory = fopen('php://memory', 'w+');
        fwrite($memory, 'hello');
        rewind($memory);
        $this->assertSame('hello', stream_get_contents($memory));
        fclose($memory);

        echo 'via-output';
        $out = fopen('php://output', 'w');
        fwrite($out, '-stream');
        fclose($out);
        $this->assertSame('via-output-stream', $this->capturedOutput());
    }

    // --- Health ---

    public static function healthProvider(): array
    {
        $ok = '{"status":"ok","services":{"mysql":"connected","redis":"connected","rabbitmq":"connected"}}';
        return [
            'all ok' => [false, false, 'connected', $ok],
            'mysql throws' => [true, false, 'connected',
                '{"status":"degraded","services":{"mysql":"error","redis":"connected","rabbitmq":"connected"}}'],
            'redis ping throws' => [false, true, 'connected',
                '{"status":"degraded","services":{"mysql":"connected","redis":"error","rabbitmq":"connected"}}'],
            'rabbitmq not connected keeps status ok' => [false, false, 'disconnected',
                '{"status":"ok","services":{"mysql":"connected","redis":"connected","rabbitmq":"error"}}'],
            'rabbitmq isConnected throws' => [false, false, 'throws',
                '{"status":"degraded","services":{"mysql":"connected","redis":"connected","rabbitmq":"error"}}'],
            'mysql down and rabbitmq disconnected' => [true, false, 'disconnected',
                '{"status":"degraded","services":{"mysql":"error","redis":"connected","rabbitmq":"error"}}'],
            'everything down' => [true, true, 'throws',
                '{"status":"degraded","services":{"mysql":"error","redis":"error","rabbitmq":"error"}}'],
        ];
    }

    #[DataProvider('healthProvider')]
    public function testHealth(bool $mysqlThrows, bool $redisThrows, string $rabbit, string $expectedBody): void
    {
        $this->db->onSql('SELECT 1', queryThrows: $mysqlThrows ? new \PDOException('mysql gone') : null);
        $this->redis->pingException = $redisThrows ? new \RuntimeException('redis gone') : null;

        $amqp = $this->createMock(AMQPStreamConnection::class);
        match ($rabbit) {
            'connected' => $amqp->method('isConnected')->willReturn(true),
            'disconnected' => $amqp->method('isConnected')->willReturn(false),
            'throws' => $amqp->method('isConnected')->willThrowException(new AMQPRuntimeException('rabbit gone')),
        };
        $this->amqp = $amqp;

        $body = $this->request('GET', '/health');

        $this->assertSame(200, $this->httpStatus());
        $this->assertSame($expectedBody, $body);
        $this->assertSame(['SELECT 1'], $this->db->queried);
        $this->assertSame([['ping', []]], $this->redis->calls);
        $this->assertSame([], $this->db->prepared);
    }

    // --- Products: list ---

    public function testListProductsCacheMissQueriesDbAndCachesForSixtySeconds(): void
    {
        $rows = [self::PRODUCT, ['id' => 6, 'name' => 'Gadget', 'description' => '', 'price' => '5.00', 'stock' => 0]];
        $this->db->onSql('SELECT * FROM products ORDER BY id', rows: $rows);

        $body = $this->request('GET', '/api/products');

        $json = json_encode($rows);
        $this->assertSame(200, $this->httpStatus());
        $this->assertSame($json, $body);
        $this->assertSame('[' . self::PRODUCT_JSON . ',{"id":6,"name":"Gadget","description":"","price":"5.00","stock":0}]', $body);
        $this->assertSame(['SELECT * FROM products ORDER BY id'], $this->db->queried);
        $this->assertSame([], $this->db->prepared);
        $this->assertSame([['get', ['products:all']], ['setex', ['products:all', 60, $json]]], $this->redis->calls);
    }

    public function testListProductsCacheHitEchoesCachedStringWithoutDb(): void
    {
        $this->redis->seed('products:all', '[{"id":1,"cached":true}]');

        $body = $this->request('GET', '/api/products');

        $this->assertSame(200, $this->httpStatus());
        $this->assertSame('[{"id":1,"cached":true}]', $body);
        $this->assertFalse($this->db->hasInteractions());
        $this->assertSame([['get', ['products:all']]], $this->redis->calls);
    }

    public static function falsyCacheProvider(): array
    {
        return ['string zero' => ['0'], 'empty string' => ['']];
    }

    #[DataProvider('falsyCacheProvider')]
    public function testListProductsFalsyCachedValueFallsThroughToDb(string $cached): void
    {
        $this->redis->seed('products:all', $cached);
        $this->db->onSql('SELECT * FROM products ORDER BY id', rows: []);

        $body = $this->request('GET', '/api/products');

        $this->assertSame('[]', $body);
        $this->assertSame(['SELECT * FROM products ORDER BY id'], $this->db->queried);
        $this->assertSame([['get', ['products:all']], ['setex', ['products:all', 60, '[]']]], $this->redis->calls);
    }

    // --- Products: get ---

    public function testGetProductCacheMissReadsDbAndCaches(): void
    {
        $this->db->onSql('SELECT * FROM products WHERE id = ?', fetch: self::PRODUCT);

        $body = $this->request('GET', '/api/products/5');

        $this->assertSame(200, $this->httpStatus());
        $this->assertSame(self::PRODUCT_JSON, $body);
        $this->assertSame(['SELECT * FROM products WHERE id = ?'], $this->db->prepared);
        $this->assertSame([[5]], $this->db->paramsFor('SELECT * FROM products'));
        $this->assertSame([['get', ['products:5']], ['setex', ['products:5', 60, self::PRODUCT_JSON]]], $this->redis->calls);
        $this->assertSame(60, $this->redis->ttls['products:5']);
    }

    public function testGetProductCacheHitSkipsDb(): void
    {
        $this->redis->seed('products:5', '{"id":5,"from":"cache"}');

        $body = $this->request('GET', '/api/products/5');

        $this->assertSame(200, $this->httpStatus());
        $this->assertSame('{"id":5,"from":"cache"}', $body);
        $this->assertFalse($this->db->hasInteractions());
        $this->assertSame([['get', ['products:5']]], $this->redis->calls);
    }

    #[DataProvider('falsyCacheProvider')]
    public function testGetProductFalsyCachedValueFallsThroughToDb(string $cached): void
    {
        $this->redis->seed('products:5', $cached);
        $this->db->onSql('SELECT * FROM products WHERE id = ?', fetch: self::PRODUCT);

        $body = $this->request('GET', '/api/products/5');

        $this->assertSame(self::PRODUCT_JSON, $body);
        $this->assertSame(['SELECT * FROM products WHERE id = ?'], $this->db->prepared);
        $this->assertSame([['get', ['products:5']], ['setex', ['products:5', 60, self::PRODUCT_JSON]]], $this->redis->calls);
    }

    public function testGetProductNotFoundDoesNotWriteCache(): void
    {
        $this->db->onSql('SELECT * FROM products WHERE id = ?', fetch: false);

        $body = $this->request('GET', '/api/products/5');

        $this->assertSame(404, $this->httpStatus());
        $this->assertSame('{"error":"Product not found"}', $body);
        $this->assertSame([['get', ['products:5']]], $this->redis->calls);
        $this->assertSame([], $this->redis->store);
    }

    public function testGetProductBindsIdAsStrictInteger(): void
    {
        $this->db->onSql('SELECT * FROM products WHERE id = ?', fetch: self::PRODUCT);

        $this->request('GET', '/api/products/007');

        $this->assertSame([[7]], $this->db->paramsFor('SELECT * FROM products'));
        $this->assertSame('products:7', $this->redis->calls[0][1][0]);
    }

    // --- Products: create ---

    public static function invalidCreateBodyProvider(): array
    {
        return [
            'missing name' => ['{"price":5}', 'name and price are required'],
            'missing price' => ['{"name":"x"}', 'name and price are required'],
            'null price' => ['{"name":"x","price":null}', 'name and price are required'],
            'null name' => ['{"name":null,"price":5}', 'name and price are required'],
            'empty body' => ['', 'name and price are required'],
            'invalid json' => ['{not json', 'name and price are required'],
            'json string' => ['"just a string"', 'name and price are required'],
            'json list' => ['[]', 'name and price are required'],
            'price zero' => ['{"name":"x","price":0}', 'price must be greater than zero'],
            'price zero string' => ['{"name":"x","price":"0"}', 'price must be greater than zero'],
            'price negative' => ['{"name":"x","price":-1}', 'price must be greater than zero'],
            'price negative string' => ['{"name":"x","price":"-5.5"}', 'price must be greater than zero'],
            'price non numeric' => ['{"name":"x","price":"abc"}', 'price must be greater than zero'],
            'price empty string' => ['{"name":"x","price":""}', 'price must be greater than zero'],
            'stock negative' => ['{"name":"x","price":5,"stock":-1}', 'stock cannot be negative'],
            'stock negative string' => ['{"name":"x","price":5,"stock":"-3"}', 'stock cannot be negative'],
        ];
    }

    #[DataProvider('invalidCreateBodyProvider')]
    public function testCreateProductValidationFailures(string $body, string $error): void
    {
        $out = $this->request('POST', '/api/products', $body);

        $this->assertSame(400, $this->httpStatus());
        $this->assertSame(json_encode(['error' => $error]), $out);
        $this->assertNoSideEffects();
    }

    public static function validCreateProvider(): array
    {
        return [
            'full' => ['{"name":"Widget","description":"A widget","price":"19.99","stock":10}', ['Widget', 'A widget', 19.99, 10]],
            'defaults' => ['{"name":"Gadget","price":5}', ['Gadget', '', 5.0, 0]],
            'null description and stock' => ['{"name":"Thing","description":null,"price":"7.5","stock":null}', ['Thing', '', 7.5, 0]],
            'stock zero' => ['{"name":"Z","price":0.01,"stock":0}', ['Z', '', 0.01, 0]],
            'string numerics are cast' => ['{"name":"S","price":"1e1","stock":"3"}', ['S', '', 10.0, 3]],
        ];
    }

    #[DataProvider('validCreateProvider')]
    public function testCreateProductSuccess(string $body, array $expectedParams): void
    {
        $this->db->withInsertIds('42')
            ->onSql('INSERT INTO products', rowCount: 1)
            ->onSql('SELECT * FROM products WHERE id = ?', fetch: ['id' => 42, 'name' => $expectedParams[0], 'price' => '9.99']);
        $this->redis->seed('products:all', 'stale');

        $out = $this->request('POST', '/api/products', $body);

        $json = '{"id":42,"name":' . json_encode($expectedParams[0]) . ',"price":"9.99"}';
        $this->assertSame(201, $this->httpStatus());
        $this->assertSame($json, $out);
        $this->assertSame([
            'INSERT INTO products (name, description, price, stock) VALUES (?, ?, ?, ?)',
            'SELECT * FROM products WHERE id = ?',
        ], $this->db->prepared);
        $this->assertSame([$expectedParams], $this->db->paramsFor('INSERT INTO products'));
        $this->assertSame([[42]], $this->db->paramsFor('SELECT * FROM products'));
        $this->assertSame([
            ['del', ['products:all']],
            ['get', ['products:42']],
            ['setex', ['products:42', 60, $json]],
        ], $this->redis->calls);
        $this->assertArrayNotHasKey('products:all', $this->redis->store);
    }

    public function testCreateProductReturns404WhenReadBackFindsNothing(): void
    {
        $this->db->withInsertIds('42')
            ->onSql('INSERT INTO products')
            ->onSql('SELECT * FROM products WHERE id = ?', fetch: false);

        $out = $this->request('POST', '/api/products', '{"name":"x","price":1}');

        $this->assertSame(404, $this->httpStatus());
        $this->assertSame('{"error":"Product not found"}', $out);
    }

    // --- Products: update ---

    private function stubUpdate(bool $exists = true): void
    {
        $this->db
            ->onSql('SELECT id FROM products WHERE id = ?', fetch: $exists ? ['id' => 7] : false)
            ->onSql('UPDATE products SET', rowCount: 1)
            ->onSql('SELECT * FROM products WHERE id = ?', fetch: ['id' => 7, 'name' => 'Updated', 'price' => '1.00']);
    }

    public static function missingProductUpdateProvider(): array
    {
        return ['empty body' => [''], 'valid body' => ['{"name":"x"}'], 'invalid json' => ['{oops']];
    }

    #[DataProvider('missingProductUpdateProvider')]
    public function testUpdateProductNotFoundIsCheckedBeforeFields(string $body): void
    {
        $this->stubUpdate(exists: false);

        $out = $this->request('PUT', '/api/products/7', $body);

        $this->assertSame(404, $this->httpStatus());
        $this->assertSame('{"error":"Product not found"}', $out);
        $this->assertSame(['SELECT id FROM products WHERE id = ?'], $this->db->prepared);
        $this->assertSame([[7]], $this->db->paramsFor('SELECT id FROM products'));
        $this->assertSame([], $this->redis->calls);
    }

    public static function emptyUpdateProvider(): array
    {
        return [
            'empty body' => [''],
            'empty object' => ['{}'],
            'null fields only' => ['{"name":null,"description":null,"price":null,"stock":null}'],
            'unknown fields only' => ['{"foo":"bar","id":9}'],
            'invalid json' => ['not json'],
        ];
    }

    #[DataProvider('emptyUpdateProvider')]
    public function testUpdateProductWithNoUsableFields(string $body): void
    {
        $this->stubUpdate();

        $out = $this->request('PUT', '/api/products/7', $body);

        $this->assertSame(400, $this->httpStatus());
        $this->assertSame('{"error":"No fields to update"}', $out);
        $this->assertSame(['SELECT id FROM products WHERE id = ?'], $this->db->prepared);
        $this->assertSame([], $this->redis->calls);
    }

    public static function updateProvider(): array
    {
        return [
            'name only' => ['{"name":"New"}', 'UPDATE products SET name = ? WHERE id = ?', ['New', 7]],
            'price then name in body, fixed column order' => [
                '{"price":12.5,"name":"New"}', 'UPDATE products SET name = ?, price = ? WHERE id = ?', ['New', 12.5, 7],
            ],
            'all four, falsy values still set' => [
                '{"stock":0,"description":"","price":"9.99","name":"N"}',
                'UPDATE products SET name = ?, description = ?, price = ?, stock = ? WHERE id = ?',
                ['N', '', '9.99', 0, 7],
            ],
            'stock only' => ['{"stock":3}', 'UPDATE products SET stock = ? WHERE id = ?', [3, 7]],
            'null and unknown ignored' => [
                '{"description":"d","stock":null,"foo":"bar"}', 'UPDATE products SET description = ? WHERE id = ?', ['d', 7],
            ],
            'values are not validated or cast' => ['{"price":-5}', 'UPDATE products SET price = ? WHERE id = ?', [-5, 7]],
        ];
    }

    #[DataProvider('updateProvider')]
    public function testUpdateProductSuccess(string $body, string $expectedSql, array $expectedParams): void
    {
        $this->stubUpdate();
        $this->redis->seed('products:7', 'stale-one');
        $this->redis->seed('products:all', 'stale-all');

        $out = $this->request('PUT', '/api/products/7', $body);

        $json = '{"id":7,"name":"Updated","price":"1.00"}';
        $this->assertSame(200, $this->httpStatus());
        $this->assertSame($json, $out);
        $this->assertSame([
            'SELECT id FROM products WHERE id = ?',
            $expectedSql,
            'SELECT * FROM products WHERE id = ?',
        ], $this->db->prepared);
        $this->assertSame([$expectedParams], $this->db->paramsFor('UPDATE products SET'));
        $this->assertSame([
            ['del', ['products:7']],
            ['del', ['products:all']],
            ['get', ['products:7']],
            ['setex', ['products:7', 60, $json]],
        ], $this->redis->calls);
        $this->assertSame($json, $this->redis->store['products:7']);
        $this->assertArrayNotHasKey('products:all', $this->redis->store);
    }

    // --- Products: delete ---

    public function testDeleteProductReturns204WithEmptyBodyAndInvalidatesCaches(): void
    {
        $this->db->onSql('DELETE FROM products WHERE id = ?', rowCount: 1);

        $out = $this->request('DELETE', '/api/products/5');

        $this->assertSame(204, $this->httpStatus());
        $this->assertSame('', $out);
        $this->assertSame(['DELETE FROM products WHERE id = ?'], $this->db->prepared);
        $this->assertSame([[5]], $this->db->paramsFor('DELETE FROM products'));
        $this->assertSame([['del', ['products:5']], ['del', ['products:all']]], $this->redis->calls);
    }

    public function testDeleteProductNotFoundWhenNoRowsAffected(): void
    {
        $this->db->onSql('DELETE FROM products WHERE id = ?', rowCount: 0);

        $out = $this->request('DELETE', '/api/products/5');

        $this->assertSame(404, $this->httpStatus());
        $this->assertSame('{"error":"Product not found"}', $out);
        $this->assertSame([], $this->redis->calls);
    }

    public function testDeleteProductReferencedByOrdersReturns409(): void
    {
        $this->db->onSql('DELETE FROM products WHERE id = ?', executeThrows: $this->pdoException('23000', 'FK constraint'));

        $out = $this->request('DELETE', '/api/products/5');

        $this->assertSame(409, $this->httpStatus());
        $this->assertSame('{"error":"Cannot delete product that is referenced by orders"}', $out);
        $this->assertSame([], $this->redis->calls);
    }

    public function testDeleteProductRethrowsOtherPdoExceptions(): void
    {
        $error = $this->pdoException('HY000', 'server has gone away');
        $this->db->onSql('DELETE FROM products WHERE id = ?', executeThrows: $error);

        try {
            $this->request('DELETE', '/api/products/5');
            $this->fail('PDOException was expected to propagate');
        } catch (\PDOException $e) {
            $this->assertSame($error, $e);
        }

        $this->assertSame([], $this->redis->calls);
        $this->assertSame('', $this->capturedOutput());
    }

    // --- Orders: list / get ---

    public function testListOrdersReturnsRowsNewestFirstQuery(): void
    {
        $rows = [
            ['id' => 2, 'status' => 'pending', 'total' => '25.00', 'created_at' => '2026-01-02 10:00:00'],
            ['id' => 1, 'status' => 'pending', 'total' => '5.00', 'created_at' => '2026-01-01 10:00:00'],
        ];
        $this->db->onSql('SELECT * FROM orders ORDER BY id DESC', rows: $rows);

        $out = $this->request('GET', '/api/orders');

        $this->assertSame(200, $this->httpStatus());
        $this->assertSame(json_encode($rows), $out);
        $this->assertSame(['SELECT * FROM orders ORDER BY id DESC'], $this->db->queried);
        $this->assertSame([], $this->db->prepared);
        $this->assertSame([], $this->redis->calls);
    }

    public function testListOrdersEmpty(): void
    {
        $this->db->onSql('SELECT * FROM orders ORDER BY id DESC', rows: []);

        $this->assertSame('[]', $this->request('GET', '/api/orders'));
        $this->assertSame(200, $this->httpStatus());
    }

    public function testGetOrderNotFound(): void
    {
        $this->db->onSql('SELECT * FROM orders WHERE id = ?', fetch: false);

        $out = $this->request('GET', '/api/orders/3');

        $this->assertSame(404, $this->httpStatus());
        $this->assertSame('{"error":"Order not found"}', $out);
        $this->assertSame(['SELECT * FROM orders WHERE id = ?'], $this->db->prepared);
        $this->assertSame([[3]], $this->db->paramsFor('SELECT * FROM orders'));
    }

    public function testGetOrderIncludesItemsJoinedWithProductName(): void
    {
        $order = ['id' => 3, 'status' => 'pending', 'total' => '44.98', 'created_at' => '2026-01-01 10:00:00'];
        $items = [
            ['id' => 1, 'order_id' => 3, 'product_id' => 1, 'quantity' => 2, 'price' => '19.99', 'product_name' => 'Widget'],
        ];
        $this->db->onSql('SELECT * FROM orders WHERE id = ?', fetch: $order)
            ->onSql('FROM order_items oi', rows: $items);

        $out = $this->request('GET', '/api/orders/3');

        $this->assertSame(200, $this->httpStatus());
        $this->assertSame(json_encode($order + ['items' => $items]), $out);
        $this->assertSame(
            '{"id":3,"status":"pending","total":"44.98","created_at":"2026-01-01 10:00:00","items":'
            . '[{"id":1,"order_id":3,"product_id":1,"quantity":2,"price":"19.99","product_name":"Widget"}]}',
            $out
        );
        $this->assertSame([
            'SELECT * FROM orders WHERE id = ?',
            'SELECT oi.*, p.name as product_name FROM order_items oi JOIN products p ON p.id = oi.product_id WHERE oi.order_id = ?',
        ], $this->db->prepared);
        $this->assertSame([[3]], $this->db->paramsFor('SELECT * FROM orders'));
        $this->assertSame([[3]], $this->db->paramsFor('FROM order_items oi'));
    }

    // --- Orders: create ---

    private const WIDGET = ['id' => 1, 'name' => 'Widget', 'price' => '19.99', 'stock' => 10];
    private const BOLT = ['id' => 2, 'name' => 'Bolt', 'price' => '5.00', 'stock' => 3];

    /** @param array<int, array> $products keyed by product id */
    private function stubOrderCreation(array $products): void
    {
        $this->db->withInsertIds('77')
            ->onSql('INSERT INTO orders (total) VALUES (0)')
            ->onSql('SELECT * FROM products WHERE id = ? FOR UPDATE', fetch: fn (array $p) => $products[$p[0]] ?? false)
            ->onSql('INSERT INTO order_items')
            ->onSql('UPDATE products SET stock = stock - ?')
            ->onSql('UPDATE orders SET total = ?');
    }

    private function stubOrderReadBack(): array
    {
        $order = ['id' => 77, 'status' => 'pending', 'total' => '44.98', 'created_at' => '2026-01-01 10:00:00'];
        $items = [['id' => 1, 'order_id' => 77, 'product_id' => 1, 'quantity' => 2, 'price' => '19.99', 'product_name' => 'Widget']];
        $this->db->onSql('SELECT * FROM orders WHERE id = ?', fetch: $order)->onSql('FROM order_items oi', rows: $items);
        return $order + ['items' => $items];
    }

    public static function invalidOrderBodyProvider(): array
    {
        return [
            'empty body' => [''],
            'invalid json' => ['{nope'],
            'empty object' => ['{}'],
            'items missing' => ['{"other":1}'],
            'items null' => ['{"items":null}'],
            'items string' => ['{"items":"abc"}'],
            'items number' => ['{"items":5}'],
            'items empty list' => ['{"items":[]}'],
        ];
    }

    #[DataProvider('invalidOrderBodyProvider')]
    public function testCreateOrderRequiresItemsArrayBeforeAnyTransaction(string $body): void
    {
        $out = $this->request('POST', '/api/orders', $body);

        $this->assertSame(400, $this->httpStatus());
        $this->assertSame('{"error":"items array is required"}', $out);
        $this->assertNoSideEffects();
    }

    public static function failingItemsProvider(): array
    {
        return [
            'product_id missing' => ['{"items":[{"quantity":1}]}', 'Each item needs product_id and quantity'],
            'quantity missing' => ['{"items":[{"product_id":1}]}', 'Each item needs product_id and quantity'],
            'quantity null' => ['{"items":[{"product_id":1,"quantity":null}]}', 'Each item needs product_id and quantity'],
            'item not an object' => ['{"items":[3]}', 'Each item needs product_id and quantity'],
            'quantity zero' => ['{"items":[{"product_id":1,"quantity":0}]}', 'Quantity must be at least 1'],
            'quantity negative' => ['{"items":[{"product_id":1,"quantity":-2}]}', 'Quantity must be at least 1'],
            'quantity non numeric' => ['{"items":[{"product_id":1,"quantity":"abc"}]}', 'Quantity must be at least 1'],
            'product not found' => ['{"items":[{"product_id":99,"quantity":1}]}', 'Product 99 not found'],
            'insufficient stock' => ['{"items":[{"product_id":2,"quantity":4}]}', 'Insufficient stock for Bolt'],
            'second item fails after first processed' => [
                '{"items":[{"product_id":1,"quantity":1},{"product_id":2,"quantity":4}]}', 'Insufficient stock for Bolt',
            ],
        ];
    }

    #[DataProvider('failingItemsProvider')]
    public function testCreateOrderItemFailureRollsBackWith400(string $body, string $message): void
    {
        $this->stubOrderCreation([1 => self::WIDGET, 2 => self::BOLT]);
        $this->channel->expects($this->never())->method('basic_publish');

        $out = $this->request('POST', '/api/orders', $body);

        $this->assertSame(400, $this->httpStatus());
        $this->assertSame(json_encode(['error' => $message]), $out);
        $this->assertSame(['beginTransaction', 'rollBack'], $this->db->events);
        $this->assertSame([], $this->redis->calls);
    }

    public static function orderProcessingFailureProvider(): array
    {
        return [
            'generic exception on order insert' => ['INSERT INTO orders (total) VALUES (0)', new \RuntimeException('disk full')],
            'pdo exception on stock update' => ['UPDATE products SET stock = stock - ?', new \PDOException('deadlock found')],
            'pdo exception on total update' => ['UPDATE orders SET total = ?', new \PDOException('lock wait timeout')],
        ];
    }

    #[DataProvider('orderProcessingFailureProvider')]
    public function testCreateOrderUnexpectedFailureRollsBackWith500(string $failingSql, \Throwable $error): void
    {
        $this->stubOrderCreation([1 => self::WIDGET]);
        $this->db->onSql($failingSql, executeThrows: $error);
        $this->channel->expects($this->never())->method('basic_publish');

        $out = $this->request('POST', '/api/orders', '{"items":[{"product_id":1,"quantity":1}]}');

        $this->assertSame(500, $this->httpStatus());
        $this->assertSame('{"error":"Order creation failed"}', $out);
        $this->assertSame(['beginTransaction', 'rollBack'], $this->db->events);
        $this->assertSame([], $this->redis->calls);
    }

    public function testCreateOrderSuccess(): void
    {
        $this->stubOrderCreation([1 => self::WIDGET, 2 => self::BOLT]);
        $expectedBody = $this->stubOrderReadBack();
        $this->redis->seed('products:1', 'stale-1');
        $this->redis->seed('products:2', 'stale-2');
        $this->redis->seed('products:all', 'stale-all');

        $published = null;
        $eventsAtPublish = null;
        $this->channel->expects($this->once())->method('queue_declare')->with('order_created', false, true, false, false);
        $this->channel->expects($this->once())->method('basic_publish')
            ->with(
                $this->callback(function ($message) use (&$published) {
                    $published = $message;
                    return $message instanceof AMQPMessage;
                }),
                '',
                'order_created'
            )
            ->willReturnCallback(function () use (&$eventsAtPublish) {
                $eventsAtPublish = $this->db->events;
            });
        $this->channel->expects($this->once())->method('close');

        $out = $this->request('POST', '/api/orders', '{"items":[{"product_id":1,"quantity":2},{"product_id":2,"quantity":1}]}');

        $total = 19.99 * 2 + 5.00 * 1;

        $this->assertSame(201, $this->httpStatus());
        $this->assertSame(json_encode($expectedBody), $out);
        $this->assertSame(['beginTransaction', 'commit'], $this->db->events);
        $this->assertSame(['beginTransaction', 'commit'], $eventsAtPublish);

        $this->assertSame([
            ['sql' => 'INSERT INTO orders (total) VALUES (0)', 'params' => []],
            ['sql' => 'SELECT * FROM products WHERE id = ? FOR UPDATE', 'params' => [1]],
            ['sql' => 'INSERT INTO order_items (order_id, product_id, quantity, price) VALUES (?, ?, ?, ?)', 'params' => [77, 1, 2, '19.99']],
            ['sql' => 'UPDATE products SET stock = stock - ? WHERE id = ?', 'params' => [2, 1]],
            ['sql' => 'SELECT * FROM products WHERE id = ? FOR UPDATE', 'params' => [2]],
            ['sql' => 'INSERT INTO order_items (order_id, product_id, quantity, price) VALUES (?, ?, ?, ?)', 'params' => [77, 2, 1, '5.00']],
            ['sql' => 'UPDATE products SET stock = stock - ? WHERE id = ?', 'params' => [1, 2]],
            ['sql' => 'UPDATE orders SET total = ? WHERE id = ?', 'params' => [$total, 77]],
            ['sql' => 'SELECT * FROM orders WHERE id = ?', 'params' => [77]],
            ['sql' => 'SELECT oi.*, p.name as product_name FROM order_items oi JOIN products p ON p.id = oi.product_id WHERE oi.order_id = ?', 'params' => [77]],
        ], $this->db->executions);
        $this->assertEqualsWithDelta(44.98, $total, 1e-9);

        $this->assertSame([
            ['del', ['products:1']],
            ['del', ['products:2']],
            ['del', ['products:all']],
        ], $this->redis->calls);
        $this->assertSame([], $this->redis->store);

        $this->assertInstanceOf(AMQPMessage::class, $published);
        $payload = json_decode($published->getBody(), true);
        $this->assertSame(['order_id', 'total', 'created_at'], array_keys($payload));
        $this->assertSame(77, $payload['order_id']);
        $this->assertSame($total, $payload['total']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $payload['created_at']);
        $this->assertSame(AMQPMessage::DELIVERY_MODE_PERSISTENT, $published->get('delivery_mode'));
    }

    public function testCreateOrderBindsRawQuantityAndUsesIntegerQuantityForTotal(): void
    {
        $this->stubOrderCreation([1 => self::WIDGET]);
        $this->stubOrderReadBack();
        $this->channel->expects($this->once())->method('basic_publish');

        $this->request('POST', '/api/orders', '{"items":[{"product_id":"1","quantity":"2"}]}');

        $this->assertSame(201, $this->httpStatus());
        $this->assertSame([["1"]], $this->db->paramsFor('FOR UPDATE'));
        $this->assertSame([[77, "1", "2", '19.99']], $this->db->paramsFor('INSERT INTO order_items'));
        $this->assertSame([["2", "1"]], $this->db->paramsFor('SET stock = stock - ?'));
        $this->assertSame([[19.99 * 2, 77]], $this->db->paramsFor('UPDATE orders SET total'));
        $this->assertSame([['del', ['products:1']], ['del', ['products:all']]], $this->redis->calls);
    }

    public function testCreateOrderAllowsQuantityEqualToStock(): void
    {
        $this->stubOrderCreation([2 => self::BOLT]);
        $this->stubOrderReadBack();
        $this->channel->expects($this->once())->method('basic_publish');

        $this->request('POST', '/api/orders', '{"items":[{"product_id":2,"quantity":3}]}');

        $this->assertSame(201, $this->httpStatus());
        $this->assertSame(['beginTransaction', 'commit'], $this->db->events);
    }

    public function testCreateOrderPublishFailureAfterCommitPropagatesRollBackError(): void
    {
        $this->stubOrderCreation([1 => self::WIDGET]);
        $this->stubOrderReadBack();
        $noTransaction = new \PDOException('There is no active transaction');
        $this->db->rollBackThrows($noTransaction);
        $this->channel->method('basic_publish')->willThrowException(new \RuntimeException('amqp down'));

        try {
            $this->request('POST', '/api/orders', '{"items":[{"product_id":1,"quantity":1}]}');
            $this->fail('PDOException from rollBack() was expected to propagate');
        } catch (\PDOException $e) {
            $this->assertSame($noTransaction, $e);
        }

        $this->assertSame(['beginTransaction', 'commit', 'rollBack'], $this->db->events);
        $this->assertSame([['del', ['products:1']], ['del', ['products:all']]], $this->redis->calls);
        $this->assertSame(200, $this->httpStatus());
        $this->assertSame('', $this->capturedOutput());
    }

    public function testCreateOrderPublishFailureAfterCommitReturns500WhenRollBackSucceeds(): void
    {
        $this->stubOrderCreation([1 => self::WIDGET]);
        $this->stubOrderReadBack();
        $this->db->rollBackReturns(true);
        $this->channel->method('basic_publish')->willThrowException(new \RuntimeException('amqp down'));

        $out = $this->request('POST', '/api/orders', '{"items":[{"product_id":1,"quantity":1}]}');

        $this->assertSame(500, $this->httpStatus());
        $this->assertSame('{"error":"Order creation failed"}', $out);
        $this->assertSame(['beginTransaction', 'commit', 'rollBack'], $this->db->events);
        $this->assertSame([['del', ['products:1']], ['del', ['products:all']]], $this->redis->calls);
        $this->assertSame([], $this->db->paramsFor('SELECT * FROM orders WHERE id = ?'));
    }

    // --- Routing ---

    public static function cleanPathProvider(): array
    {
        return [
            'health trailing slash' => ['/health/'],
            'health query' => ['/health?x=1'],
            'health slash and query' => ['/health/?x=1'],
            'health many slashes' => ['/health//'],
        ];
    }

    #[DataProvider('cleanPathProvider')]
    public function testHealthRoutesIgnoreTrailingSlashAndQueryString(string $uri): void
    {
        $this->db->onSql('SELECT 1');
        $this->amqp = $this->createMock(AMQPStreamConnection::class);
        $this->amqp->method('isConnected')->willReturn(true);

        $out = $this->request('GET', $uri);

        $this->assertSame(200, $this->httpStatus());
        $this->assertSame('{"status":"ok","services":{"mysql":"connected","redis":"connected","rabbitmq":"connected"}}', $out);
    }

    public static function productListUriProvider(): array
    {
        return [['/api/products/'], ['/api/products?x=1'], ['/api/products/?a=1&b=2'], ['/api/products//']];
    }

    #[DataProvider('productListUriProvider')]
    public function testProductListIgnoresTrailingSlashAndQueryString(string $uri): void
    {
        $this->redis->seed('products:all', '[{"cached":1}]');

        $this->assertSame('[{"cached":1}]', $this->request('GET', $uri));
        $this->assertSame(200, $this->httpStatus());
    }

    public static function productByIdUriProvider(): array
    {
        return [['/api/products/5/'], ['/api/products/5?x=1'], ['/api/products/5/?x=1&y=2']];
    }

    #[DataProvider('productByIdUriProvider')]
    public function testProductByIdIgnoresTrailingSlashAndQueryString(string $uri): void
    {
        $this->redis->seed('products:5', '{"cached":5}');

        $this->assertSame('{"cached":5}', $this->request('GET', $uri));
        $this->assertSame([['get', ['products:5']]], $this->redis->calls);
    }

    public function testOrderRoutesIgnoreTrailingSlashAndQueryString(): void
    {
        $this->db->onSql('SELECT * FROM orders ORDER BY id DESC', rows: [['id' => 1]])
            ->onSql('SELECT * FROM orders WHERE id = ?', fetch: false);

        $this->assertSame('[{"id":1}]', $this->request('GET', '/api/orders/'));
        $this->assertSame('[{"id":1}]', $this->request('GET', '/api/orders?x=1'));
        $this->assertSame('{"error":"Order not found"}', $this->request('GET', '/api/orders/3/?x=1'));
        $this->assertSame(404, $this->httpStatus());
    }

    public function testWriteRoutesIgnoreTrailingSlash(): void
    {
        $this->db->onSql('DELETE FROM products WHERE id = ?', rowCount: 1)
            ->onSql('SELECT id FROM products WHERE id = ?', fetch: false);

        $this->assertSame('{"error":"name and price are required"}', $this->request('POST', '/api/products/', '{}'));
        $this->assertSame(400, $this->httpStatus());

        $this->assertSame('{"error":"Product not found"}', $this->request('PUT', '/api/products/7/', '{"name":"x"}'));
        $this->assertSame(404, $this->httpStatus());

        $this->assertSame('', $this->request('DELETE', '/api/products/5/?force=1'));
        $this->assertSame(204, $this->httpStatus());

        $this->assertSame('{"error":"items array is required"}', $this->request('POST', '/api/orders/', '{}'));
        $this->assertSame(400, $this->httpStatus());
    }

    public static function unroutableProvider(): array
    {
        return [
            'unknown path' => ['GET', '/unknown'],
            'root' => ['GET', '/'],
            'api root' => ['GET', '/api'],
            'path is case sensitive' => ['GET', '/Health'],
            'health with extra segment' => ['GET', '/health/extra'],
            'PATCH products' => ['PATCH', '/api/products'],
            'PATCH product id' => ['PATCH', '/api/products/1'],
            'DELETE order by id' => ['DELETE', '/api/orders/1'],
            'PUT orders' => ['PUT', '/api/orders'],
            'PUT order by id' => ['PUT', '/api/orders/1'],
            'POST order by id' => ['POST', '/api/orders/1'],
            'POST health' => ['POST', '/health'],
            'HEAD health' => ['HEAD', '/health'],
            'OPTIONS products' => ['OPTIONS', '/api/products'],
            'lowercase verb' => ['get', '/api/products'],
            'POST product by id' => ['POST', '/api/products/1'],
            'DELETE products without id' => ['DELETE', '/api/products'],
            'PUT products without id' => ['PUT', '/api/products'],
            'GET non numeric id' => ['GET', '/api/products/abc'],
            'GET decimal id' => ['GET', '/api/products/1.5'],
            'GET negative id' => ['GET', '/api/products/-1'],
            'GET non numeric order id' => ['GET', '/api/orders/abc'],
            'order items sub-resource' => ['GET', '/api/orders/1/items'],
            'product sub-resource' => ['GET', '/api/products/1/reviews'],
        ];
    }

    #[DataProvider('unroutableProvider')]
    public function testUnroutableRequestsReturnNotFound(string $method, string $uri): void
    {
        $out = $this->request($method, $uri, '{"name":"x","price":1,"items":[{"product_id":1,"quantity":1}]}');

        $this->assertSame(404, $this->httpStatus());
        $this->assertSame(self::NOT_FOUND, $out);
        $this->assertNoSideEffects();
    }
}
