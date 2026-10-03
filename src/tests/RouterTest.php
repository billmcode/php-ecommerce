<?php

namespace Tests;

use App\Controllers\HealthController;
use App\Controllers\OrderController;
use App\Controllers\ProductController;
use App\Router;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PHPUnit\Framework\MockObject\MockObject;
use Predis\Client as RedisClient;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionProperty;
use Tests\Support\HttpTestCase;
use Tests\Support\QueueReset;

/**
 * Router-level dispatch tests.
 *
 * Delegation is proven WITHOUT any SQL: the Router's private controller properties are replaced
 * (via reflection) with call-recording mocks, so each test asserts exactly which controller method
 * received which argument. A few wiring tests then use the real controllers and observe their effect
 * on the shared doubles. Request-level behaviour through the real stack stays in
 * Characterization\DispatchTest; this file focuses on the Router as a pure dispatcher.
 */
final class RouterTest extends HttpTestCase
{
    private const NOT_FOUND = '{"error":"Not found"}';

    /** @var list<array{0: string, 1: list<mixed>}> Calls recorded by the controller mocks, in order. */
    private array $calls = [];

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    private function realRouter(): Router
    {
        return new Router($this->pdo, $this->redis, $this->amqp->connection());
    }

    /** A Router whose three controllers are replaced by mocks that log "<controller>.<method>" and the arguments. */
    private function routerWithRecordingControllers(): Router
    {
        $router = $this->realRouter();
        $this->calls = [];

        $replace = function (string $property, string $class, string $label, array $methods) use ($router): void {
            $mock = $this->createMock($class);
            foreach ($methods as $method) {
                $mock->method($method)->willReturnCallback(function (...$args) use ($label, $method): void {
                    $this->calls[] = ["{$label}.{$method}", $args];
                });
            }
            (new ReflectionProperty(Router::class, $property))->setValue($router, $mock);
        };

        $replace('health', HealthController::class, 'health', ['health']);
        $replace('products', ProductController::class, 'products', ['list', 'get', 'create', 'update', 'delete']);
        $replace('orders', OrderController::class, 'orders', ['list', 'get', 'create']);

        return $router;
    }

    /** @return array{0: int, 1: string} */
    private function dispatch(Router $router, string $method, string $uri): array
    {
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI'] = $uri;

        return $this->capture(static function () use ($router): void {
            $router->handleRequest();
        });
    }

    private function assertDoublesUntouched(): void
    {
        self::assertSame([], $this->pdo->executed(), 'no SQL');
        self::assertSame([], $this->pdo->transactionEvents(), 'no transaction');
        self::assertSame([], $this->redis->calls(), 'no cache call');
        self::assertSame([], $this->amqp->published(), 'nothing published');
        self::assertSame(0, $this->amqp->channelsOpened(), 'no channel opened');
    }

    // ------------------------------------------------------------------
    // Delegation: every verb/path pair reaches exactly one controller method
    // ------------------------------------------------------------------

    /** @return array<string, array{0: string, 1: string, 2: string, 3: list<int>}> */
    public static function routes(): array
    {
        return [
            'GET /health' => ['GET', '/health', 'health.health', []],
            'GET /api/products' => ['GET', '/api/products', 'products.list', []],
            'GET /api/products/{id}' => ['GET', '/api/products/3', 'products.get', [3]],
            'POST /api/products' => ['POST', '/api/products', 'products.create', []],
            'PUT /api/products/{id}' => ['PUT', '/api/products/5', 'products.update', [5]],
            'DELETE /api/products/{id}' => ['DELETE', '/api/products/6', 'products.delete', [6]],
            'GET /api/orders' => ['GET', '/api/orders', 'orders.list', []],
            'GET /api/orders/{id}' => ['GET', '/api/orders/7', 'orders.get', [7]],
            'POST /api/orders' => ['POST', '/api/orders', 'orders.create', []],
        ];
    }

    #[Test]
    #[DataProvider('routes')]
    public function a_request_is_delegated_to_exactly_one_controller_method(
        string $method,
        string $uri,
        string $target,
        array $arguments
    ): void {
        $router = $this->routerWithRecordingControllers();

        $this->dispatch($router, $method, $uri);

        self::assertSame([[$target, $arguments]], $this->calls);
    }

