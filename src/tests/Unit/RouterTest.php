<?php

namespace Tests\Unit;

use App\Router;
use PDO;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use Predis\Client as RedisClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\Support\ControllerTestCase;
use Tests\Support\FakePdo;
use Tests\Support\PhpInputStream;

/**
 * Black-box dispatch tests for App\Router (Design Decision 1, approach 1): the Router
 * builds real controllers from its three constructor arguments and each test asserts
 * which handler ran by the SQL / Redis calls / output it produced. No production seams.
 */
final class RouterTest extends ControllerTestCase
{
    private const NOT_FOUND = '{"error":"Not found"}';

    private const SELECT_PRODUCT = 'SELECT * FROM products WHERE id = ?';
    private const SELECT_PRODUCT_ID = 'SELECT id FROM products WHERE id = ?';
    private const LIST_PRODUCTS = 'SELECT * FROM products ORDER BY id';
    private const INSERT_PRODUCT = 'INSERT INTO products (name, description, price, stock) VALUES (?, ?, ?, ?)';
    private const UPDATE_PRODUCT = 'UPDATE products SET name = ? WHERE id = ?';
    private const DELETE_PRODUCT = 'DELETE FROM products WHERE id = ?';
    private const LIST_ORDERS = 'SELECT * FROM orders ORDER BY id DESC';
    private const SELECT_ORDER = 'SELECT * FROM orders WHERE id = ?';
    private const SELECT_ITEMS = 'SELECT oi.*, p.name as product_name FROM order_items oi JOIN products p ON p.id = oi.product_id WHERE oi.order_id = ?';
    private const INSERT_ORDER = 'INSERT INTO orders (total) VALUES (0)';
    private const SELECT_FOR_UPDATE = 'SELECT * FROM products WHERE id = ? FOR UPDATE';
    private const INSERT_ITEM = 'INSERT INTO order_items (order_id, product_id, quantity, price) VALUES (?, ?, ?, ?)';
    private const UPDATE_STOCK = 'UPDATE products SET stock = stock - ? WHERE id = ?';
    private const UPDATE_TOTAL = 'UPDATE orders SET total = ? WHERE id = ?';

    private const HEALTH_OK = '{"status":"ok","services":{"mysql":"connected","redis":"connected","rabbitmq":"connected"}}';

    private const PRODUCT = ['id' => 7, 'name' => 'Widget', 'description' => 'A widget', 'price' => '19.99', 'stock' => 10];
    private const PRODUCT_JSON = '{"id":7,"name":"Widget","description":"A widget","price":"19.99","stock":10}';
    private const ORDER = ['id' => 42, 'total' => '39.98', 'created_at' => '2026-01-01 10:00:00'];
    private const ITEMS = [
        ['id' => 1, 'order_id' => 42, 'product_id' => 7, 'quantity' => 2, 'price' => '19.99', 'product_name' => 'Widget'],
    ];

    private FakePdo $db;
    private AMQPStreamConnection&MockObject $amqp;
    private AMQPChannel&MockObject $channel;

    protected function setUp(): void
    {
        parent::setUp();
        PhpInputStream::register();
        PhpInputStream::setBody('');
        $this->db = new FakePdo($this->mockPdo(), fn () => $this->createMock(\PDOStatement::class));
        $this->amqp = $this->createMock(AMQPStreamConnection::class);
        $this->amqp->method('isConnected')->willReturn(true);
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
        $this->assertFalse($this->db->hasInteractions(), 'database was touched');
        $this->assertSame([], $this->redis->calls, 'redis was touched');
    }

    private function assertNotFound(string $out): void
    {
        $this->assertSame(404, $this->httpStatus());
        $this->assertSame(self::NOT_FOUND, $out);
    }

    private function productRow(int $id): array
    {
        return ['id' => $id] + self::PRODUCT;
    }

    // --- constructor / structure ---

