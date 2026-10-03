<?php

namespace Tests\Characterization;

use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\HttpTestCase;
use Tests\Support\ScriptedPdo;

/**
 * Characterization of the product endpoints, driven only through the public
 * contract: `new Router($pdo, $redis, $amqp)->handleRequest()`.
 *
 * Quirks are pinned as they are today (no validation on update values, 404 wins
 * over 400 on update, strict '23000' string comparison on delete, ...).
 */
final class ProductEndpointsTest extends HttpTestCase
{
    private const SELECT_ALL = 'SELECT * FROM products ORDER BY id';
    private const SELECT_ONE = 'SELECT * FROM products WHERE id = ?';
    private const SELECT_ID = 'SELECT id FROM products WHERE id = ?';
    private const INSERT = 'INSERT INTO products (name, description, price, stock) VALUES (?, ?, ?, ?)';
    private const DELETE = 'DELETE FROM products WHERE id = ?';

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function row(int $id = 7, array $overrides = []): array
    {
        return array_merge([
            'id' => $id,
            'name' => 'Widget',
            'description' => 'A fine widget',
            'price' => '19.99',
            'stock' => 5,
        ], $overrides);
    }

    /** @param array<string, mixed> $row */
    private function givenProductInDb(array $row): void
    {
        $this->pdo->onSql(self::SELECT_ONE)->withParams([$row['id']])->fetchesRow($row);
        $this->pdo->onSql(self::SELECT_ID)->withParams([$row['id']])->fetchesRow(['id' => $row['id']]);
    }

    /** @param array<string, mixed> $row */
    private function givenInsertCreates(array $row): void
    {
        $this->pdo->onSql('INSERT INTO products')->insertsId($row['id']);
        $this->pdo->onSql(self::SELECT_ONE)->withParams([$row['id']])->fetchesRow($row);
    }

    /** @return list<array{sql: string, params: list<mixed>}> */
    private function updateExecutions(): array
    {
        return $this->pdo->executions('UPDATE products');
    }

    private function assertNoDbWrite(): void
    {
        self::assertFalse($this->pdo->wasExecuted('INSERT'), 'unexpected INSERT');
        self::assertFalse($this->pdo->wasExecuted('UPDATE'), 'unexpected UPDATE');
        self::assertFalse($this->pdo->wasExecuted('DELETE'), 'unexpected DELETE');
    }

    // ------------------------------------------------------------------
    // GET /api/products
    // ------------------------------------------------------------------

    #[Test]
    public function list_on_cache_miss_queries_db_echoes_json_and_caches_for_60_seconds(): void
    {
        $rows = [self::row(1), self::row(2, ['name' => 'Gadget'])];
        $this->pdo->onSql(self::SELECT_ALL)->fetchesRows($rows);

        [$status, $body] = $this->request('GET', '/api/products');

        $json = json_encode($rows);
        self::assertSame(200, $status);
        self::assertSame($json, $body);
        self::assertSame([[self::SELECT_ALL, []]], array_map(
            static fn (array $e): array => [$e['sql'], $e['params']],
            $this->pdo->executed()
        ));
        self::assertSame([
            ['get', ['products:all']],
            ['setex', ['products:all', 60, $json]],
        ], $this->redis->calls());
        self::assertSame($json, $this->redis->stored('products:all'));
        self::assertSame(60, $this->redis->ttl('products:all'));
    }

    #[Test]
    public function list_with_empty_table_returns_and_caches_an_empty_json_array(): void
    {
        $this->pdo->onSql(self::SELECT_ALL)->fetchesRows([]);

        [$status, $body] = $this->request('GET', '/api/products');

        self::assertSame(200, $status);
        self::assertSame('[]', $body);
        self::assertSame([['products:all', 60, '[]']], $this->redis->callsTo('setex'));
    }

    #[Test]
    public function list_on_cache_hit_echoes_the_cached_string_verbatim_without_touching_the_db(): void
    {
        $cached = 'cached-payload-that-is-not-even-json';
        $this->redis->seed('products:all', $cached);

        [$status, $body] = $this->request('GET', '/api/products');

        self::assertSame(200, $status);
        self::assertSame($cached, $body);
        self::assertSame([], $this->pdo->executed());
        self::assertSame([['get', ['products:all']]], $this->redis->calls());
    }