    #[Test]
    #[DataProvider('routes')]
    public function delegation_works_with_a_trailing_slash(string $method, string $uri, string $target, array $arguments): void
    {
        $router = $this->routerWithRecordingControllers();

        $this->dispatch($router, $method, $uri . '/');

        self::assertSame([[$target, $arguments]], $this->calls);
    }

    #[Test]
    #[DataProvider('routes')]
    public function delegation_ignores_the_query_string(string $method, string $uri, string $target, array $arguments): void
    {
        $router = $this->routerWithRecordingControllers();

        $this->dispatch($router, $method, $uri . '?page=2&sort=name');

        self::assertSame([[$target, $arguments]], $this->calls);
    }

    #[Test]
    #[DataProvider('routes')]
    public function delegation_works_with_trailing_slash_and_query_string_together(
        string $method,
        string $uri,
        string $target,
        array $arguments
    ): void {
        $router = $this->routerWithRecordingControllers();

        $this->dispatch($router, $method, $uri . '/?x=1');

        self::assertSame([[$target, $arguments]], $this->calls);
    }

    #[Test]
    #[DataProvider('routes')]
    public function a_delegated_request_never_adds_a_router_level_status_or_body(
        string $method,
        string $uri,
        string $target,
        array $arguments
    ): void {
        $router = $this->routerWithRecordingControllers();

        [$status, $body] = $this->dispatch($router, $method, $uri);

        // The mocked handlers print nothing and set no status: whatever the real handler does is all the client sees.
        self::assertSame(200, $status);
        self::assertSame('', $body);
        self::assertCount(1, $this->calls, "only {$target} may run");
    }

    #[Test]
    public function the_product_and_order_collections_and_items_reach_different_controllers(): void
    {
        $router = $this->routerWithRecordingControllers();

        $this->dispatch($router, 'GET', '/api/products');
        $this->dispatch($router, 'GET', '/api/orders');
        $this->dispatch($router, 'GET', '/api/products/1');
        $this->dispatch($router, 'GET', '/api/orders/1');

        self::assertSame(
            [
                ['products.list', []],
                ['orders.list', []],
                ['products.get', [1]],
                ['orders.get', [1]],
            ],
            $this->calls
        );
    }

    #[Test]
    public function one_id_path_reaches_a_different_method_per_verb(): void
    {
        $router = $this->routerWithRecordingControllers();

        $this->dispatch($router, 'GET', '/api/products/9');
        $this->dispatch($router, 'PUT', '/api/products/9');
        $this->dispatch($router, 'DELETE', '/api/products/9');

        self::assertSame(
            [['products.get', [9]], ['products.update', [9]], ['products.delete', [9]]],
            $this->calls
        );
    }

    #[Test]
    public function one_collection_path_reaches_a_different_method_per_verb(): void
    {
        $router = $this->routerWithRecordingControllers();

        $this->dispatch($router, 'GET', '/api/products');
        $this->dispatch($router, 'POST', '/api/products');
        $this->dispatch($router, 'GET', '/api/orders');
        $this->dispatch($router, 'POST', '/api/orders');

        self::assertSame(
            [['products.list', []], ['products.create', []], ['orders.list', []], ['orders.create', []]],
            $this->calls
        );
    }

    #[Test]
    public function every_request_is_dispatched_independently_by_the_current_globals(): void
    {
        $router = $this->routerWithRecordingControllers();

        $this->dispatch($router, 'GET', '/health');
        $this->dispatch($router, 'GET', '/nowhere');
        $this->dispatch($router, 'GET', '/health');

        self::assertSame([['health.health', []], ['health.health', []]], $this->calls);
    }

    // ------------------------------------------------------------------
    // Ids
    // ------------------------------------------------------------------