    public function testConstructorAcceptsExactlyThreeTypedArguments(): void
    {
        $constructor = (new \ReflectionClass(Router::class))->getConstructor();

        $this->assertNotNull($constructor);
        $this->assertTrue($constructor->isPublic());
        $this->assertSame(3, $constructor->getNumberOfParameters());
        $this->assertSame(3, $constructor->getNumberOfRequiredParameters());

        $expected = [PDO::class, RedisClient::class, AMQPStreamConnection::class];
        foreach ($constructor->getParameters() as $i => $parameter) {
            $type = $parameter->getType();
            $this->assertInstanceOf(\ReflectionNamedType::class, $type);
            $this->assertFalse($type->allowsNull(), "parameter {$i} must not be nullable");
            $this->assertSame($expected[$i], $type->getName(), "parameter {$i} type");
        }
    }

    public function testConstructorRejectsMissingOrWrongArguments(): void
    {
        $this->expectException(\ArgumentCountError::class);
        new Router($this->db->pdo, $this->redis);
    }

    public function testConstructorRejectsWrongArgumentType(): void
    {
        $this->expectException(\TypeError::class);
        new Router($this->redis, $this->db->pdo, $this->amqp);
    }

    public function testRouterOnlyDeclaresConstructorAndHandleRequest(): void
    {
        $class = new \ReflectionClass(Router::class);
        $own = array_map(
            fn (\ReflectionMethod $m) => $m->getName(),
            array_filter($class->getMethods(), fn (\ReflectionMethod $m) => $m->getDeclaringClass()->getName() === Router::class)
        );
        sort($own);

        $this->assertSame(['__construct', 'handleRequest'], $own);
        $handle = $class->getMethod('handleRequest');
        $this->assertTrue($handle->isPublic());
        $this->assertSame(0, $handle->getNumberOfParameters());
        $this->assertSame('void', (string) $handle->getReturnType());
    }

    public function testConstructingRouterHasNoSideEffects(): void
    {
        new Router($this->db->pdo, $this->redis, $this->amqp);

        $this->assertNoSideEffects();
        $this->assertSame('', $this->capturedOutput());
    }

    // --- GET /health ---

    public function testGetHealthReachesHealthProbe(): void
    {
        $this->db->onSql('SELECT 1');

        $out = $this->request('GET', '/health');

        $this->assertSame(self::HEALTH_OK, $out);
        $this->assertSame(200, $this->httpStatus());
        $this->assertSame(['SELECT 1'], $this->db->queried);
        $this->assertSame([], $this->db->prepared);
        $this->assertSame([['ping', []]], $this->redis->calls);
    }

    public function testHealthProbeUsesTheQueueConnectionPassedToTheRouter(): void
    {
        $this->db->onSql('SELECT 1');
        $this->amqp = $this->createMock(AMQPStreamConnection::class);
        $this->amqp->expects($this->once())->method('isConnected')->willReturn(false);

        $out = $this->request('GET', '/health');

        $this->assertSame('{"status":"ok","services":{"mysql":"connected","redis":"connected","rabbitmq":"error"}}', $out);
    }

    public function testHealthProbeUsesTheDatabaseAndCachePassedToTheRouter(): void
    {
        $this->db->onSql('SELECT 1', queryThrows: new \PDOException('down'));
        $this->redis->pingException = new \RuntimeException('down');

        $out = $this->request('GET', '/health');

        $this->assertSame('{"status":"degraded","services":{"mysql":"error","redis":"error","rabbitmq":"connected"}}', $out);
    }

    // --- products ---

    public function testGetProductsReachesProductList(): void
    {
        $this->db->onSql(self::LIST_PRODUCTS, rows: [self::PRODUCT]);

        $out = $this->request('GET', '/api/products');

        $this->assertSame(json_encode([self::PRODUCT]), $out);
        $this->assertSame(200, $this->httpStatus());
        $this->assertSame([self::LIST_PRODUCTS], $this->db->queried);
        $this->assertSame([], $this->db->prepared);
        $this->assertSame([
            ['get', ['products:all']],
            ['setex', ['products:all', 60, json_encode([self::PRODUCT])]],
        ], $this->redis->calls);
    }