    #[Test]
    public function list_on_cache_hit_with_a_valid_empty_array_is_still_a_hit(): void
    {
        $this->redis->seed('products:all', '[]');
        $this->pdo->onSql(self::SELECT_ALL)->fetchesRows([self::row(1)]);

        [, $body] = $this->request('GET', '/api/products');

        self::assertSame('[]', $body);
        self::assertSame([], $this->pdo->executed());
    }

    /** @return array<string, array{string}> */
    public static function falsyCacheValues(): array
    {
        return [
            'empty string' => [''],
            'string zero' => ['0'],
        ];
    }

    #[Test]
    #[DataProvider('falsyCacheValues')]
    public function list_treats_a_falsy_cached_value_as_a_miss(string $cached): void
    {
        $rows = [self::row(1)];
        $this->pdo->onSql(self::SELECT_ALL)->fetchesRows($rows);
        $this->redis->seed('products:all', $cached);

        [$status, $body] = $this->request('GET', '/api/products');

        self::assertSame(200, $status);
        self::assertSame(json_encode($rows), $body);
        self::assertTrue($this->pdo->wasExecuted(self::SELECT_ALL));
        self::assertSame(json_encode($rows), $this->redis->stored('products:all'));
    }

    #[Test]
    public function list_ignores_a_trailing_slash_and_query_string(): void
    {
        $this->pdo->onSql(self::SELECT_ALL)->fetchesRows([self::row(1)]);

        [$status, $body] = $this->request('GET', '/api/products/?page=2');

        self::assertSame(200, $status);
        self::assertSame(json_encode([self::row(1)]), $body);
    }

