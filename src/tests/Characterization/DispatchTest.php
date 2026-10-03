<?php

namespace Tests\Characterization;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AmqpSpy;
use Tests\Support\FakePredis;
use Tests\Support\HttpTestCase;
use Tests\Support\QueueReset;
use Tests\Support\ScriptedPdo;

/**
 * Characterizes which handler each method/path pair reaches in the original Router.
 *
 * Handlers are identified by observable evidence only (SQL executed, cache calls,
 * transaction events, output), never by inspecting Router internals.
 *
 * Quirks pinned here on purpose:
 *  - the path is run through parse_url() (query string dropped) and rtrim($uri, '/') (ALL trailing slashes dropped)
 *  - matching is case sensitive for both path and verb
 *  - the id regex runs before the verb check in every id case, so an id path with an unsupported verb simply
 *    falls through to the default 404 (it never answers 405)
 *  - ids are cast with (int): leading zeros vanish and absurdly long digit strings saturate at PHP_INT_MAX
 */
final class DispatchTest extends HttpTestCase
{
    private const NOT_FOUND = '{"error":"Not found"}';

    // ------------------------------------------------------------------
    // Positive dispatch
    // ------------------------------------------------------------------

    #[Test]
    #[DataProvider('routes')]
    public function a_request_reaches_the_expected_handler(string $method, string $uri, string $handler, ?int $id): void
    {
        $this->assertReaches($method, $uri, $handler, $id);
    }

    /** @return array<string, array{0: string, 1: string, 2: string, 3: int|null}> */
    public static function routes(): array
    {
        return [
            'health' => ['GET', '/health', 'health', null],
            'health trailing slash' => ['GET', '/health/', 'health', null],
            'health query string' => ['GET', '/health?verbose=1', 'health', null],
            'health many trailing slashes' => ['GET', '/health///', 'health', null],

            'list products' => ['GET', '/api/products', 'list_products', null],
            'list products trailing slash' => ['GET', '/api/products/', 'list_products', null],
            'list products double trailing slash' => ['GET', '/api/products//', 'list_products', null],
            'list products query string' => ['GET', '/api/products?x=1', 'list_products', null],
            'list products slash and query string' => ['GET', '/api/products/?x=1&y=2', 'list_products', null],
            'list products empty query string' => ['GET', '/api/products?', 'list_products', null],
            'list products fragment' => ['GET', '/api/products#top', 'list_products', null],

            'get product' => ['GET', '/api/products/3', 'get_product', 3],
            'get product trailing slash' => ['GET', '/api/products/3/', 'get_product', 3],
            'get product query string' => ['GET', '/api/products/3?x=1', 'get_product', 3],
            'get product multi digit' => ['GET', '/api/products/1234567', 'get_product', 1234567],
            'get product id zero' => ['GET', '/api/products/0', 'get_product', 0],
            'get product leading zeros' => ['GET', '/api/products/007', 'get_product', 7],
            'get product all zeros' => ['GET', '/api/products/000', 'get_product', 0],
            'get product id beyond int range saturates' => ['GET', '/api/products/99999999999999999999', 'get_product', PHP_INT_MAX],

            'create product' => ['POST', '/api/products', 'create_product', 42],
            'create product trailing slash' => ['POST', '/api/products/', 'create_product', 42],
            'create product query string' => ['POST', '/api/products?dry=1', 'create_product', 42],

            'update product' => ['PUT', '/api/products/5', 'update_product', 5],
            'update product trailing slash' => ['PUT', '/api/products/5/', 'update_product', 5],
            'update product query string' => ['PUT', '/api/products/5?x=1', 'update_product', 5],
            'update product multi digit' => ['PUT', '/api/products/512', 'update_product', 512],
            'update product leading zeros' => ['PUT', '/api/products/0005', 'update_product', 5],

            'delete product' => ['DELETE', '/api/products/6', 'delete_product', 6],
            'delete product trailing slash' => ['DELETE', '/api/products/6/', 'delete_product', 6],
            'delete product query string' => ['DELETE', '/api/products/6?force=1', 'delete_product', 6],
            'delete product multi digit' => ['DELETE', '/api/products/600', 'delete_product', 600],
            'delete product id zero' => ['DELETE', '/api/products/0', 'delete_product', 0],

            'list orders' => ['GET', '/api/orders', 'list_orders', null],
            'list orders trailing slash' => ['GET', '/api/orders/', 'list_orders', null],
            'list orders query string' => ['GET', '/api/orders?limit=5', 'list_orders', null],

            'get order' => ['GET', '/api/orders/5', 'get_order', 5],
            'get order trailing slash' => ['GET', '/api/orders/5/', 'get_order', 5],
            'get order query string' => ['GET', '/api/orders/5?x=1', 'get_order', 5],
            'get order multi digit' => ['GET', '/api/orders/98765', 'get_order', 98765],
            'get order id zero' => ['GET', '/api/orders/0', 'get_order', 0],
            'get order leading zeros' => ['GET', '/api/orders/0012', 'get_order', 12],

            'create order' => ['POST', '/api/orders', 'create_order', null],
            'create order trailing slash' => ['POST', '/api/orders/', 'create_order', null],
            'create order query string' => ['POST', '/api/orders?x=1', 'create_order', null],
        ];
    }