    public function testPostProductsReachesProductCreateWithBodyFromPhpInput(): void
    {
        $this->db->withInsertIds('7')
            ->onSql(self::INSERT_PRODUCT)
            ->onSql(self::SELECT_PRODUCT, fetch: self::PRODUCT);

        $out = $this->request('POST', '/api/products', '{"name":"Widget","description":"A widget","price":"19.99","stock":"10"}');

        $this->assertSame(201, $this->httpStatus());
        $this->assertSame(self::PRODUCT_JSON, $out);
        $this->assertSame([self::INSERT_PRODUCT, self::SELECT_PRODUCT], $this->db->prepared);
        $this->assertSame([['Widget', 'A widget', 19.99, 10]], $this->db->paramsFor(self::INSERT_PRODUCT));
        $this->assertSame([[7]], $this->db->paramsFor(self::SELECT_PRODUCT));
        $this->assertSame([
            ['del', ['products:all']],
            ['get', ['products:7']],
            ['setex', ['products:7', 60, self::PRODUCT_JSON]],
        ], $this->redis->calls);
    }

    public function testPostProductsWithInvalidBodyIsHandledByProductController(): void
    {
        $out = $this->request('POST', '/api/products', '{}');

        $this->assertSame(400, $this->httpStatus());
        $this->assertSame('{"error":"name and price are required"}', $out);
        $this->assertNoSideEffects();
    }

    public static function integerIdProvider(): array
    {
        return [
            'plain' => ['7', 7],
            'leading zeros' => ['007', 7],
            'multi digit' => ['123', 123],
            'single zero' => ['0', 0],
            'only zeros' => ['000', 0],
            'large' => ['2147483647', 2147483647],
        ];
    }

    #[DataProvider('integerIdProvider')]
    public function testGetProductByIdReachesProductGetWithIntegerId(string $segment, int $id): void
    {
        $this->db->onSql(self::SELECT_PRODUCT, fetch: $this->productRow($id));

        $out = $this->request('GET', "/api/products/{$segment}");

        $this->assertSame(200, $this->httpStatus());
        $this->assertSame(json_encode($this->productRow($id)), $out);
        $this->assertSame([self::SELECT_PRODUCT], $this->db->prepared);
        $params = $this->db->paramsFor(self::SELECT_PRODUCT);
        $this->assertSame([[$id]], $params);
        $this->assertIsInt($params[0][0]);
        $this->assertSame([
            ['get', ["products:{$id}"]],
            ['setex', ["products:{$id}", 60, json_encode($this->productRow($id))]],
        ], $this->redis->calls);
    }

    #[DataProvider('integerIdProvider')]
    public function testPutProductByIdReachesProductUpdateWithIntegerId(string $segment, int $id): void
    {
        $this->db->onSql(self::SELECT_PRODUCT_ID, fetch: ['id' => $id])
            ->onSql(self::UPDATE_PRODUCT)
            ->onSql(self::SELECT_PRODUCT, fetch: $this->productRow($id));

        $out = $this->request('PUT', "/api/products/{$segment}", '{"name":"Renamed"}');

        $this->assertSame(200, $this->httpStatus());
        $this->assertSame(json_encode($this->productRow($id)), $out);
        $this->assertSame([self::SELECT_PRODUCT_ID, self::UPDATE_PRODUCT, self::SELECT_PRODUCT], $this->db->prepared);
        $this->assertSame([[$id]], $this->db->paramsFor(self::SELECT_PRODUCT_ID));
        $this->assertSame([['Renamed', $id]], $this->db->paramsFor(self::UPDATE_PRODUCT));
        $this->assertIsInt($this->db->paramsFor(self::UPDATE_PRODUCT)[0][1]);
        $this->assertSame([[$id]], $this->db->paramsFor(self::SELECT_PRODUCT));
        $this->assertSame([
            ['del', ["products:{$id}"]],
            ['del', ['products:all']],
            ['get', ["products:{$id}"]],
            ['setex', ["products:{$id}", 60, json_encode($this->productRow($id))]],
        ], $this->redis->calls);
    }