    /** @return array<string, array{0: string, 1: string, 2: string, 3: int}> */
    public static function idRoutes(): array
    {
        return [
            'product get' => ['GET', '/api/products/42', 'products.get', 42],
            'product update' => ['PUT', '/api/products/42', 'products.update', 42],
            'product delete' => ['DELETE', '/api/products/42', 'products.delete', 42],
            'order get' => ['GET', '/api/orders/42', 'orders.get', 42],
            'product get leading zeros' => ['GET', '/api/products/007', 'products.get', 7],
            'order get leading zeros' => ['GET', '/api/orders/0012', 'orders.get', 12],
            'product id zero' => ['GET', '/api/products/0', 'products.get', 0],
            'order id zero' => ['GET', '/api/orders/0', 'orders.get', 0],
            'multi digit' => ['PUT', '/api/products/1234567', 'products.update', 1234567],
        ];
    }

    #[Test]
    #[DataProvider('idRoutes')]
    public function ids_are_passed_to_the_controller_as_integers(string $method, string $uri, string $target, int $id): void
    {
        $router = $this->routerWithRecordingControllers();

        $this->dispatch($router, $method, $uri);

        self::assertCount(1, $this->calls);
        self::assertSame($target, $this->calls[0][0]);
        self::assertSame([$id], $this->calls[0][1]);
        self::assertIsInt($this->calls[0][1][0]);
    }

    #[Test]
    public function controller_id_parameters_are_declared_int(): void
    {
        foreach ([[ProductController::class, ['get', 'update', 'delete']], [OrderController::class, ['get']]] as [$class, $methods]) {
            foreach ($methods as $name) {
                $parameters = (new ReflectionMethod($class, $name))->getParameters();
                self::assertCount(1, $parameters, "{$class}::{$name}");
                $type = $parameters[0]->getType();
                self::assertInstanceOf(ReflectionNamedType::class, $type);
                self::assertSame('int', $type->getName(), "{$class}::{$name}");
            }
        }
    }

    // ------------------------------------------------------------------
    // 404s
    // ------------------------------------------------------------------

    /** @return array<string, array{0: string, 1: string}> */
    public static function unmatched(): array
    {
        return [
            // non-numeric ids
            'product id letters' => ['GET', '/api/products/abc'],
            'order id letters' => ['GET', '/api/orders/x'],
            'product id mixed' => ['GET', '/api/products/1x'],
            'product id negative' => ['GET', '/api/products/-1'],
            'product put id letters' => ['PUT', '/api/products/abc'],
            'product delete id letters' => ['DELETE', '/api/products/abc'],
            'order id negative' => ['GET', '/api/orders/-5'],
            'order id decimal' => ['GET', '/api/orders/1.5'],

            // unsupported methods on known paths
            'PATCH /api/products' => ['PATCH', '/api/products'],
            'POST /api/products/1' => ['POST', '/api/products/1'],
            'PATCH /api/products/1' => ['PATCH', '/api/products/1'],
            'PUT /api/products' => ['PUT', '/api/products'],
            'DELETE /api/products' => ['DELETE', '/api/products'],
            'DELETE /api/orders/1' => ['DELETE', '/api/orders/1'],
            'PUT /api/orders/1' => ['PUT', '/api/orders/1'],
            'POST /api/orders/1' => ['POST', '/api/orders/1'],
            'PUT /api/orders' => ['PUT', '/api/orders'],
            'DELETE /api/orders' => ['DELETE', '/api/orders'],
            'POST /health' => ['POST', '/health'],
            'PUT /health' => ['PUT', '/health'],
            'DELETE /health' => ['DELETE', '/health'],
            'HEAD /health' => ['HEAD', '/health'],
            'OPTIONS /api/products' => ['OPTIONS', '/api/products'],
            'lower case verb' => ['get', '/health'],

            // unknown paths
            'root' => ['GET', '/'],
            'api root' => ['GET', '/api'],
            'unknown api path' => ['GET', '/api/unknown'],
            'unknown path with POST' => ['POST', '/api/unknown'],
            'extra segment on item' => ['GET', '/api/products/1/extra'],
            'extra segment on health' => ['GET', '/health/live'],
            'wrong case' => ['GET', '/Health'],
            'singular resource' => ['GET', '/api/product'],
            'unknown with trailing slash' => ['GET', '/nothing/'],
            'unknown with query string' => ['GET', '/nothing?x=1'],
            'known path only inside the query string' => ['GET', '/nowhere?/api/products'],
        ];
    }