    #[Test]
    public function the_same_id_path_reaches_a_different_handler_per_verb(): void
    {
        // The id regex is evaluated before the verb in each case, but a mismatching verb
        // must still fall through to the later id cases rather than short-circuit.
        $this->assertReaches('GET', '/api/products/9', 'get_product', 9);
        $this->assertReaches('PUT', '/api/products/9', 'update_product', 9);
        $this->assertReaches('DELETE', '/api/products/9', 'delete_product', 9);
    }

    #[Test]
    public function get_put_and_delete_on_an_id_route_each_run_only_their_own_statements(): void
    {
        $this->pdo->onSql('SELECT id FROM products')->fetchesRow(['id' => 9]);
        $this->pdo->onSql('DELETE FROM products')->affects(1);

        $this->request('GET', '/api/products/9');
        $afterGet = array_column($this->pdo->executed(), 'sql');
        $this->request('PUT', '/api/products/9', ['name' => 'N']);
        $afterPut = array_column($this->pdo->executed(), 'sql');
        $this->request('DELETE', '/api/products/9');
        $afterDelete = array_column($this->pdo->executed(), 'sql');

        self::assertSame(['SELECT * FROM products WHERE id = ?'], $afterGet);
        self::assertSame(
            [
                'SELECT id FROM products WHERE id = ?',
                'UPDATE products SET name = ? WHERE id = ?',
                'SELECT * FROM products WHERE id = ?',
            ],
            array_slice($afterPut, count($afterGet))
        );
        self::assertSame(['DELETE FROM products WHERE id = ?'], array_slice($afterDelete, count($afterPut)));
    }

    #[Test]
    public function the_product_and_order_id_routes_are_distinct(): void
    {
        $this->request('GET', '/api/orders/4');
        $this->request('GET', '/api/products/4');

        self::assertSame(
            ['SELECT * FROM orders WHERE id = ?', 'SELECT * FROM products WHERE id = ?'],
            array_column($this->pdo->executed(), 'sql')
        );
    }

    #[Test]
    public function an_id_is_bound_as_an_int_not_a_string(): void
    {
        $this->request('GET', '/api/products/007');
        $this->request('GET', '/api/orders/007');

        self::assertSame([7], $this->pdo->executed()[0]['params']);
        self::assertSame([7], $this->pdo->executed()[1]['params']);
        self::assertSame(['products:7'], $this->redis->callsTo('get')[0]);
        self::assertTrue($this->pdo->executed()[0]['params'][0] === 7);
        self::assertTrue($this->pdo->executed()[1]['params'][0] === 7);
    }

    // ------------------------------------------------------------------
    // 404 fall-through
    // ------------------------------------------------------------------

