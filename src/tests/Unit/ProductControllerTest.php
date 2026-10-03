<?php

namespace Tests\Unit;

use App\Controllers\ProductController;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ControllerTestCase;
use Tests\Support\FakePdo;
use Tests\Support\PhpInputStream;

/**
 * Branch matrix for App\Controllers\ProductController, driven directly (no Router).
 */
final class ProductControllerTest extends ControllerTestCase
{
    private const PRODUCT = ['id' => 5, 'name' => 'Widget', 'description' => 'A widget', 'price' => '19.99', 'stock' => 10];
    private const PRODUCT_JSON = '{"id":5,"name":"Widget","description":"A widget","price":"19.99","stock":10}';

    private const SELECT_ALL = 'SELECT * FROM products ORDER BY id';
    private const SELECT_ONE = 'SELECT * FROM products WHERE id = ?';
    private const SELECT_ID = 'SELECT id FROM products WHERE id = ?';
    private const INSERT = 'INSERT INTO products (name, description, price, stock) VALUES (?, ?, ?, ?)';
    private const DELETE = 'DELETE FROM products WHERE id = ?';

    private FakePdo $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = new FakePdo($this->mockPdo(), fn () => $this->createMock(\PDOStatement::class));
    }

    protected function tearDown(): void
    {
        PhpInputStream::restore();
        $this->assertSame([], $this->db->unmatched, 'SQL that no test rule matched');
        parent::tearDown();
    }

    /** Controller whose request body is the given string (no php://input involved). */
    private function controller(string $body = ''): ProductController
    {
        return new ProductController($this->db->pdo, $this->redis, fn () => $body);
    }

    private function assertNoSideEffects(): void
    {
        $this->assertFalse($this->db->hasInteractions());
        $this->assertSame([], $this->redis->calls);
    }

    // --- list ---

    public function testListCacheMissQueriesDbAndCachesForSixtySeconds(): void
    {
        $rows = [self::PRODUCT, ['id' => 6, 'name' => 'Gadget', 'description' => '', 'price' => '5.00', 'stock' => 0]];
        $this->db->onSql(self::SELECT_ALL, rows: $rows);

        $this->controller()->list();

        $json = json_encode($rows);
        $this->assertSame(200, $this->httpStatus());
        $this->assertSame($json, $this->capturedOutput());
        $this->assertSame([self::SELECT_ALL], $this->db->queried);
        $this->assertSame([], $this->db->prepared);
        $this->assertSame([['get', ['products:all']], ['setex', ['products:all', 60, $json]]], $this->redis->calls);
        $this->assertSame(['products:all' => 60], $this->redis->ttls);
        $this->assertSame($json, $this->redis->store['products:all']);
    }

    public function testListCacheMissWithNoRowsCachesEmptyArray(): void
    {
        $this->db->onSql(self::SELECT_ALL, rows: []);

        $this->controller()->list();

        $this->assertSame(200, $this->httpStatus());
        $this->assertSame('[]', $this->capturedOutput());
        $this->assertSame([['get', ['products:all']], ['setex', ['products:all', 60, '[]']]], $this->redis->calls);
    }

    public function testListCacheHitEchoesCachedStringWithoutTouchingDb(): void
    {
        $this->redis->seed('products:all', '[{"id":1,"cached":true}]');

        $this->controller()->list();

        $this->assertSame(200, $this->httpStatus());
        $this->assertSame('[{"id":1,"cached":true}]', $this->capturedOutput());
        $this->assertFalse($this->db->hasInteractions());
        $this->assertSame([['get', ['products:all']]], $this->redis->calls);
    }

    #[DataProvider('falsyCacheValueProvider')]
    public function testListFalsyCachedValueFallsThroughToDb(string $cached): void
    {
        $this->redis->seed('products:all', $cached);
        $this->db->onSql(self::SELECT_ALL, rows: [self::PRODUCT]);

        $this->controller()->list();

        $json = '[' . self::PRODUCT_JSON . ']';
        $this->assertSame($json, $this->capturedOutput());
        $this->assertSame([self::SELECT_ALL], $this->db->queried);
        $this->assertSame([['get', ['products:all']], ['setex', ['products:all', 60, $json]]], $this->redis->calls);
    }

    public static function falsyCacheValueProvider(): array
    {
        return ['empty string' => [''], 'string zero' => ['0']];
    }

    // --- get ---

    public function testGetCacheMissQueriesDbWithIntIdAndCachesForSixtySeconds(): void
    {
        $this->db->onSql(self::SELECT_ONE, fetch: self::PRODUCT);

        $this->controller()->get(5);

        $this->assertSame(200, $this->httpStatus());
        $this->assertSame(self::PRODUCT_JSON, $this->capturedOutput());
        $this->assertSame([self::SELECT_ONE], $this->db->prepared);
        $this->assertSame([[5]], $this->db->paramsFor(self::SELECT_ONE));
        $this->assertSame(
            [['get', ['products:5']], ['setex', ['products:5', 60, self::PRODUCT_JSON]]],
            $this->redis->calls
        );
        $this->assertSame(['products:5' => 60], $this->redis->ttls);
    }

    public function testGetCacheHitEchoesCachedStringWithoutTouchingDb(): void
    {
        $this->redis->seed('products:5', '{"id":5,"cached":true}');

        $this->controller()->get(5);

        $this->assertSame(200, $this->httpStatus());
        $this->assertSame('{"id":5,"cached":true}', $this->capturedOutput());
        $this->assertFalse($this->db->hasInteractions());
        $this->assertSame([['get', ['products:5']]], $this->redis->calls);
    }

    #[DataProvider('falsyCacheValueProvider')]
    public function testGetFalsyCachedValueFallsThroughToDb(string $cached): void
    {
        $this->redis->seed('products:5', $cached);
        $this->db->onSql(self::SELECT_ONE, fetch: self::PRODUCT);

        $this->controller()->get(5);

        $this->assertSame(self::PRODUCT_JSON, $this->capturedOutput());
        $this->assertSame([[5]], $this->db->paramsFor(self::SELECT_ONE));
        $this->assertSame(
            [['get', ['products:5']], ['setex', ['products:5', 60, self::PRODUCT_JSON]]],
            $this->redis->calls
        );
    }

    public function testGetNotFoundReturns404AndDoesNotCache(): void
    {
        $this->db->onSql(self::SELECT_ONE, fetch: false);

        $this->controller()->get(99);

        $this->assertSame(404, $this->httpStatus());
        $this->assertSame('{"error":"Product not found"}', $this->capturedOutput());
        $this->assertSame([[99]], $this->db->paramsFor(self::SELECT_ONE));
        $this->assertSame([['get', ['products:99']]], $this->redis->calls);
        $this->assertSame([], $this->redis->store);
    }

    // --- create: validation (400) ---

    public static function createInvalidProvider(): array
    {
        $required = 'name and price are required';
        $price = 'price must be greater than zero';
        $stock = 'stock cannot be negative';
        return [
            'missing name' => ['{"price":5}', $required],
            'missing price' => ['{"name":"Widget"}', $required],
            'both missing' => ['{"description":"x"}', $required],
            'null name' => ['{"name":null,"price":5}', $required],
            'null price' => ['{"name":"Widget","price":null}', $required],
            'invalid json' => ['{not json', $required],
            'empty body' => ['', $required],
            'json null' => ['null', $required],
            'json empty array' => ['[]', $required],
            'json scalar' => ['5', $required],
            'price zero int' => ['{"name":"Widget","price":0}', $price],
            'price zero float' => ['{"name":"Widget","price":0.0}', $price],
            'price zero string' => ['{"name":"Widget","price":"0"}', $price],
            'price negative' => ['{"name":"Widget","price":-1}', $price],
            'price tiny negative' => ['{"name":"Widget","price":-0.01}', $price],
            'price non-numeric string' => ['{"name":"Widget","price":"abc"}', $price],
            'price empty string' => ['{"name":"Widget","price":""}', $price],
            'price false' => ['{"name":"Widget","price":false}', $price],
            'stock negative' => ['{"name":"Widget","price":5,"stock":-1}', $stock],
            'stock negative string' => ['{"name":"Widget","price":5,"stock":"-5"}', $stock],
            'stock negative float' =>['{"name":"Widget","price":5,"stock":-1.5}', $stock],
        ];
    }

    #[DataProvider('createInvalidProvider')]
    public function testCreateInvalidInputReturns400WithoutSideEffects(string $body, string $message): void
    {
        $this->controller($body)->create();

        $this->assertSame(400, $this->httpStatus());
        $this->assertSame(json_encode(['error' => $message]), $this->capturedOutput());
        $this->assertNoSideEffects();
    }

    public function testCreateChecksPriceBeforeStock(): void
    {
        $this->controller('{"name":"Widget","price":0,"stock":-1}')->create();

        $this->assertSame(400, $this->httpStatus());
        $this->assertSame('{"error":"price must be greater than zero"}', $this->capturedOutput());
        $this->assertNoSideEffects();
    }

    // --- create: success (201) ---

    public static function createSuccessProvider(): array
    {
        return [
            'all fields' => [
                '{"name":"Widget","description":"A widget","price":19.99,"stock":10}',
                ['Widget', 'A widget', 19.99, 10],
            ],
            'defaults for description and stock' => [
                '{"name":"Widget","price":5}',
                ['Widget', '', 5.0, 0],
            ],
            'string price and stock are cast' => [
                '{"name":"Widget","price":"19.99","stock":"3"}',
                ['Widget', '', 19.99, 3],
            ],
            'exponent string price' => [
                '{"name":"Widget","price":"1e2"}',
                ['Widget', '', 100.0, 0],
            ],
            'stock zero accepted' => [
                '{"name":"Widget","price":1,"stock":0}',
                ['Widget', '', 1.0, 0],
            ],
            'null description falls back to empty string' => [
                '{"name":"Widget","price":1,"description":null}',
                ['Widget', '', 1.0, 0],
            ],
            'null stock falls back to zero' => [
                '{"name":"Widget","price":1,"stock":null}',
                ['Widget', '', 1.0, 0],
            ],
            'float stock truncated' => [
                '{"name":"Widget","price":1,"stock":2.9}',
                ['Widget', '', 1.0, 2],
            ],
            'non-numeric stock string becomes zero' => [
                '{"name":"Widget","price":1,"stock":"abc"}',
                ['Widget', '', 1.0, 0],
            ],
            'fractional negative stock truncating to zero accepted' => [
                '{"name":"Widget","price":1,"stock":-0.5}',
                ['Widget', '', 1.0, 0],
            ],
            'unknown keys ignored' => [
                '{"name":"Widget","price":1,"id":99,"foo":"bar"}',
                ['Widget', '', 1.0, 0],
            ],
        ];
    }

    #[DataProvider('createSuccessProvider')]
    public function testCreateInsertsWithDefaultsAndCasts(string $body, array $expectedParams): void
    {
        $this->db->withInsertIds('5');
        $this->db->onSql(self::INSERT);
        $this->db->onSql(self::SELECT_ONE, fetch: self::PRODUCT);

        $this->controller($body)->create();

        $this->assertSame([$expectedParams], $this->db->paramsFor(self::INSERT));
        $this->assertSame(201, $this->httpStatus());
        $this->assertSame(self::PRODUCT_JSON, $this->capturedOutput());
    }

    public function testCreateInvalidatesListCacheThenRespondsThroughGetPath(): void
    {
        $this->db->withInsertIds('5');
        $this->db->onSql(self::INSERT);
        $this->db->onSql(self::SELECT_ONE, fetch: self::PRODUCT);
        $this->redis->seed('products:all', '[stale]');

        $this->controller('{"name":"Widget","price":19.99}')->create();

        $this->assertSame(201, $this->httpStatus(), '201 must survive the read-back through get()');
        $this->assertSame(self::PRODUCT_JSON, $this->capturedOutput());
        $this->assertSame([self::INSERT, self::SELECT_ONE], $this->db->prepared);
        // lastInsertId is cast to int and used for the read-back
        $this->assertSame([[5]], $this->db->paramsFor(self::SELECT_ONE));
        $this->assertSame(
            [
                ['del', ['products:all']],
                ['get', ['products:5']],
                ['setex', ['products:5', 60, self::PRODUCT_JSON]],
            ],
            $this->redis->calls
        );
        $this->assertArrayNotHasKey('products:all', $this->redis->store);
    }

    public function testCreateReadBackCacheHitIsServedWith201(): void
    {
        $this->db->withInsertIds('5');
        $this->db->onSql(self::INSERT);
        $this->redis->seed('products:5', '{"id":5,"cached":true}');

        $this->controller('{"name":"Widget","price":2}')->create();

        $this->assertSame(201, $this->httpStatus());
        $this->assertSame('{"id":5,"cached":true}', $this->capturedOutput());
        $this->assertSame([self::INSERT], $this->db->prepared);
        $this->assertSame([['del', ['products:all']], ['get', ['products:5']]], $this->redis->calls);
    }

    public function testCreateReadBackMissingRowOverridesStatusTo404(): void
    {
        $this->db->withInsertIds('7');
        $this->db->onSql(self::INSERT);
        $this->db->onSql(self::SELECT_ONE, fetch: false);

        $this->controller('{"name":"Widget","price":2}')->create();

        $this->assertSame(404, $this->httpStatus());
        $this->assertSame('{"error":"Product not found"}', $this->capturedOutput());
        $this->assertSame([[7]], $this->db->paramsFor(self::SELECT_ONE));
    }

    public function testCreateReadsRequestBodyOnce(): void
    {
        $reads = 0;
        $controller = new ProductController($this->db->pdo, $this->redis, function () use (&$reads) {
            $reads++;
            return '{"name":"Widget"}';
        });

        $controller->create();

        $this->assertSame(1, $reads);
        $this->assertSame(400, $this->httpStatus());
    }

    // --- update ---

    public function testUpdateNotFoundReturns404BeforeValidatingBody(): void
    {
        $this->db->onSql(self::SELECT_ID, fetch: false);

        $this->controller('{}')->update(3);

        $this->assertSame(404, $this->httpStatus());
        $this->assertSame('{"error":"Product not found"}', $this->capturedOutput());
        $this->assertSame([self::SELECT_ID], $this->db->prepared);
        $this->assertSame([[3]], $this->db->paramsFor(self::SELECT_ID));
        $this->assertSame([], $this->redis->calls);
    }

    public function testUpdateReadsBodyEvenWhenProductIsMissing(): void
    {
        $this->db->onSql(self::SELECT_ID, fetch: false);
        $reads = 0;
        $controller = new ProductController($this->db->pdo, $this->redis, function () use (&$reads) {
            $reads++;
            return '{"name":"New"}';
        });

        $controller->update(3);

        $this->assertSame(1, $reads);
        $this->assertSame(404, $this->httpStatus());
    }

    public static function updateNoFieldsProvider(): array
    {
        return [
            'empty object' => ['{}'],
            'empty array' => ['[]'],
            'invalid json' => ['{not json'],
            'empty body' => [''],
            'json null' => ['null'],
            'all supported fields null' => ['{"name":null,"description":null,"price":null,"stock":null}'],
            'only unknown fields' => ['{"foo":1,"id":9,"sku":"x"}'],
        ];
    }

    #[DataProvider('updateNoFieldsProvider')]
    public function testUpdateWithNoUsableFieldsReturns400(string $body): void
    {
        $this->db->onSql(self::SELECT_ID, fetch: ['id' => 3]);

        $this->controller($body)->update(3);

        $this->assertSame(400, $this->httpStatus());
        $this->assertSame('{"error":"No fields to update"}', $this->capturedOutput());
        $this->assertSame([self::SELECT_ID], $this->db->prepared);
        $this->assertCount(1, $this->db->executions, 'only the existence check may execute');
        $this->assertSame([], $this->redis->calls);
    }

    public static function updateFieldsProvider(): array
    {
        return [
            'name only' => [
                '{"name":"New"}',
                'UPDATE products SET name = ? WHERE id = ?',
                ['New', 3],
            ],
            'description only' => [
                '{"description":"Desc"}',
                'UPDATE products SET description = ? WHERE id = ?',
                ['Desc', 3],
            ],
            'price only (value passed through uncast)' => [
                '{"price":12.5}',
                'UPDATE products SET price = ? WHERE id = ?',
                [12.5, 3],
            ],
            'price string passed through uncast' => [
                '{"price":"12.50"}',
                'UPDATE products SET price = ? WHERE id = ?',
                ['12.50', 3],
            ],
            'stock only' => [
                '{"stock":7}',
                'UPDATE products SET stock = ? WHERE id = ?',
                [7, 3],
            ],
            'all four fields in canonical order regardless of body order' => [
                '{"stock":7,"price":12.5,"description":"Desc","name":"New"}',
                'UPDATE products SET name = ?, description = ?, price = ?, stock = ? WHERE id = ?',
                ['New', 'Desc', 12.5, 7, 3],
            ],
            'subset keeps canonical order' => [
                '{"stock":1,"name":"N"}',
                'UPDATE products SET name = ?, stock = ? WHERE id = ?',
                ['N', 1, 3],
            ],
            'null values are skipped' => [
                '{"name":null,"description":"D","price":null,"stock":4}',
                'UPDATE products SET description = ?, stock = ? WHERE id = ?',
                ['D', 4, 3],
            ],
            'falsy but non-null values are kept (no validation)' => [
                '{"name":"","price":0,"stock":0,"description":""}',
                'UPDATE products SET name = ?, description = ?, price = ?, stock = ? WHERE id = ?',
                ['', '', 0, 0, 3],
            ],
            'negative values are not validated' => [
                '{"price":-5,"stock":-2}',
                'UPDATE products SET price = ?, stock = ? WHERE id = ?',
                [-5, -2, 3],
            ],
            'unknown fields ignored' => [
                '{"name":"New","id":99,"foo":"bar"}',
                'UPDATE products SET name = ? WHERE id = ?',
                ['New', 3],
            ],
        ];
    }

    #[DataProvider('updateFieldsProvider')]
    public function testUpdateBuildsSqlAndParamsFromProvidedFields(string $body, string $expectedSql, array $expectedParams): void
    {
        $product = ['id' => 3, 'name' => 'New', 'description' => 'Desc', 'price' => '12.50', 'stock' => 7];
        $this->db->onSql(self::SELECT_ID, fetch: ['id' => 3]);
        $this->db->onSql('UPDATE products SET', rowCount: 1);
        $this->db->onSql(self::SELECT_ONE, fetch: $product);

        $this->controller($body)->update(3);

        $this->assertSame([self::SELECT_ID, $expectedSql, self::SELECT_ONE], $this->db->prepared);
        $this->assertSame([$expectedParams], $this->db->paramsFor('UPDATE products SET'));
        $this->assertSame(200, $this->httpStatus());
        $this->assertSame(json_encode($product), $this->capturedOutput());
    }

    public function testUpdateInvalidatesCachesBeforeReadingBack(): void
    {
        $product = ['id' => 3, 'name' => 'New', 'description' => '', 'price' => '1.00', 'stock' => 0];
        $this->db->onSql(self::SELECT_ID, fetch: ['id' => 3]);
        $this->db->onSql('UPDATE products SET', rowCount: 1);
        $this->db->onSql(self::SELECT_ONE, fetch: $product);
        $this->redis->seed('products:3', '{"stale":true}');
        $this->redis->seed('products:all', '[stale]');

        $this->controller('{"name":"New"}')->update(3);

        $json = json_encode($product);
        $this->assertSame(200, $this->httpStatus());
        $this->assertSame($json, $this->capturedOutput());
        $this->assertSame(
            [
                ['del', ['products:3']],
                ['del', ['products:all']],
                ['get', ['products:3']],
                ['setex', ['products:3', 60, $json]],
            ],
            $this->redis->calls
        );
        $this->assertSame($json, $this->redis->store['products:3']);
        $this->assertArrayNotHasKey('products:all', $this->redis->store);
        // the read-back hits the DB (cache was invalidated), with the int id
        $this->assertSame([[3]], $this->db->paramsFor(self::SELECT_ONE));
    }

    public function testUpdateReadBackMissingRowReturns404AfterInvalidation(): void
    {
        $this->db->onSql(self::SELECT_ID, fetch: ['id' => 3]);
        $this->db->onSql('UPDATE products SET', rowCount: 0);
        $this->db->onSql(self::SELECT_ONE, fetch: false);

        $this->controller('{"name":"New"}')->update(3);

        $this->assertSame(404, $this->httpStatus());
        $this->assertSame('{"error":"Product not found"}', $this->capturedOutput());
        $this->assertSame(
            [['del', ['products:3']], ['del', ['products:all']], ['get', ['products:3']]],
            $this->redis->calls
        );
    }

    // --- delete ---

    public function testDeleteSuccessReturns204EmptyBodyAndInvalidatesCaches(): void
    {
        $this->db->onSql(self::DELETE, rowCount: 1);
        $this->redis->seed('products:4', '{"id":4}');
        $this->redis->seed('products:all', '[{"id":4}]');

        $this->controller()->delete(4);

        $this->assertSame(204, $this->httpStatus());
        $this->assertSame('', $this->capturedOutput());
        $this->assertSame([self::DELETE], $this->db->prepared);
        $this->assertSame([[4]], $this->db->paramsFor(self::DELETE));
        $this->assertSame([['del', ['products:4']], ['del', ['products:all']]], $this->redis->calls);
        $this->assertSame([], $this->redis->store);
    }

    public function testDeleteNoRowReturns404WithoutTouchingCache(): void
    {
        $this->db->onSql(self::DELETE, rowCount: 0);
        $this->redis->seed('products:4', '{"id":4}');
        $this->redis->calls = [];

        $this->controller()->delete(4);

        $this->assertSame(404, $this->httpStatus());
        $this->assertSame('{"error":"Product not found"}', $this->capturedOutput());
        $this->assertSame([[4]], $this->db->paramsFor(self::DELETE));
        $this->assertSame([], $this->redis->calls);
        $this->assertSame(['products:4' => '{"id":4}'], $this->redis->store);
    }

    public function testDeleteReferencedByOrdersReturns409WithoutTouchingCache(): void
    {
        $this->db->onSql(self::DELETE, executeThrows: $this->pdoException('23000', 'FK constraint'));

        $this->controller()->delete(4);

        $this->assertSame(409, $this->httpStatus());
        $this->assertSame('{"error":"Cannot delete product that is referenced by orders"}', $this->capturedOutput());
        $this->assertSame([], $this->redis->calls);
    }

    public function testDeleteRethrowsOtherPdoExceptions(): void
    {
        $error = $this->pdoException('HY000', 'server gone');
        $this->db->onSql(self::DELETE, executeThrows: $error);

        try {
            $this->controller()->delete(4);
            $this->fail('PDOException was expected to propagate');
        } catch (\PDOException $e) {
            $this->assertSame($error, $e);
        }

        $this->assertSame(200, $this->httpStatus());
        $this->assertSame('', $this->capturedOutput());
        $this->assertSame([], $this->redis->calls);
    }

    // --- input reader seam ---

    public function testAnyCallableCanBeUsedAsInputReader(): void
    {
        $reader = new class {
            public function __invoke(): string
            {
                return '{"name":"Widget"}';
            }
        };
        $controller = new ProductController($this->db->pdo, $this->redis, $reader);

        $controller->create();

        $this->assertSame(400, $this->httpStatus());
        $this->assertSame('{"error":"name and price are required"}', $this->capturedOutput());
    }

    public function testDefaultInputReaderReadsPhpInput(): void
    {
        PhpInputStream::register();
        PhpInputStream::setBody('{"name":"Widget","price":19.99,"stock":10}');
        $this->db->withInsertIds('5');
        $this->db->onSql(self::INSERT);
        $this->db->onSql(self::SELECT_ONE, fetch: self::PRODUCT);

        (new ProductController($this->db->pdo, $this->redis))->create();

        $this->assertSame([['Widget', '', 19.99, 10]], $this->db->paramsFor(self::INSERT));
        $this->assertSame(201, $this->httpStatus());
        $this->assertSame(self::PRODUCT_JSON, $this->capturedOutput());
    }
}