    #[Test]
    public function list_lets_a_redis_failure_propagate(): void
    {
        $this->redis->failOn('get', new RuntimeException('redis down'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('redis down');

        $this->request('GET', '/api/products');
    }

    #[Test]
    public function list_escapes_unicode_and_slashes_in_the_json_output(): void
    {
        $this->pdo->onSql(self::SELECT_ALL)->fetchesRows([self::row(1, ['name' => 'Café/Bar'])]);

        [, $body] = $this->request('GET', '/api/products');

        self::assertStringContainsString('"name":"Caf\u00e9\/Bar"', $body);
    }

    // ------------------------------------------------------------------
    // GET /api/products/{id}
    // ------------------------------------------------------------------

    #[Test]
    public function get_on_cache_hit_echoes_cached_string_without_touching_the_db(): void
    {
        $this->redis->seed('products:7', '{"id":7,"cached":true}');

        [$status, $body] = $this->request('GET', '/api/products/7');

        self::assertSame(200, $status);
        self::assertSame('{"id":7,"cached":true}', $body);
        self::assertSame([], $this->pdo->executed());
        self::assertSame([['get', ['products:7']]], $this->redis->calls());
    }

    #[Test]
    public function get_on_cache_miss_loads_from_db_with_int_id_and_caches_for_60_seconds(): void
    {
        $row = self::row(7);
        $this->givenProductInDb($row);

        [$status, $body] = $this->request('GET', '/api/products/7');

        $json = json_encode($row);
        self::assertSame(200, $status);
        self::assertSame($json, $body);
        self::assertSame([[7]], $this->pdo->paramsOf(self::SELECT_ONE));
        self::assertSame(
            [self::SELECT_ONE],
            array_column($this->pdo->executed(), 'sql')
        );
        self::assertSame([
            ['get', ['products:7']],
            ['setex', ['products:7', 60, $json]],
        ], $this->redis->calls());
        self::assertSame(60, $this->redis->ttl('products:7'));
        self::assertNull($this->redis->stored('products:all'));
    }

    #[Test]
    public function get_of_unknown_product_is_404_and_caches_nothing(): void
    {
        $this->pdo->onSql(self::SELECT_ONE)->fetchesRow(false);

        [$status, $body] = $this->request('GET', '/api/products/99');

        self::assertSame(404, $status);
        self::assertSame('{"error":"Product not found"}', $body);
        self::assertSame([[99]], $this->pdo->paramsOf(self::SELECT_ONE));
        self::assertSame([], $this->redis->callsTo('setex'));
        self::assertNull($this->redis->stored('products:99'));
    }

    #[Test]
    #[DataProvider('falsyCacheValues')]
    public function get_treats_a_falsy_cached_value_as_a_miss(string $cached): void
    {
        $row = self::row(7);
        $this->givenProductInDb($row);
        $this->redis->seed('products:7', $cached);

        [$status, $body] = $this->request('GET', '/api/products/7');

        self::assertSame(200, $status);
        self::assertSame(json_encode($row), $body);
        self::assertTrue($this->pdo->wasExecuted(self::SELECT_ONE));
    }

    /** @return array<string, array{string, int}> */
    public static function idUris(): array
    {
        return [
            'plain' => ['/api/products/7', 7],
            'trailing slash' => ['/api/products/7/', 7],
            'query string' => ['/api/products/7?foo=bar', 7],
            'leading zeros' => ['/api/products/007', 7],
            'large id' => ['/api/products/123456', 123456],
        ];
    }

    #[Test]
    #[DataProvider('idUris')]
    public function get_binds_the_url_id_as_an_int(string $uri, int $expectedId): void
    {
        $this->pdo->onSql(self::SELECT_ONE)->fetchesRow(self::row($expectedId));

        [$status] = $this->request('GET', $uri);

        self::assertSame(200, $status);
        self::assertSame([[$expectedId]], $this->pdo->paramsOf(self::SELECT_ONE));
        self::assertSame([["products:{$expectedId}"]], $this->redis->callsTo('get'));
    }

    /** @return array<string, array{string, string}> */
    public static function unroutedProductRequests(): array
    {
        return [
            'non-numeric id' => ['GET', '/api/products/abc'],
            'negative id' => ['GET', '/api/products/-1'],
            'sub-path' => ['GET', '/api/products/7/extra'],
            'unsupported method on item' => ['PATCH', '/api/products/7'],
            'unsupported method on collection' => ['DELETE', '/api/products'],
            'put on collection' => ['PUT', '/api/products'],
            'post on item' => ['POST', '/api/products/7'],
            'lowercase method' => ['get', '/api/products'],
        ];
    }

    #[Test]
    #[DataProvider('unroutedProductRequests')]
    public function product_requests_that_match_no_route_get_the_generic_404(string $method, string $uri): void
    {
        [$status, $body] = $this->request($method, $uri);

        self::assertSame(404, $status);
        self::assertSame('{"error":"Not found"}', $body);
        self::assertSame([], $this->pdo->executed());
        self::assertSame([], $this->redis->calls());
    }

    // ------------------------------------------------------------------
    // POST /api/products
    // ------------------------------------------------------------------

    #[Test]
    public function create_inserts_invalidates_list_cache_and_responds_201_with_the_stored_product(): void
    {
        $row = self::row(42, ['name' => 'Gizmo', 'description' => 'Shiny', 'price' => '9.50', 'stock' => 3]);
        $this->givenInsertCreates($row);

        [$status, $body] = $this->request('POST', '/api/products', [
            'name' => 'Gizmo',
            'description' => 'Shiny',
            'price' => 9.5,
            'stock' => 3,
        ]);

        $json = json_encode($row);
        self::assertSame(201, $status);
        self::assertSame($json, $body);

        $inserts = $this->pdo->executions('INSERT INTO products');
        self::assertCount(1, $inserts);
        self::assertSame(self::INSERT, $inserts[0]['sql']);
        self::assertSame(['Gizmo', 'Shiny', 9.5, 3], $inserts[0]['params']);

        self::assertSame([[42]], $this->pdo->paramsOf(self::SELECT_ONE));
        self::assertSame([
            ['del', ['products:all']],
            ['get', ['products:42']],
            ['setex', ['products:42', 60, $json]],
        ], $this->redis->calls());
    }

    #[Test]
    public function create_applies_defaults_for_description_and_stock(): void
    {
        $row = self::row(5, ['name' => 'Bare', 'description' => '', 'price' => '1.00', 'stock' => 0]);
        $this->givenInsertCreates($row);

        [$status, $body] = $this->request('POST', '/api/products', ['name' => 'Bare', 'price' => 1]);

        self::assertSame(201, $status);
        self::assertSame(json_encode($row), $body);
        self::assertSame([['Bare', '', 1.0, 0]], $this->pdo->paramsOf('INSERT INTO products'));
    }

    /** @return array<string, array{array<string, mixed>, list<mixed>}> */
    public static function createCasts(): array
    {
        return [
            'string numerics' => [['name' => 'X', 'price' => '19.99', 'stock' => '5'], ['X', '', 19.99, 5]],
            'int price becomes float' => [['name' => 'X', 'price' => 10], ['X', '', 10.0, 0]],
            'exponent price string' => [['name' => 'X', 'price' => '1e2'], ['X', '', 100.0, 0]],
            'tiny positive price' => [['name' => 'X', 'price' => '0.001'], ['X', '', 0.001, 0]],
            'price with trailing junk' => [['name' => 'X', 'price' => '12abc'], ['X', '', 12.0, 0]],
            'boolean true price' => [['name' => 'X', 'price' => true], ['X', '', 1.0, 0]],
            'float stock truncates' => [['name' => 'X', 'price' => 1, 'stock' => 3.9], ['X', '', 1.0, 3]],
            'negative fractional stock truncates to zero' => [['name' => 'X', 'price' => 1, 'stock' => -0.9], ['X', '', 1.0, 0]],
            'non-numeric stock becomes zero' => [['name' => 'X', 'price' => 1, 'stock' => 'abc'], ['X', '', 1.0, 0]],
            'stock with trailing junk' => [['name' => 'X', 'price' => 1, 'stock' => '7 apples'], ['X', '', 1.0, 7]],
            'boolean true stock' => [['name' => 'X', 'price' => 1, 'stock' => true], ['X', '', 1.0, 1]],
            'boolean false stock' => [['name' => 'X', 'price' => 1, 'stock' => false], ['X', '', 1.0, 0]],
            'null stock defaults to zero' => [['name' => 'X', 'price' => 1, 'stock' => null], ['X', '', 1.0, 0]],
            'zero stock is allowed' => [['name' => 'X', 'price' => 1, 'stock' => 0], ['X', '', 1.0, 0]],
            'null description defaults to empty' => [['name' => 'X', 'price' => 1, 'description' => null], ['X', '', 1.0, 0]],
            'empty description kept' => [['name' => 'X', 'price' => 1, 'description' => ''], ['X', '', 1.0, 0]],
            'name and description are not cast' => [['name' => 123, 'price' => 1, 'description' => 456], [123, 456, 1.0, 0]],
            'unknown fields ignored' => [['name' => 'X', 'price' => 1, 'id' => 99, 'foo' => 'bar'], ['X', '', 1.0, 0]],
        ];
    }

    /**
     * @param array<string, mixed> $body
     * @param list<mixed>          $expectedParams
     */
    #[Test]
    #[DataProvider('createCasts')]
    public function create_casts_numeric_input_before_binding(array $body, array $expectedParams): void
    {
        $this->givenInsertCreates(self::row(11));

        [$status] = $this->request('POST', '/api/products', $body);

        self::assertSame(201, $status);
        self::assertSame([$expectedParams], $this->pdo->paramsOf('INSERT INTO products'));
    }

    #[Test]
    public function create_responds_with_the_cached_entry_if_one_already_exists_for_the_new_id(): void
    {
        $this->givenInsertCreates(self::row(42));
        $this->redis->seed('products:42', '{"stale":true}');

        [$status, $body] = $this->request('POST', '/api/products', ['name' => 'X', 'price' => 1]);

        self::assertSame(201, $status);
        self::assertSame('{"stale":true}', $body);
        self::assertSame([], $this->pdo->paramsOf(self::SELECT_ONE));
    }

    #[Test]
    public function create_responds_404_if_the_inserted_row_cannot_be_read_back(): void
    {
        $this->pdo->onSql('INSERT INTO products')->insertsId(42);
        $this->pdo->onSql(self::SELECT_ONE)->fetchesRow(false);

        [$status, $body] = $this->request('POST', '/api/products', ['name' => 'X', 'price' => 1]);

        self::assertSame(404, $status);
        self::assertSame('{"error":"Product not found"}', $body);
        self::assertSame([['products:all']], $this->redis->callsTo('del'));
    }

    #[Test]
    public function create_lets_a_database_exception_propagate_without_touching_the_cache(): void
    {
        $this->pdo->onSql('INSERT INTO products')->throws(ScriptedPdo::sqlStateException('23000'));

        try {
            $this->request('POST', '/api/products', ['name' => 'X', 'price' => 1]);
            self::fail('PDOException expected');
        } catch (PDOException $e) {
            self::assertSame('23000', $e->getCode());
        }

        self::assertSame([], $this->redis->calls());
    }

    /**
     * Raw bodies: the router receives the string exactly as given.
     *
     * @return array<string, array{string|null, string}>
     */
    public static function invalidCreateBodies(): array
    {
        $required = 'name and price are required';
        $price = 'price must be greater than zero';
        $stock = 'stock cannot be negative';

        return [
            'no body' => [null, $required],
            'invalid json' => ['{not json', $required],
            'json null' => ['null', $required],
            'json scalar number' => ['123', $required],
            'json scalar string' => ['"hello"', $required],
            'empty array' => ['[]', $required],
            'empty object' => ['{}', $required],
            'missing name' => ['{"price": 5}', $required],
            'missing price' => ['{"name": "X"}', $required],
            'null name' => ['{"name": null, "price": 5}', $required],
            'null price' => ['{"name": "X", "price": null}', $required],
            'price zero' => ['{"name": "X", "price": 0}', $price],
            'price zero string' => ['{"name": "X", "price": "0"}', $price],
            'price zero float' => ['{"name": "X", "price": 0.0}', $price],
            'price negative' => ['{"name": "X", "price": -5.5}', $price],
            'price negative string' => ['{"name": "X", "price": "-1"}', $price],
            'price non numeric' => ['{"name": "X", "price": "abc"}', $price],
            'price empty string' => ['{"name": "X", "price": ""}', $price],
            'price false' => ['{"name": "X", "price": false}', $price],
            'price checked before stock' => ['{"name": "X", "price": 0, "stock": -1}', $price],
            'stock negative' => ['{"name": "X", "price": 5, "stock": -1}', $stock],
            'stock negative string' => ['{"name": "X", "price": 5, "stock": "-3"}', $stock],
            'stock very negative' => ['{"name": "X", "price": 5, "stock": -1000000}', $stock],
        ];
    }

    #[Test]
    #[DataProvider('invalidCreateBodies')]
    public function create_rejects_invalid_input_with_400_and_writes_nothing(?string $body, string $error): void
    {
        [$status, $responseBody] = $this->request('POST', '/api/products', $body);

        self::assertSame(400, $status);
        self::assertSame(json_encode(['error' => $error]), $responseBody);
        self::assertSame([], $this->pdo->executed());
        self::assertSame([], $this->redis->calls());
        $this->assertNoDbWrite();
    }

    #[Test]
    public function create_ignores_query_string_and_trailing_slash_in_the_route(): void
    {
        $this->givenInsertCreates(self::row(3));

        [$status] = $this->request('POST', '/api/products/?source=test', ['name' => 'X', 'price' => 1]);

        self::assertSame(201, $status);
        self::assertTrue($this->pdo->wasExecuted('INSERT INTO products'));
    }

    // ------------------------------------------------------------------
    // PUT /api/products/{id}
    // ------------------------------------------------------------------

    /** @return array<string, array{string|array<string, mixed>|null}> */
    public static function updateBodies(): array
    {
        return [
            'valid body' => [['name' => 'New']],
            'empty body' => [null],
            'invalid json' => ['{oops'],
            'empty object' => ['{}'],
            'unknown fields only' => [['foo' => 'bar']],
        ];
    }

    /** @param string|array<string, mixed>|null $body */
    #[Test]
    #[DataProvider('updateBodies')]
    public function update_of_unknown_product_is_404_whatever_the_body_is(string|array|null $body): void
    {
        $this->pdo->onSql(self::SELECT_ID)->fetchesRow(false);

        [$status, $responseBody] = $this->request('PUT', '/api/products/9', $body);

        self::assertSame(404, $status);
        self::assertSame('{"error":"Product not found"}', $responseBody);
        self::assertSame([[9]], $this->pdo->paramsOf(self::SELECT_ID));
        self::assertSame([], $this->updateExecutions());
        self::assertSame([], $this->redis->calls());
        $this->assertNoDbWrite();
    }

    /** @return array<string, array{string|null}> */
    public static function emptyUpdateBodies(): array
    {
        return [
            'no body' => [null],
            'invalid json' => ['{not json'],
            'json null' => ['null'],
            'json scalar number' => ['123'],
            'json scalar string' => ['"hello"'],
            'empty array' => ['[]'],
            'empty object' => ['{}'],
            'unknown fields only' => ['{"foo": "bar", "id": 99, "category": "x"}'],
            'name null' => ['{"name": null}'],
            'all known fields null' => ['{"name": null, "description": null, "price": null, "stock": null}'],
            'null known plus unknown' => ['{"name": null, "foo": 1}'],
        ];
    }

    #[Test]
    #[DataProvider('emptyUpdateBodies')]
    public function update_with_nothing_to_update_is_400_after_the_existence_check(?string $body): void
    {
        $this->givenProductInDb(self::row(7));

        [$status, $responseBody] = $this->request('PUT', '/api/products/7', $body);

        self::assertSame(400, $status);
        self::assertSame('{"error":"No fields to update"}', $responseBody);
        self::assertSame([[7]], $this->pdo->paramsOf(self::SELECT_ID));
        self::assertSame([], $this->updateExecutions());
        self::assertSame([], $this->redis->calls());
        $this->assertNoDbWrite();
    }

    /** @return array<string, array{array<string, mixed>|string, string, list<mixed>}> */
    public static function partialUpdates(): array
    {
        return [
            'name only' => [['name' => 'New'], 'UPDATE products SET name = ? WHERE id = ?', ['New', 7]],
            'description only' => [['description' => 'Desc'], 'UPDATE products SET description = ? WHERE id = ?', ['Desc', 7]],
            'price only' => [['price' => 9.5], 'UPDATE products SET price = ? WHERE id = ?', [9.5, 7]],
            'stock only' => [['stock' => 12], 'UPDATE products SET stock = ? WHERE id = ?', [12, 7]],
            'name and price' => [
                ['name' => 'N', 'price' => 2.5],
                'UPDATE products SET name = ?, price = ? WHERE id = ?',
                ['N', 2.5, 7],
            ],
            'field order is fixed regardless of body order' => [
                '{"price": 2.5, "name": "N"}',
                'UPDATE products SET name = ?, price = ? WHERE id = ?',
                ['N', 2.5, 7],
            ],
            'description and stock' => [
                ['stock' => 1, 'description' => 'D'],
                'UPDATE products SET description = ?, stock = ? WHERE id = ?',
                ['D', 1, 7],
            ],
            'all four fields' => [
                ['name' => 'N', 'description' => 'D', 'price' => 3.25, 'stock' => 8],
                'UPDATE products SET name = ?, description = ?, price = ?, stock = ? WHERE id = ?',
                ['N', 'D', 3.25, 8, 7],
            ],
            'all four fields in reverse body order' => [
                '{"stock": 8, "price": 3.25, "description": "D", "name": "N"}',
                'UPDATE products SET name = ?, description = ?, price = ?, stock = ? WHERE id = ?',
                ['N', 'D', 3.25, 8, 7],
            ],
            'unknown and null fields are skipped' => [
                '{"name": "N", "id": 99, "foo": 1, "price": null, "stock": null}',
                'UPDATE products SET name = ? WHERE id = ?',
                ['N', 7],
            ],
            'zero stock is applied' => [['stock' => 0], 'UPDATE products SET stock = ? WHERE id = ?', [0, 7]],
            'empty description is applied' => [['description' => ''], 'UPDATE products SET description = ? WHERE id = ?', ['', 7]],
            'false is applied as a value' => [['stock' => false], 'UPDATE products SET stock = ? WHERE id = ?', [false, 7]],
            'no validation: zero price' => [['price' => 0], 'UPDATE products SET price = ? WHERE id = ?', [0, 7]],
            'no validation: negative price' => [['price' => -5], 'UPDATE products SET price = ? WHERE id = ?', [-5, 7]],
            'no validation: negative stock' => [['stock' => -3], 'UPDATE products SET stock = ? WHERE id = ?', [-3, 7]],
            'no casting: numeric strings are bound as strings' => [
                ['price' => '19.99', 'stock' => '5'],
                'UPDATE products SET price = ?, stock = ? WHERE id = ?',
                ['19.99', '5', 7],
            ],
            'no validation: non-numeric price' => [['price' => 'abc'], 'UPDATE products SET price = ? WHERE id = ?', ['abc', 7]],
        ];
    }

    /**
     * @param array<string, mixed>|string $body
     * @param list<mixed>                 $expectedParams
     */
    #[Test]
    #[DataProvider('partialUpdates')]
    public function update_builds_the_sql_from_the_provided_fields_in_fixed_order(
        array|string $body,
        string $expectedSql,
        array $expectedParams
    ): void {
        $this->givenProductInDb(self::row(7));

        [$status] = $this->request('PUT', '/api/products/7', $body);

        self::assertSame(200, $status);
        $updates = $this->updateExecutions();
        self::assertCount(1, $updates);
        self::assertSame($expectedSql, $updates[0]['sql']);
        self::assertSame($expectedParams, $updates[0]['params']);
    }

    #[Test]
    public function update_invalidates_both_caches_then_responds_with_the_fresh_product_with_status_200(): void
    {
        $fresh = self::row(7, ['name' => 'Renamed']);
        $this->givenProductInDb($fresh);
        $this->redis->seed('products:7', '{"stale":true}')->seed('products:all', '[{"stale":true}]');

        [$status, $body] = $this->request('PUT', '/api/products/7', ['name' => 'Renamed']);

        $json = json_encode($fresh);
        self::assertSame(200, $status);
        self::assertSame($json, $body);
        self::assertSame([
            ['del', ['products:7']],
            ['del', ['products:all']],
            ['get', ['products:7']],
            ['setex', ['products:7', 60, $json]],
        ], $this->redis->calls());
        self::assertSame($json, $this->redis->stored('products:7'));
        self::assertNull($this->redis->stored('products:all'));
    }

    #[Test]
    public function update_runs_existence_check_then_update_then_reload_in_that_order_with_int_ids(): void
    {
        $this->givenProductInDb(self::row(7));

        $this->request('PUT', '/api/products/7', ['stock' => 1]);

        self::assertSame(
            [self::SELECT_ID, 'UPDATE products SET stock = ? WHERE id = ?', self::SELECT_ONE],
            array_column($this->pdo->executed(), 'sql')
        );
        self::assertSame([[7]], $this->pdo->paramsOf(self::SELECT_ID));
        self::assertSame([[1, 7]], $this->pdo->paramsOf('UPDATE products'));
        self::assertSame([[7]], $this->pdo->paramsOf(self::SELECT_ONE));
    }

    #[Test]
    #[DataProvider('idUris')]
    public function update_binds_the_url_id_as_an_int(string $uri, int $expectedId): void
    {
        $this->pdo->onSql(self::SELECT_ID)->fetchesRow(['id' => $expectedId]);
        $this->pdo->onSql(self::SELECT_ONE)->fetchesRow(self::row($expectedId));

        [$status] = $this->request('PUT', $uri, ['name' => 'N']);

        self::assertSame(200, $status);
        self::assertSame([[$expectedId]], $this->pdo->paramsOf(self::SELECT_ID));
        self::assertSame([['N', $expectedId]], $this->pdo->paramsOf('UPDATE products'));
        self::assertSame([["products:{$expectedId}"], ['products:all']], $this->redis->callsTo('del'));
    }

    #[Test]
    public function update_responds_404_if_the_product_cannot_be_read_back_but_still_invalidates_caches(): void
    {
        $this->pdo->onSql(self::SELECT_ID)->fetchesRow(['id' => 7]);
        $this->pdo->onSql(self::SELECT_ONE)->fetchesRow(false);

        [$status, $body] = $this->request('PUT', '/api/products/7', ['name' => 'N']);

        self::assertSame(404, $status);
        self::assertSame('{"error":"Product not found"}', $body);
        self::assertSame([['products:7'], ['products:all']], $this->redis->callsTo('del'));
        self::assertSame([], $this->redis->callsTo('setex'));
    }

    #[Test]
    public function update_lets_a_database_exception_propagate_without_touching_the_cache(): void
    {
        $this->givenProductInDb(self::row(7));
        $this->pdo->onSql('UPDATE products')->throws(ScriptedPdo::sqlStateException('22001'));

        try {
            $this->request('PUT', '/api/products/7', ['name' => 'N']);
            self::fail('PDOException expected');
        } catch (PDOException $e) {
            self::assertSame('22001', $e->getCode());
        }

        self::assertSame([], $this->redis->calls());
    }

    // ------------------------------------------------------------------
    // DELETE /api/products/{id}
    // ------------------------------------------------------------------

    #[Test]
    public function delete_responds_204_with_empty_body_and_invalidates_both_caches(): void
    {
        $this->pdo->onSql(self::DELETE)->affects(1);

        [$status, $body] = $this->request('DELETE', '/api/products/7');

        self::assertSame(204, $status);
        self::assertSame('', $body);
        self::assertSame([[7]], $this->pdo->paramsOf(self::DELETE));
        self::assertSame(
            [self::DELETE],
            array_column($this->pdo->executed(), 'sql')
        );
        self::assertSame([
            ['del', ['products:7']],
            ['del', ['products:all']],
        ], $this->redis->calls());
    }

    #[Test]
    public function delete_removes_previously_cached_entries(): void
    {
        $this->pdo->onSql(self::DELETE)->affects(1);
        $this->redis->seed('products:7', '{"id":7}')->seed('products:all', '[{"id":7}]')->seed('products:8', '{"id":8}');

        $this->request('DELETE', '/api/products/7');

        self::assertNull($this->redis->stored('products:7'));
        self::assertNull($this->redis->stored('products:all'));
        self::assertSame('{"id":8}', $this->redis->stored('products:8'));
    }

    #[Test]
    #[DataProvider('idUris')]
    public function delete_binds_the_url_id_as_an_int(string $uri, int $expectedId): void
    {
        $this->pdo->onSql(self::DELETE)->affects(1);

        [$status] = $this->request('DELETE', $uri);

        self::assertSame(204, $status);
        self::assertSame([[$expectedId]], $this->pdo->paramsOf(self::DELETE));
        self::assertSame([["products:{$expectedId}"], ['products:all']], $this->redis->callsTo('del'));
    }

    #[Test]
    public function delete_of_unknown_product_is_404_and_leaves_the_cache_alone(): void
    {
        $this->pdo->onSql(self::DELETE)->affects(0);

        [$status, $body] = $this->request('DELETE', '/api/products/99');

        self::assertSame(404, $status);
        self::assertSame('{"error":"Product not found"}', $body);
        self::assertSame([[99]], $this->pdo->paramsOf(self::DELETE));
        self::assertSame([], $this->redis->calls());
    }

    #[Test]
    public function delete_without_a_matching_rule_counts_as_zero_rows_and_is_404(): void
    {
        [$status, $body] = $this->request('DELETE', '/api/products/1');

        self::assertSame(404, $status);
        self::assertSame('{"error":"Product not found"}', $body);
        self::assertSame([], $this->redis->calls());
    }

    #[Test]
    public function delete_of_a_product_referenced_by_orders_is_409_and_leaves_the_cache_alone(): void
    {
        $this->pdo->onSql(self::DELETE)->throws(ScriptedPdo::sqlStateException('23000'));
        $this->redis->seed('products:7', '{"id":7}')->seed('products:all', '[{"id":7}]');

        [$status, $body] = $this->request('DELETE', '/api/products/7');

        self::assertSame(409, $status);
        self::assertSame('{"error":"Cannot delete product that is referenced by orders"}', $body);
        self::assertSame([], $this->redis->callsTo('del'));
        self::assertSame('{"id":7}', $this->redis->stored('products:7'));
        self::assertSame('[{"id":7}]', $this->redis->stored('products:all'));
    }

    /** @return array<string, array{PDOException}> */
    public static function otherPdoFailures(): array
    {
        return [
            'base table not found' => [ScriptedPdo::sqlStateException('42S02')],
            'general error' => [ScriptedPdo::sqlStateException('HY000')],
            'serialization failure' => [ScriptedPdo::sqlStateException('40001')],
            'integer 23000 code is not the string 23000' => [new PDOException('int code', 23000)],
        ];
    }

    #[Test]
    #[DataProvider('otherPdoFailures')]
    public function delete_rethrows_any_other_pdo_exception(PDOException $failure): void
    {
        $this->pdo->onSql(self::DELETE)->throws($failure);

        try {
            $this->request('DELETE', '/api/products/7');
            self::fail('PDOException expected');
        } catch (PDOException $e) {
            self::assertSame($failure, $e);
        }

        self::assertSame([], $this->redis->calls());
    }
}