    public function testPutProductWithEmptyBodyIsHandledByProductUpdate(): void
    {
        $this->db->onSql(self::SELECT_PRODUCT_ID, fetch: ['id' => 7]);

        $out = $this->request('PUT', '/api/products/7', '{}');

        $this->assertSame(400, $this->httpStatus());
        $this->assertSame('{"error":"No fields to update"}', $out);
        $this->assertSame([self::SELECT_PRODUCT_ID], $this->db->prepared);
    }

    #[DataProvider('integerIdProvider')]
    public function testDeleteProductByIdReachesProductDeleteWithIntegerId(string $segment, int $id): void
    {
        $this->db->onSql(self::DELETE_PRODUCT, rowCount: 1);

        $out = $this->request('DELETE', "/api/products/{$segment}");

        $this->assertSame(204, $this->httpStatus());
        $this->assertSame('', $out);
        $this->assertSame([self::DELETE_PRODUCT], $this->db->prepared);
        $params = $this->db->paramsFor(self::DELETE_PRODUCT);
        $this->assertSame([[$id]], $params);
        $this->assertIsInt($params[0][0]);
        $this->assertSame([['del', ["products:{$id}"]], ['del', ['products:all']]], $this->redis->calls);
    }

    public function testDeleteProductNotFoundComesFromProductController(): void
    {
        $this->db->onSql(self::DELETE_PRODUCT, rowCount: 0);

        $out = $this->request('DELETE', '/api/products/9');

        $this->assertSame(404, $this->httpStatus());
        $this->assertSame('{"error":"Product not found"}', $out);
    }

    // --- orders ---

    public function testGetOrdersReachesOrderList(): void
    {
        $this->db->onSql(self::LIST_ORDERS, rows: [self::ORDER]);

        $out = $this->request('GET', '/api/orders');

        $this->assertSame(json_encode([self::ORDER]), $out);
        $this->assertSame(200, $this->httpStatus());
        $this->assertSame([self::LIST_ORDERS], $this->db->queried);
        $this->assertSame([], $this->db->prepared);
        $this->assertSame([], $this->redis->calls);
    }

    #[DataProvider('integerIdProvider')]
    public function testGetOrderByIdReachesOrderGetWithIntegerId(string $segment, int $id): void
    {
        $order = ['id' => $id] + self::ORDER;
        $this->db->onSql(self::SELECT_ORDER, fetch: $order)
            ->onSql(self::SELECT_ITEMS, rows: self::ITEMS);

        $out = $this->request('GET', "/api/orders/{$segment}");

        $this->assertSame(200, $this->httpStatus());
        $this->assertSame(json_encode($order + ['items' => self::ITEMS]), $out);
        $this->assertSame([self::SELECT_ORDER, self::SELECT_ITEMS], $this->db->prepared);
        $this->assertSame([[$id]], $this->db->paramsFor(self::SELECT_ORDER));
        $this->assertSame([[$id]], $this->db->paramsFor(self::SELECT_ITEMS));
        $this->assertIsInt($this->db->paramsFor(self::SELECT_ORDER)[0][0]);
        $this->assertSame([], $this->redis->calls);
    }