    #[Test]
    #[DataProvider('unmatched')]
    public function an_unmatched_request_is_a_404_and_no_controller_is_invoked(string $method, string $uri): void
    {
        $router = $this->routerWithRecordingControllers();

        [$status, $body] = $this->dispatch($router, $method, $uri);

        self::assertSame(404, $status);
        self::assertSame(self::NOT_FOUND, $body);
        self::assertSame([], $this->calls);
    }

    #[Test]
    #[DataProvider('unmatched')]
    public function an_unmatched_request_through_the_real_controllers_touches_nothing(string $method, string $uri): void
    {
        [$status, $body] = $this->dispatch($this->realRouter(), $method, $uri);

        self::assertSame(404, $status);
        self::assertSame(self::NOT_FOUND, $body);
        $this->assertDoublesUntouched();
    }

    #[Test]
    public function the_404_body_is_the_exact_json_error_document(): void
    {
        [, $body] = $this->dispatch($this->realRouter(), 'GET', '/api/unknown');

        self::assertSame(['error' => 'Not found'], json_decode($body, true));
        self::assertSame(self::NOT_FOUND, $body);
    }

    #[Test]
    public function an_unsupported_method_is_a_404_never_a_405(): void
    {
        [$status] = $this->dispatch($this->realRouter(), 'POST', '/api/products/1');

        self::assertSame(404, $status);
        self::assertNotSame(405, $status);
    }

    // ------------------------------------------------------------------
    // Wiring with the real controllers (observable effects on the shared doubles)
    // ------------------------------------------------------------------

    #[Test]
    public function the_constructor_builds_the_three_controllers(): void
    {
        $router = $this->realRouter();

        $types = [];
        foreach ((new ReflectionClass(Router::class))->getProperties() as $property) {
            $type = $property->getType();
            self::assertInstanceOf(ReflectionNamedType::class, $type);
            self::assertTrue($property->isPrivate(), "{$property->getName()} stays private");
            $types[$property->getName()] = $type->getName();
            self::assertInstanceOf($type->getName(), $property->getValue($router));
        }

        self::assertEqualsCanonicalizing(
            [HealthController::class, ProductController::class, OrderController::class],
            array_values($types)
        );
    }

    #[Test]
    public function the_health_controller_receives_all_three_collaborators(): void
    {
        $this->pdo->onSql('SELECT 1')->throws(new \PDOException('down'));
        $this->amqp->setConnected(false);

        [$status, $body] = $this->dispatch($this->realRouter(), 'GET', '/health');

        self::assertSame(200, $status);
        self::assertSame(
            '{"status":"degraded","services":{"mysql":"error","redis":"connected","rabbitmq":"error"}}',
            $body
        );
        self::assertSame([['ping', []]], $this->redis->calls());
    }

    #[Test]
    public function the_product_controller_receives_the_database_and_the_cache(): void
    {
        $this->redis->seed('products:5', '{"id":5,"from":"cache"}');

        [$status, $body] = $this->dispatch($this->realRouter(), 'GET', '/api/products/5');

        self::assertSame([200, '{"id":5,"from":"cache"}'], [$status, $body]);
        self::assertSame([['products:5']], $this->redis->callsTo('get'));
        self::assertSame([], $this->pdo->executed(), 'served from the injected cache, so the database stays idle');
    }

    #[Test]
    public function the_order_controller_receives_the_database(): void
    {
        $this->pdo->onSql('SELECT * FROM orders ORDER BY id DESC')->fetchesRows([['id' => 1, 'total' => '5.00']]);

        [$status, $body] = $this->dispatch($this->realRouter(), 'GET', '/api/orders');

        self::assertSame([200, '[{"id":1,"total":"5.00"}]'], [$status, $body]);
        self::assertSame(['SELECT * FROM orders ORDER BY id DESC'], array_column($this->pdo->executed(), 'sql'));
    }

    #[Test]
    public function the_order_controller_publishes_order_created_through_the_shared_queue(): void
    {
        $this->pdo->onSql('SELECT * FROM products WHERE id = ? FOR UPDATE')->fetchesRow(['id' => 1, 'name' => 'W', 'price' => '2.00', 'stock' => 5]);
        $this->pdo->onSql('INSERT INTO orders')->insertsId(11);
        $this->pdo->onSql('SELECT * FROM orders WHERE id = ?')->fetchesRow(['id' => 11, 'total' => '2.00']);

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/api/orders';
        [$status] = $this->capture(
            function (): void {
                $this->realRouter()->handleRequest();
            },
            ['items' => [['product_id' => 1, 'quantity' => 1]]]
        );

        self::assertSame(201, $status);
        self::assertCount(1, $this->amqp->published());
        self::assertSame('order_created', $this->amqp->published()[0]['queue']);
    }