    #[Test]
    #[DataProvider('unmatchedRequests')]
    public function an_unmatched_request_is_a_404_and_touches_nothing(string $method, string $uri): void
    {
        [$status, $body] = $this->request($method, $uri, ['name' => 'x', 'price' => 1, 'items' => [['product_id' => 1, 'quantity' => 1]]]);

        self::assertSame(404, $status);
        self::assertSame(self::NOT_FOUND, $body);
        $this->assertNothingTouched();
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function unmatchedRequests(): array
    {
        return [
            // non-numeric / non-digit ids
            'product id letters' => ['GET', '/api/products/abc'],
            'product id digit then letter' => ['GET', '/api/products/1x'],
            'product id letter then digit' => ['GET', '/api/products/x1'],
            'product id negative' => ['GET', '/api/products/-1'],
            'product id signed positive' => ['GET', '/api/products/+1'],
            'product id decimal' => ['GET', '/api/products/1.5'],
            'product id hex' => ['GET', '/api/products/0x1A'],
            'product id exponent' => ['GET', '/api/products/1e3'],
            'product id percent encoded digit' => ['GET', '/api/products/%31'],
            'product id with space' => ['GET', '/api/products/1%202'],
            'product id non-ascii digit' => ['GET', "/api/products/\u{0663}"],
            'put product id letters' => ['PUT', '/api/products/abc'],
            'delete product id negative' => ['DELETE', '/api/products/-1'],
            'delete product id letters' => ['DELETE', '/api/products/abc'],
            'order id letters' => ['GET', '/api/orders/abc'],
            'order id negative' => ['GET', '/api/orders/-5'],
            'order id digit then letter' => ['GET', '/api/orders/5a'],

            // unsupported verbs on known paths
            'patch products' => ['PATCH', '/api/products'],
            'put products collection' => ['PUT', '/api/products'],
            'delete products collection' => ['DELETE', '/api/products'],
            'post product item' => ['POST', '/api/products/1'],
            'patch product item' => ['PATCH', '/api/products/1'],
            'head products' => ['HEAD', '/api/products'],
            'options products' => ['OPTIONS', '/api/products'],
            'put orders collection' => ['PUT', '/api/orders'],
            'delete orders collection' => ['DELETE', '/api/orders'],
            'delete order item' => ['DELETE', '/api/orders/1'],
            'put order item' => ['PUT', '/api/orders/1'],
            'post order item' => ['POST', '/api/orders/1'],
            'patch order item' => ['PATCH', '/api/orders/1'],
            'post health' => ['POST', '/health'],
            'delete health' => ['DELETE', '/health'],
            'put health' => ['PUT', '/health'],
            'head health' => ['HEAD', '/health'],

            // verb is case sensitive and exact
            'lower case get' => ['get', '/health'],
            'lower case get products' => ['get', '/api/products'],
            'mixed case Get' => ['Get', '/api/products/1'],
            'lower case post' => ['post', '/api/orders'],
            'lower case put' => ['put', '/api/products/1'],
            'lower case delete' => ['delete', '/api/products/1'],
            'empty verb' => ['', '/health'],
            'verb with trailing space' => ['GET ', '/health'],

            // unknown paths
            'root' => ['GET', '/'],
            'api root' => ['GET', '/api'],
            'api root trailing slash' => ['GET', '/api/'],
            'unknown api path' => ['GET', '/api/unknown'],
            'unknown api path post' => ['POST', '/api/unknown'],
            'product with extra segment' => ['GET', '/api/products/1/extra'],
            'order with extra segment' => ['GET', '/api/orders/1/items'],
            'put product with extra segment' => ['PUT', '/api/products/1/extra'],
            'health with extra segment' => ['GET', '/health/live'],
            'capitalised health' => ['GET', '/Health'],
            'upper case health' => ['GET', '/HEALTH'],
            'capitalised api' => ['GET', '/API/products'],
            'capitalised products' => ['GET', '/api/Products'],
            'singular product' => ['GET', '/api/product'],
            'singular order' => ['GET', '/api/order/1'],
            'no leading slash' => ['GET', 'health'],
            'doubled leading slash in api' => ['GET', '/api//products'],
            'api prefix missing' => ['GET', '/products'],
            'front controller prefix' => ['GET', '/index.php/health'],
            'versioned prefix' => ['GET', '/v1/api/products'],
            'products with dot suffix' => ['GET', '/api/products.json'],
            'orders with dot suffix' => ['GET', '/api/orders.json'],
            'health with dot suffix' => ['GET', '/health.php'],
        ];
    }

    #[Test]
    public function a_404_sets_only_the_status_and_the_json_error_body(): void
    {
        [$status, $body] = $this->request('GET', '/api/unknown');

        self::assertSame(404, $status);
        self::assertSame(['error' => 'Not found'], json_decode($body, true));
        self::assertSame(self::NOT_FOUND, $body);
    }

    #[Test]
    public function an_unsupported_verb_on_an_existing_path_is_404_not_405(): void
    {
        [$status, $body] = $this->request('POST', '/api/products/1');

        self::assertSame(404, $status);
        self::assertSame(self::NOT_FOUND, $body);
        $this->assertNothingTouched();
    }

    #[Test]
    public function the_default_404_is_distinct_from_the_handler_level_not_found_errors(): void
    {
        [$unknownStatus, $unknownBody] = $this->request('GET', '/api/products/abc');
        [$missingStatus, $missingBody] = $this->request('GET', '/api/products/123');

        self::assertSame([404, self::NOT_FOUND], [$unknownStatus, $unknownBody]);
        self::assertSame([404, '{"error":"Product not found"}'], [$missingStatus, $missingBody]);
    }

    // ------------------------------------------------------------------
    // Path normalisation edge cases
    // ------------------------------------------------------------------

    #[Test]
    public function every_trailing_slash_is_stripped_not_just_one(): void
    {
        $this->assertReaches('GET', '/api/orders///', 'list_orders', null);
        $this->assertReaches('GET', '/api/products/8//', 'get_product', 8);
    }

    #[Test]
    public function the_query_string_is_never_part_of_the_match(): void
    {
        [$status, $body] = $this->request('GET', '/api/unknown?next=/api/products');

        self::assertSame([404, self::NOT_FOUND], [$status, $body]);
        $this->assertNothingTouched();
    }

    #[Test]
    public function a_matching_path_inside_a_query_string_does_not_route(): void
    {
        [$status, $body] = $this->request('GET', '/nowhere?/health');

        self::assertSame([404, self::NOT_FOUND], [$status, $body]);
        $this->assertNothingTouched();
    }

    #[Test]
    public function a_host_prefixed_request_uri_is_routed_by_its_path(): void
    {
        $this->assertReaches('GET', 'http://example.test/api/products/2?x=1', 'get_product', 2);
    }

    // ------------------------------------------------------------------
    // Fixtures and assertions
    // ------------------------------------------------------------------

    /** Replaces the fakes so one test can drive several requests against clean doubles. */
    private function freshFakes(): void
    {
        $this->pdo = new ScriptedPdo();
        $this->redis = new FakePredis();
        $this->amqp = new AmqpSpy(
            $this->createMock(AMQPStreamConnection::class),
            $this->createMock(AMQPChannel::class)
        );
        QueueReset::install($this->amqp->connection());
    }

    private function assertNothingTouched(): void
    {
        self::assertSame([], $this->pdo->executed(), 'no SQL on a 404');
        self::assertSame([], $this->pdo->transactionEvents(), 'no transaction on a 404');
        self::assertSame([], $this->redis->calls(), 'no cache call on a 404');
        self::assertSame([], $this->amqp->published(), 'nothing published on a 404');
        self::assertSame(0, $this->amqp->channelsOpened(), 'no channel opened on a 404');
    }

    /**
     * Arranges the fakes for $handler, performs the request and asserts the evidence that
     * identifies $handler. $id is the id the handler is expected to bind (insert id for create_product).
     */
    private function assertReaches(string $method, string $uri, string $handler, ?int $id): void
    {
        $this->freshFakes();
        $body = null;
        $product = ['id' => $id, 'name' => 'Widget', 'price' => '9.50', 'stock' => 3];

        switch ($handler) {
            case 'get_product':
                $this->pdo->onSql('SELECT * FROM products WHERE id = ?')->fetchesRow($product);
                break;
            case 'create_product':
                $body = ['name' => 'Widget', 'price' => 9.5, 'stock' => 3];
                $this->pdo->onSql('INSERT INTO products')->insertsId($id);
                $this->pdo->onSql('SELECT * FROM products WHERE id = ?')->fetchesRow($product);
                break;
            case 'update_product':
                $body = ['name' => 'Renamed'];
                $this->pdo->onSql('SELECT id FROM products WHERE id = ?')->fetchesRow(['id' => $id]);
                $this->pdo->onSql('SELECT * FROM products WHERE id = ?')->fetchesRow($product);
                break;
            case 'delete_product':
                $this->pdo->onSql('DELETE FROM products')->affects(1);
                break;
            case 'get_order':
                $this->pdo->onSql('SELECT * FROM orders WHERE id = ?')->fetchesRow(['id' => $id, 'total' => '9.50']);
                break;
            case 'create_order':
                $body = ['items' => [['product_id' => 7, 'quantity' => 1]]];
                break;
        }

        [$status, $output] = $this->request($method, $uri, $body);
        $sql = array_column($this->pdo->executed(), 'sql');

        switch ($handler) {
            case 'health':
                self::assertSame(200, $status);
                self::assertStringStartsWith('{"status":"ok","services":{"mysql":', $output);
                self::assertSame(['SELECT 1'], $sql);
                self::assertSame([['ping', []]], $this->redis->calls());
                break;

            case 'list_products':
                self::assertSame([200, '[]'], [$status, $output]);
                self::assertSame(['SELECT * FROM products ORDER BY id'], $sql);
                self::assertSame([['products:all']], $this->redis->callsTo('get'));
                self::assertSame([['products:all', 60, '[]']], $this->redis->callsTo('setex'));
                break;

            case 'get_product':
                self::assertSame([200, json_encode($product)], [$status, $output]);
                self::assertSame(['SELECT * FROM products WHERE id = ?'], $sql);
                self::assertSame([[$id]], $this->pdo->paramsOf('SELECT * FROM products WHERE id = ?'));
                self::assertSame([["products:{$id}"]], $this->redis->callsTo('get'));
                self::assertSame([["products:{$id}", 60, json_encode($product)]], $this->redis->callsTo('setex'));
                break;

            case 'create_product':
                self::assertSame([201, json_encode($product)], [$status, $output]);
                self::assertSame(
                    ['INSERT INTO products (name, description, price, stock) VALUES (?, ?, ?, ?)', 'SELECT * FROM products WHERE id = ?'],
                    $sql
                );
                self::assertSame([['Widget', '', 9.5, 3]], $this->pdo->paramsOf('INSERT INTO products'));
                self::assertSame([[42]], $this->pdo->paramsOf('SELECT * FROM products WHERE id = ?'));
                self::assertSame([['products:all']], $this->redis->callsTo('del'));
                break;

            case 'update_product':
                self::assertSame([200, json_encode($product)], [$status, $output]);
                self::assertSame(
                    ['SELECT id FROM products WHERE id = ?', 'UPDATE products SET name = ? WHERE id = ?', 'SELECT * FROM products WHERE id = ?'],
                    $sql
                );
                self::assertSame([['Renamed', $id]], $this->pdo->paramsOf('UPDATE products SET'));
                self::assertSame([["products:{$id}"], ['products:all']], $this->redis->callsTo('del'));
                break;

            case 'delete_product':
                self::assertSame([204, ''], [$status, $output]);
                self::assertSame(['DELETE FROM products WHERE id = ?'], $sql);
                self::assertSame([[$id]], $this->pdo->paramsOf('DELETE FROM products'));
                self::assertSame([["products:{$id}"], ['products:all']], $this->redis->callsTo('del'));
                break;

            case 'list_orders':
                self::assertSame([200, '[]'], [$status, $output]);
                self::assertSame(['SELECT * FROM orders ORDER BY id DESC'], $sql);
                self::assertSame([], $this->redis->calls());
                break;

            case 'get_order':
                self::assertSame(200, $status);
                self::assertSame(['id' => $id, 'total' => '9.50', 'items' => []], json_decode($output, true));
                self::assertCount(2, $sql);
                self::assertSame('SELECT * FROM orders WHERE id = ?', $sql[0]);
                self::assertStringContainsString('FROM order_items oi', $sql[1]);
                self::assertSame([[$id], [$id]], array_column($this->pdo->executed(), 'params'));
                self::assertSame([], $this->redis->calls());
                break;

            case 'create_order':
                // Product 7 is unknown to the fake, so the handler rolls back with a 400; that
                // is still unambiguous evidence that the order-creation transaction was entered.
                self::assertSame([400, '{"error":"Product 7 not found"}'], [$status, $output]);
                self::assertSame(['begin', 'rollback'], $this->pdo->transactionEvents());
                self::assertSame('INSERT INTO orders (total) VALUES (0)', $sql[0]);
                self::assertSame('SELECT * FROM products WHERE id = ? FOR UPDATE', $sql[1]);
                self::assertSame([[7]], $this->pdo->paramsOf('FOR UPDATE'));
                break;

            default:
                self::fail("Unknown handler '{$handler}'");
        }
    }
}