    public function testPostOrdersReachesOrderCreateAndPublishesViaDefaultPublisher(): void
    {
        $this->db->withInsertIds('42')
            ->onSql(self::INSERT_ORDER)
            ->onSql(self::SELECT_FOR_UPDATE, fetch: $this->productRow(7))
            ->onSql(self::INSERT_ITEM)
            ->onSql(self::UPDATE_STOCK)
            ->onSql(self::UPDATE_TOTAL)
            ->onSql(self::SELECT_ORDER, fetch: self::ORDER)
            ->onSql(self::SELECT_ITEMS, rows: self::ITEMS);
        $this->channel->expects($this->once())->method('queue_declare')->with('order_created', false, true, false, false);
        $this->channel->expects($this->once())->method('basic_publish');

        $out = $this->request('POST', '/api/orders', '{"items":[{"product_id":7,"quantity":2}]}');

        $this->assertSame(201, $this->httpStatus());
        $this->assertSame(json_encode(self::ORDER + ['items' => self::ITEMS]), $out);
        $this->assertSame(['beginTransaction', 'commit'], $this->db->events);
        $this->assertSame([[7]], $this->db->paramsFor(self::SELECT_FOR_UPDATE));
        $this->assertSame([[42]], $this->db->paramsFor(self::SELECT_ORDER));
        $this->assertSame([['del', ['products:7']], ['del', ['products:all']]], $this->redis->calls);
    }

    public function testPostOrdersWithoutItemsIsHandledByOrderCreate(): void
    {
        $out = $this->request('POST', '/api/orders', '{}');

        $this->assertSame(400, $this->httpStatus());
        $this->assertSame('{"error":"items array is required"}', $out);
        $this->assertNoSideEffects();
    }

    // --- URI normalization ---

    public static function trailingSlashAndQueryProvider(): array
    {
        return [
            'products trailing slash' => ['/api/products/'],
            'products query' => ['/api/products?page=2'],
            'products slash and query' => ['/api/products/?page=2&x=1'],
            'products repeated slashes' => ['/api/products///'],
            'products query looks like an id' => ['/api/products?id=3'],
            'products query looks like a path' => ['/api/products?/health'],
        ];
    }

    #[DataProvider('trailingSlashAndQueryProvider')]
    public function testProductListUriIsNormalized(string $uri): void
    {
        $this->db->onSql(self::LIST_PRODUCTS, rows: [self::PRODUCT]);

        $out = $this->request('GET', $uri);

        $this->assertSame(json_encode([self::PRODUCT]), $out);
        $this->assertSame([self::LIST_PRODUCTS], $this->db->queried);
        $this->assertSame([], $this->db->prepared);
    }

    public function testHealthTrailingSlash(): void
    {
        $this->db->onSql('SELECT 1');

        $this->assertSame(self::HEALTH_OK, $this->request('GET', '/health/'));
        $this->assertSame(['SELECT 1'], $this->db->queried);
    }

    public function testHealthQueryStringIgnored(): void
    {
        $this->db->onSql('SELECT 1');

        $this->assertSame(self::HEALTH_OK, $this->request('GET', '/health?verbose=1'));
        $this->assertSame(self::HEALTH_OK, $this->request('GET', '/health/?verbose=1'));
    }

    public function testProductByIdTrailingSlashDispatchesWithIntegerId(): void
    {
        $this->db->onSql(self::SELECT_PRODUCT, fetch: $this->productRow(7));

        $out = $this->request('GET', '/api/products/007/');

        $this->assertSame(json_encode($this->productRow(7)), $out);
        $this->assertSame([[7]], $this->db->paramsFor(self::SELECT_PRODUCT));
    }

    public function testProductByIdQueryStringIgnored(): void
    {
        $this->db->onSql(self::SELECT_PRODUCT, fetch: $this->productRow(7));

        $out = $this->request('GET', '/api/products/7?fields=name&id=9');

        $this->assertSame(json_encode($this->productRow(7)), $out);
        $this->assertSame([[7]], $this->db->paramsFor(self::SELECT_PRODUCT));
    }

    public function testOrderByIdTrailingSlashDispatchesWithIntegerId(): void
    {
        $this->db->onSql(self::SELECT_ORDER, fetch: ['id' => 5] + self::ORDER)
            ->onSql(self::SELECT_ITEMS, rows: []);

        $out = $this->request('GET', '/api/orders/5/');

        $this->assertSame(200, $this->httpStatus());
        $this->assertSame(json_encode(['id' => 5] + self::ORDER + ['items' => []]), $out);
        $this->assertSame([[5]], $this->db->paramsFor(self::SELECT_ORDER));
        $this->assertSame([[5]], $this->db->paramsFor(self::SELECT_ITEMS));
    }