    // ------------------------------------------------------------------
    // Construction
    // ------------------------------------------------------------------

    #[Test]
    public function constructing_a_router_performs_no_io(): void
    {
        $connection = $this->createMock(AMQPStreamConnection::class);
        $connection->expects(self::never())->method(self::anything());
        $queueBefore = QueueReset::current();

        $router = new Router($this->pdo, $this->redis, $connection);

        self::assertInstanceOf(Router::class, $router);
        self::assertSame([], $this->pdo->executed(), 'no SQL');
        self::assertSame([], $this->pdo->transactionEvents(), 'no transaction');
        self::assertSame([], $this->redis->calls(), 'no Redis command');
        self::assertSame(0, $this->amqp->channelsOpened(), 'no AMQP channel');
        self::assertSame([], $this->amqp->published(), 'no AMQP publish');
        self::assertSame($queueBefore, QueueReset::current(), 'the shared Queue connection is neither opened nor replaced');
        self::assertSame('', (string) ob_get_contents(), 'no output');
    }

    #[Test]
    public function constructing_a_router_does_not_need_request_globals(): void
    {
        unset($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);

        $router = $this->realRouter();

        self::assertInstanceOf(Router::class, $router);
        self::assertArrayNotHasKey('REQUEST_METHOD', $_SERVER);
    }

    #[Test]
    public function constructing_a_router_does_not_dispatch_anything(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/health';

        $this->realRouter();

        self::assertSame([], $this->pdo->executed());
        self::assertSame([], $this->redis->calls());
        self::assertSame('', (string) ob_get_contents());
    }

    #[Test]
    public function the_constructor_accepts_pdo_predis_client_and_amqp_stream_connection_in_that_order(): void
    {
        $constructor = new ReflectionMethod(Router::class, '__construct');
        $parameters = $constructor->getParameters();

        self::assertTrue($constructor->isPublic());
        self::assertCount(3, $parameters);
        self::assertSame(3, $constructor->getNumberOfRequiredParameters());

        foreach ([PDO::class, RedisClient::class, AMQPStreamConnection::class] as $position => $expectedClass) {
            $type = $parameters[$position]->getType();
            self::assertInstanceOf(ReflectionNamedType::class, $type, "parameter #{$position}");
            self::assertSame($expectedClass, $type->getName(), "parameter #{$position}");
            self::assertFalse($type->allowsNull(), "parameter #{$position} is not nullable");
            self::assertFalse($type->isBuiltin());
        }
        self::assertSame(['db', 'cache', 'queue'], array_map(static fn ($p): string => $p->getName(), $parameters));
    }

    #[Test]
    public function the_router_has_a_single_public_entry_point(): void
    {
        $public = array_map(
            static fn (ReflectionMethod $m): string => $m->getName(),
            (new ReflectionClass(Router::class))->getMethods(ReflectionMethod::IS_PUBLIC)
        );
        sort($public);

        self::assertSame(['__construct', 'handleRequest'], $public);
        $handle = new ReflectionMethod(Router::class, 'handleRequest');
        self::assertSame(0, $handle->getNumberOfParameters());
        self::assertSame('void', (string) $handle->getReturnType());
    }

    #[Test]
    public function subclasses_and_doubles_of_the_dependency_types_are_accepted(): void
    {
        /** @var MockObject&PDO $pdo */
        $pdo = $this->createMock(PDO::class);
        $redis = $this->createMock(RedisClient::class);
        $amqp = $this->createMock(AMQPStreamConnection::class);

        self::assertInstanceOf(Router::class, new Router($pdo, $redis, $amqp));
        self::assertInstanceOf(Router::class, new Router($this->pdo, $this->redis, $this->amqp->connection()));
    }

    // ------------------------------------------------------------------
    // Architecture guard: the Router only routes
    // ------------------------------------------------------------------