    public function testOrderListTrailingSlashAndQueryString(): void
    {
        $this->db->onSql(self::LIST_ORDERS, rows: []);

        $this->assertSame('[]', $this->request('GET', '/api/orders/'));
        $this->assertSame('[]', $this->request('GET', '/api/orders?status=open'));
        $this->assertSame([self::LIST_ORDERS, self::LIST_ORDERS], $this->db->queried);
    }

    public function testWriteRoutesAcceptTrailingSlashAndQueryString(): void
    {
        $this->db->onSql(self::DELETE_PRODUCT, rowCount: 1)
            ->onSql(self::SELECT_PRODUCT_ID, fetch: false);

        $this->assertSame('{"error":"name and price are required"}', $this->request('POST', '/api/products/?x=1', '{}'));
        $this->assertSame('{"error":"Product not found"}', $this->request('PUT', '/api/products/7/', '{"name":"x"}'));
        $this->assertSame('', $this->request('DELETE', '/api/products/7/?force=1'));
        $this->assertSame(204, $this->httpStatus());
        $this->assertSame('{"error":"items array is required"}', $this->request('POST', '/api/orders/', '{}'));
    }

    // --- 404 default ---

    public static function notFoundProvider(): array
    {
        return [
            // unknown paths
            'unknown path' => ['GET', '/unknown'],
            'root' => ['GET', '/'],
            'empty path' => ['GET', ''],
            'api root' => ['GET', '/api'],
            'unknown resource' => ['GET', '/api/customers'],
            'health prefix only' => ['GET', '/healthz'],
            'health under api' => ['GET', '/api/health'],
            'products without api prefix' => ['GET', '/products'],
            'versioned api' => ['GET', '/v1/api/products'],
            'products prefix match' => ['GET', '/api/productsX'],
            'orders prefix match' => ['GET', '/api/orders2'],
            // unsupported verbs on known paths
            'POST health' => ['POST', '/health'],
            'PUT health' => ['PUT', '/health'],
            'DELETE health' => ['DELETE', '/health'],
            'HEAD health' => ['HEAD', '/health'],
            'OPTIONS health' => ['OPTIONS', '/health'],
            'PUT products' => ['PUT', '/api/products'],
            'DELETE products' => ['DELETE', '/api/products'],
            'PATCH products' => ['PATCH', '/api/products'],
            'HEAD products' => ['HEAD', '/api/products'],
            'OPTIONS products' => ['OPTIONS', '/api/products'],
            'POST product by id' => ['POST', '/api/products/7'],
            'PATCH product by id' => ['PATCH', '/api/products/7'],
            'PUT orders' => ['PUT', '/api/orders'],
            'DELETE orders' => ['DELETE', '/api/orders'],
            'PUT order by id' => ['PUT', '/api/orders/5'],
            'POST order by id' => ['POST', '/api/orders/5'],
            'DELETE order by id' => ['DELETE', '/api/orders/5'],
            'PATCH order by id' => ['PATCH', '/api/orders/5'],
            'lowercase verb get' => ['get', '/api/products'],
            'lowercase verb post' => ['post', '/api/orders'],
            'empty verb' => ['', '/api/products'],
            // non-numeric ids
            'letters id' => ['GET', '/api/products/abc'],
            'trailing letter id' => ['GET', '/api/products/7a'],
            'leading letter id' => ['GET', '/api/products/a7'],
            'decimal id' => ['GET', '/api/products/1.5'],
            'negative id' => ['GET', '/api/products/-1'],
            'plus id' => ['GET', '/api/products/+1'],
            'encoded space id' => ['GET', '/api/products/%207'],
            'non ascii digit id' => ['GET', "/api/products/\u{0663}"],
            'PUT non numeric id' => ['PUT', '/api/products/abc'],
            'DELETE non numeric id' => ['DELETE', '/api/products/abc'],
            'order non numeric id' => ['GET', '/api/orders/abc'],
            'order negative id' => ['GET', '/api/orders/-5'],
            // extra segments
            'product extra segment' => ['GET', '/api/products/7/reviews'],
            'product extra numeric segment' => ['GET', '/api/products/7/8'],
            'PUT product extra segment' => ['PUT', '/api/products/7/stock'],
            'DELETE product extra segment' => ['DELETE', '/api/products/7/x'],
            'POST products extra segment' => ['POST', '/api/products/new'],
            'order extra segment' => ['GET', '/api/orders/5/items'],
            'POST orders extra segment' => ['POST', '/api/orders/5/items'],
            'health extra segment' => ['GET', '/health/extra'],
            'double slash inside' => ['GET', '/api/products//7'],
            // case sensitivity
            'uppercase health' => ['GET', '/HEALTH'],
            'capital health' => ['GET', '/Health'],
            'uppercase api' => ['GET', '/API/products'],
            'capital products' => ['GET', '/api/Products'],
            'capital orders' => ['GET', '/api/Orders'],
            'capital order by id' => ['GET', '/Api/orders/5'],
            'uppercase product by id' => ['GET', '/api/PRODUCTS/7'],
        ];
    }