    private static function routerSourceWithoutComments(): string
    {
        $path = dirname(__DIR__) . '/app/Router.php';
        self::assertFileExists($path);

        $code = '';
        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $code .= $token[1];
            } else {
                $code .= $token;
            }
        }

        return $code;
    }

    /** @return array<string, array{0: string}> */
    public static function forbiddenPatterns(): array
    {
        return [
            'prepare call' => ['/\bprepare\s*\(/i'],
            'query call' => ['/->\s*query\s*\(/i'],
            'exec call' => ['/->\s*exec\s*\(/i'],
            'execute call' => ['/->\s*execute\s*\(/i'],
            'fetch call' => ['/->\s*fetch(All|Column)?\s*\(/i'],
            'SELECT statement' => ['/\bSELECT\b/'],
            'INSERT statement' => ['/\bINSERT\s+INTO\b/i'],
            'UPDATE statement' => ['/\bUPDATE\s+\w+\s+SET\b/i'],
            'DELETE statement' => ['/\bDELETE\s+FROM\b/i'],
            'transaction control' => ['/\b(beginTransaction|commit|rollBack|lastInsertId)\b/'],
            'cache setex' => ['/\bsetex\b/i'],
            'cache del' => ['/->\s*del\s*\(/i'],
            'cache ping' => ['/->\s*ping\s*\(/i'],
            'cache key literal' => ['/products:/'],
            'Queue facade' => ['/\bQueue\s*::/'],
            'App Queue import' => ['/use\s+App\\\\Queue\b/'],
            'App Cache import' => ['/use\s+App\\\\Cache\b/'],
            'App Database import' => ['/use\s+App\\\\Database\b/'],
            'AMQP channel use' => ['/basic_publish|queue_declare|->\s*channel\s*\(|isConnected/'],
            'AMQP message class' => ['/\bAMQPMessage\b/'],
            'request body reading' => ['/php:\/\/input|json_decode|file_get_contents/'],
            'JSON building of business data' => ['/json_encode\s*\(\s*\$(?!status)/'],
            'raw SQL placeholders' => ['/\bWHERE\b|\bFROM\s+\w+\b|\bORDER\s+BY\b/'],
        ];
    }

    #[Test]
    #[DataProvider('forbiddenPatterns')]
    public function the_router_source_contains_no_business_logic(string $pattern): void
    {
        self::assertSame(
            0,
            preg_match($pattern, self::routerSourceWithoutComments()),
            "Router.php must only route, but matched {$pattern}"
        );
    }

    #[Test]
    public function the_router_source_delegates_to_exactly_the_expected_controller_methods(): void
    {
        $code = self::routerSourceWithoutComments();

        preg_match_all('/\$this->(health|products|orders)->(\w+)\(/', $code, $matches, PREG_SET_ORDER);
        $delegations = array_map(static fn (array $m): string => "{$m[1]}.{$m[2]}", $matches);
        sort($delegations);

        self::assertSame(
            [
                'health.health',
                'orders.create',
                'orders.get',
                'orders.list',
                'products.create',
                'products.delete',
                'products.get',
                'products.list',
                'products.update',
            ],
            $delegations
        );
    }

    #[Test]
    public function the_router_only_depends_on_controllers_and_the_constructor_collaborator_types(): void
    {
        preg_match_all('/^use\s+([^;]+);/m', self::routerSourceWithoutComments(), $matches);
        $imports = $matches[1];
        sort($imports);

        $allowed = [
            'App\Controllers\HealthController',
            'App\Controllers\OrderController',
            'App\Controllers\ProductController',
            'PDO',
            'PhpAmqpLib\Connection\AMQPStreamConnection',
            'Predis\Client as RedisClient',
        ];
        sort($allowed);

        self::assertSame($allowed, $imports);
    }

    #[Test]
    public function the_router_only_stores_controllers(): void
    {
        $properties = (new ReflectionClass(Router::class))->getProperties();
        $types = array_map(static fn (ReflectionProperty $p): string => (string) $p->getType(), $properties);
        sort($types);

        self::assertSame(
            [HealthController::class, OrderController::class, ProductController::class],
            $types
        );
    }
}