    #[DataProvider('notFoundProvider')]
    public function testUnroutableRequestsReturn404WithoutTouchingBackends(string $method, string $uri): void
    {
        $this->amqp = $this->createMock(AMQPStreamConnection::class);
        $this->amqp->expects($this->never())->method($this->anything());
        $this->channel->expects($this->never())->method($this->anything());

        $out = $this->request($method, $uri, '{"name":"x","price":1,"items":[{"product_id":1,"quantity":1}]}');

        $this->assertNotFound($out);
        $this->assertNoSideEffects();
    }

    public function testNotFoundBodyIsExactJsonAndOnlyStatusIsSetByRouter(): void
    {
        $out = $this->request('GET', '/nope');

        $this->assertSame('{"error":"Not found"}', $out);
        $this->assertSame(404, $this->httpStatus());
    }

    // --- full verb x path matrix ---

    /** @return array<string, list<string>> path => verbs the Router serves */
    private static function routeTable(): array
    {
        return [
            '/health' => ['GET'],
            '/api/products' => ['GET', 'POST'],
            '/api/products/7' => ['GET', 'PUT', 'DELETE'],
            '/api/orders' => ['GET', 'POST'],
            '/api/orders/5' => ['GET'],
        ];
    }

    public static function methodMatrixProvider(): array
    {
        $cases = [];
        foreach (self::routeTable() as $path => $served) {
            foreach (['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'HEAD', 'OPTIONS'] as $verb) {
                $cases["{$verb} {$path}"] = [$verb, $path, in_array($verb, $served, true)];
            }
        }
        return $cases;
    }

    #[DataProvider('methodMatrixProvider')]
    public function testOnlyTheDocumentedMethodPathPairsAreServed(string $method, string $path, bool $served): void
    {
        // Permissive stubs so every served route can complete without "unmatched SQL" noise.
        $this->db->onSql('SELECT 1')
            ->onSql(self::LIST_PRODUCTS, rows: [])
            ->onSql(self::SELECT_PRODUCT, fetch: $this->productRow(7))
            ->onSql(self::SELECT_PRODUCT_ID, fetch: false)
            ->onSql(self::INSERT_PRODUCT)
            ->onSql(self::DELETE_PRODUCT, rowCount: 0)
            ->onSql(self::LIST_ORDERS, rows: [])
            ->onSql(self::SELECT_ORDER, fetch: false)
            ->withInsertIds('7');

        $out = $this->request($method, $path, '{}');

        if ($served) {
            $this->assertNotSame(self::NOT_FOUND, $out, "{$method} {$path} should be routed");
        } else {
            $this->assertNotFound($out);
            $this->assertNoSideEffects();
        }
    }
}
