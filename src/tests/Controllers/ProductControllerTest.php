<?php

namespace Tests\Controllers;

use App\Controllers\ProductController;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\HttpTestCase;
use Tests\Support\ScriptedPdo;

/**
 * Controller-level tests: ProductController is built directly from the fakes (no Router)
 * and its list() / get() / create() / update() / delete() actions are invoked one by one.
 */
final class ProductControllerTest extends HttpTestCase
{
    private const SQL_LIST = 'SELECT * FROM products ORDER BY id';
    private const SQL_SELECT = 'SELECT * FROM products WHERE id = ?';
    private const SQL_EXISTS = 'SELECT id FROM products WHERE id = ?';
    private const SQL_INSERT = 'INSERT INTO products (name, description, price, stock) VALUES (?, ?, ?, ?)';
    private const SQL_UPDATE_PREFIX = 'UPDATE products SET';
    private const SQL_DELETE = 'DELETE FROM products WHERE id = ?';

    private const WIDGET = ['id' => 1, 'name' => 'Widget', 'description' => 'A widget', 'price' => '19.99', 'stock' => 10];

    // ------------------------------------------------------------------
    // Fixtures and helpers
    // ------------------------------------------------------------------

    private function controller(): ProductController
    {
        return new ProductController($this->pdo, $this->redis);
    }

    /** @return array{0: int, 1: string} */
    private function callList(): array
    {
        $controller = $this->controller();

        return $this->capture(static function () use ($controller): void {
            $controller->list();
        });
    }

    /** @return array{0: int, 1: string} */
    private function callGet(int $id): array
    {
        $controller = $this->controller();

        return $this->capture(static function () use ($controller, $id): void {
            $controller->get($id);
        });
    }

    /**
     * @param string|array<mixed> $body
     *
     * @return array{0: int, 1: string}
     */
    private function callCreate(string|array $body): array
    {
        $controller = $this->controller();

        return $this->capture(static function () use ($controller): void {
            $controller->create();
        }, $body);
    }

    /**
     * @param string|array<mixed> $body
     *
     * @return array{0: int, 1: string}
     */
    private function callUpdate(int $id, string|array $body): array
    {
        $controller = $this->controller();

        return $this->capture(static function () use ($controller, $id): void {
            $controller->update($id);
        }, $body);
    }

    /** @return array{0: int, 1: string} */
    private function callDelete(int $id): array
    {
        $controller = $this->controller();

        return $this->capture(static function () use ($controller, $id): void {
            $controller->delete($id);
        });
    }

    /** @param array<string, mixed> $row */
    private function givenProduct(int $id, array $row = self::WIDGET): void
    {
        $this->pdo->onSql(self::SQL_SELECT)->withParams([$id])->fetchesRow($row);
        $this->pdo->onSql(self::SQL_EXISTS)->withParams([$id])->fetchesRow(['id' => $id]);
    }

    /** @return list<string> */
    private function executedSql(): array
    {
        return array_column($this->pdo->executed(), 'sql');
    }

    private function assertNoDatabaseWrite(): void
    {
        self::assertSame([], $this->pdo->executions('INSERT'));
        self::assertSame([], $this->pdo->executions('UPDATE'));
        self::assertSame([], $this->pdo->executions('DELETE'));
    }

    // ------------------------------------------------------------------
    // list()
    // ------------------------------------------------------------------

    #[Test]
    public function listOnCacheMissQueriesTheDatabaseAndCachesTheJsonForSixtySeconds(): void
    {
        $rows = [self::WIDGET, ['id' => 2, 'name' => 'Gadget', 'description' => '', 'price' => '5.00', 'stock' => 0]];
        $this->pdo->onSql(self::SQL_LIST)->fetchesRows($rows);

        [$status, $body] = $this->callList();

        self::assertSame(200, $status);
        self::assertSame(json_encode($rows), $body);
        self::assertSame([['products:all', 60, json_encode($rows)]], $this->redis->callsTo('setex'));
        self::assertSame(json_encode($rows), $this->redis->stored('products:all'));
        self::assertSame([self::SQL_LIST], $this->executedSql());
    }

    #[Test]
    public function listOnCacheHitEchoesTheCachedJsonWithoutTouchingTheDatabase(): void
    {
        $this->redis->seed('products:all', '[{"id":9}]');

        [$status, $body] = $this->callList();

        self::assertSame(200, $status);
        self::assertSame('[{"id":9}]', $body);
        self::assertSame([], $this->pdo->executed());
        self::assertSame([], $this->redis->callsTo('setex'));
    }

    #[Test]
    public function listWithNoProductsReturnsAnEmptyJsonArray(): void
    {
        [$status, $body] = $this->callList();

        self::assertSame(200, $status);
        self::assertSame('[]', $body);
        self::assertSame([['products:all', 60, '[]']], $this->redis->callsTo('setex'));
    }

    // ------------------------------------------------------------------
    // get()
    // ------------------------------------------------------------------

    #[Test]
    public function getOnCacheMissLoadsTheRowAndCachesItUnderItsOwnKey(): void
    {
        $this->givenProduct(1);

        [$status, $body] = $this->callGet(1);

        self::assertSame(200, $status);
        self::assertSame(json_encode(self::WIDGET), $body);
        self::assertSame([['products:1', 60, json_encode(self::WIDGET)]], $this->redis->callsTo('setex'));
        self::assertSame([[1]], $this->pdo->paramsOf(self::SQL_SELECT));
    }

    #[Test]
    public function getOnCacheHitEchoesTheCachedJsonWithoutTouchingTheDatabase(): void
    {
        $this->redis->seed('products:7', '{"id":7,"name":"Cached"}');

        [$status, $body] = $this->callGet(7);

        self::assertSame(200, $status);
        self::assertSame('{"id":7,"name":"Cached"}', $body);
        self::assertSame([], $this->pdo->executed());
        self::assertSame([], $this->redis->callsTo('setex'));
    }

    #[Test]
    public function getOfAnUnknownProductRespondsWith404AndCachesNothing(): void
    {
        [$status, $body] = $this->callGet(99);

        self::assertSame(404, $status);
        self::assertSame('{"error":"Product not found"}', $body);
        self::assertSame([], $this->redis->callsTo('setex'));
        self::assertNull($this->redis->stored('products:99'));
        self::assertSame([[99]], $this->pdo->paramsOf(self::SQL_SELECT));
    }

    #[Test]
    public function getChecksOnlyItsOwnCacheKey(): void
    {
        $this->redis->seed('products:2', '{"id":2}');
        $this->givenProduct(1);

        [$status, $body] = $this->callGet(1);

        self::assertSame(200, $status);
        self::assertSame(json_encode(self::WIDGET), $body);
        self::assertSame([['products:1']], $this->redis->callsTo('get'));
    }

    #[Test]
    public function getDoesNotCacheAfterAMissOnADifferentId(): void
    {
        $this->givenProduct(1);

        $this->callGet(2);

        self::assertNull($this->redis->stored('products:1'));
        self::assertNull($this->redis->stored('products:2'));
    }

    // ------------------------------------------------------------------
    // create(): validation
    // ------------------------------------------------------------------

    /** @return array<string, array{0: array<mixed>|string, 1: string}> */
    public static function invalidCreateBodies(): array
    {
        return [
            'empty object' => [[], 'name and price are required'],
            'missing name' => [['price' => 5], 'name and price are required'],
            'missing price' => [['name' => 'Widget'], 'name and price are required'],
            'null name' => [['name' => null, 'price' => 5], 'name and price are required'],
            'null price' => [['name' => 'Widget', 'price' => null], 'name and price are required'],
            'invalid json' => ['not json', 'name and price are required'],
            'empty body' => ['', 'name and price are required'],
            'zero price' => [['name' => 'Widget', 'price' => 0], 'price must be greater than zero'],
            'negative price' => [['name' => 'Widget', 'price' => -1.5], 'price must be greater than zero'],
            'zero price string' => [['name' => 'Widget', 'price' => '0.00'], 'price must be greater than zero'],
            'non numeric price' => [['name' => 'Widget', 'price' => 'abc'], 'price must be greater than zero'],
            'negative stock' => [['name' => 'Widget', 'price' => 5, 'stock' => -1], 'stock cannot be negative'],
            'negative stock string' => [['name' => 'Widget', 'price' => 5, 'stock' => '-3'], 'stock cannot be negative'],
        ];
    }

    /** @param array<mixed>|string $body */
    #[Test]
    #[DataProvider('invalidCreateBodies')]
    public function createRejectsInvalidInputWith400AndDoesNotWrite(array|string $body, string $message): void
    {
        [$status, $response] = $this->callCreate($body);

        self::assertSame(400, $status);
        self::assertSame(json_encode(['error' => $message]), $response);
        self::assertSame([], $this->pdo->executed());
        self::assertSame([], $this->redis->calls());
        $this->assertNoDatabaseWrite();
    }

    #[Test]
    public function createChecksThePriceBeforeTheStock(): void
    {
        [$status, $response] = $this->callCreate(['name' => 'Widget', 'price' => 0, 'stock' => -1]);

        self::assertSame(400, $status);
        self::assertSame('{"error":"price must be greater than zero"}', $response);
    }

    // ------------------------------------------------------------------
    // create(): success
    // ------------------------------------------------------------------

    #[Test]
    public function createInsertsTheRowAndRespondsWith201AndTheProductJson(): void
    {
        $this->pdo->onSql(self::SQL_INSERT)->insertsId(5);
        $row = ['id' => 5, 'name' => 'Widget', 'description' => 'A widget', 'price' => '19.99', 'stock' => 10];
        $this->givenProduct(5, $row);

        [$status, $body] = $this->callCreate(
            ['name' => 'Widget', 'description' => 'A widget', 'price' => 19.99, 'stock' => 10]
        );

        self::assertSame(201, $status);
        self::assertSame(json_encode($row), $body);
        self::assertSame([['Widget', 'A widget', 19.99, 10]], $this->pdo->paramsOf(self::SQL_INSERT));
    }

    #[Test]
    public function createRespondsThroughTheControllersOwnGetAndCachesTheNewProduct(): void
    {
        $this->pdo->onSql(self::SQL_INSERT)->insertsId(8);
        $row = ['id' => 8, 'name' => 'Gizmo', 'description' => '', 'price' => '3.50', 'stock' => 0];
        $this->givenProduct(8, $row);

        $this->callCreate(['name' => 'Gizmo', 'price' => 3.5]);

        self::assertSame([[8]], $this->pdo->paramsOf(self::SQL_SELECT));
        self::assertSame([['products:8', 60, json_encode($row)]], $this->redis->callsTo('setex'));
    }

    #[Test]
    public function createAppliesDefaultsForDescriptionAndStock(): void
    {
        $this->pdo->onSql(self::SQL_INSERT)->insertsId(3);

        $this->callCreate(['name' => 'Bare', 'price' => 1]);

        self::assertSame([['Bare', '', 1.0, 0]], $this->pdo->paramsOf(self::SQL_INSERT));
    }

    #[Test]
    public function createCastsPriceToFloatAndStockToInt(): void
    {
        $this->pdo->onSql(self::SQL_INSERT)->insertsId(4);

        $this->callCreate(['name' => 'Cast', 'description' => 'd', 'price' => '12.5', 'stock' => '7']);

        $params = $this->pdo->paramsOf(self::SQL_INSERT)[0];
        self::assertSame(['Cast', 'd', 12.5, 7], $params);
    }

    #[Test]
    public function createAcceptsZeroStock(): void
    {
        $this->pdo->onSql(self::SQL_INSERT)->insertsId(6);
        $this->givenProduct(6, ['id' => 6, 'name' => 'Empty', 'description' => '', 'price' => '2.00', 'stock' => 0]);

        [$status] = $this->callCreate(['name' => 'Empty', 'price' => 2, 'stock' => 0]);

        self::assertSame(201, $status);
        self::assertSame([['Empty', '', 2.0, 0]], $this->pdo->paramsOf(self::SQL_INSERT));
    }

    #[Test]
    public function createInvalidatesTheListCache(): void
    {
        $this->redis->seed('products:all', '[]');
        $this->pdo->onSql(self::SQL_INSERT)->insertsId(9);

        $this->callCreate(['name' => 'New', 'price' => 4]);

        self::assertSame([['products:all']], $this->redis->callsTo('del'));
        self::assertNull($this->redis->stored('products:all'));
    }

    #[Test]
    public function createDoesNotInvalidateTheListCacheWhenValidationFails(): void
    {
        $this->redis->seed('products:all', '[]');

        $this->callCreate(['name' => 'Bad', 'price' => 0]);

        self::assertSame([], $this->redis->callsTo('del'));
        self::assertSame('[]', $this->redis->stored('products:all'));
    }

    #[Test]
    public function createRespondsWith404FromGetWhenTheInsertedRowCannotBeRead(): void
    {
        $this->pdo->onSql(self::SQL_INSERT)->insertsId(11);

        [$status, $body] = $this->callCreate(['name' => 'Ghost', 'price' => 1]);

        self::assertSame(404, $status);
        self::assertSame('{"error":"Product not found"}', $body);
    }

    // ------------------------------------------------------------------
    // update()
    // ------------------------------------------------------------------

    #[Test]
    public function updateOfAnUnknownProductRespondsWith404BeforeLookingAtTheBody(): void
    {
        [$status, $body] = $this->callUpdate(99, ['name' => 'X']);

        self::assertSame(404, $status);
        self::assertSame('{"error":"Product not found"}', $body);
        self::assertSame([self::SQL_EXISTS], $this->executedSql());
        self::assertSame([[99]], $this->pdo->paramsOf(self::SQL_EXISTS));
        self::assertSame([], $this->redis->calls());
    }

    #[Test]
    public function updateOfAnUnknownProductWithAnEmptyBodyIsStill404(): void
    {
        [$status, $body] = $this->callUpdate(99, []);

        self::assertSame(404, $status);
        self::assertSame('{"error":"Product not found"}', $body);
    }

    /** @return array<string, array{0: array<mixed>|string}> */
    public static function emptyUpdateBodies(): array
    {
        return [
            'empty object' => [[]],
            'unknown fields only' => [['color' => 'red']],
            'null values only' => [['name' => null, 'price' => null]],
            'invalid json' => ['not json'],
            'empty body' => [''],
        ];
    }

    /** @param array<mixed>|string $body */
    #[Test]
    #[DataProvider('emptyUpdateBodies')]
    public function updateWithoutUpdatableFieldsRespondsWith400AfterTheExistenceCheck(array|string $body): void
    {
        $this->givenProduct(1);

        [$status, $response] = $this->callUpdate(1, $body);

        self::assertSame(400, $status);
        self::assertSame('{"error":"No fields to update"}', $response);
        self::assertSame([self::SQL_EXISTS], $this->executedSql());
        self::assertSame([], $this->redis->calls());
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string, 2: list<mixed>}> */
    public static function partialUpdates(): array
    {
        return [
            'name only' => [['name' => 'New'], 'UPDATE products SET name = ? WHERE id = ?', ['New', 3]],
            'description only' => [['description' => 'Desc'], 'UPDATE products SET description = ? WHERE id = ?', ['Desc', 3]],
            'price only' => [['price' => 9.5], 'UPDATE products SET price = ? WHERE id = ?', [9.5, 3]],
            'stock only' => [['stock' => 4], 'UPDATE products SET stock = ? WHERE id = ?', [4, 3]],
            'name and price' => [['name' => 'N', 'price' => 2], 'UPDATE products SET name = ?, price = ? WHERE id = ?', ['N', 2, 3]],
            'all fields' => [
                ['name' => 'N', 'description' => 'D', 'price' => 2, 'stock' => 1],
                'UPDATE products SET name = ?, description = ?, price = ?, stock = ? WHERE id = ?',
                ['N', 'D', 2, 1, 3],
            ],
            'field order ignores body order' => [
                ['stock' => 1, 'price' => 2, 'description' => 'D', 'name' => 'N'],
                'UPDATE products SET name = ?, description = ?, price = ?, stock = ? WHERE id = ?',
                ['N', 'D', 2, 1, 3],
            ],
            'unknown fields are ignored' => [
                ['id' => 77, 'color' => 'red', 'stock' => 5],
                'UPDATE products SET stock = ? WHERE id = ?',
                [5, 3],
            ],
            'zero stock is set' => [['stock' => 0], 'UPDATE products SET stock = ? WHERE id = ?', [0, 3]],
            'empty string name is set' => [['name' => ''], 'UPDATE products SET name = ? WHERE id = ?', ['', 3]],
        ];
    }

    /**
     * @param array<string, mixed> $body
     * @param list<mixed>          $params
     */
    #[Test]
    #[DataProvider('partialUpdates')]
    public function updateBuildsTheSqlFromThePresentFieldsOnly(array $body, string $sql, array $params): void
    {
        $this->givenProduct(3);

        $this->callUpdate(3, $body);

        $updates = $this->pdo->executions(self::SQL_UPDATE_PREFIX);
        self::assertCount(1, $updates);
        self::assertSame($sql, $updates[0]['sql']);
        self::assertSame($params, $updates[0]['params']);
    }

    #[Test]
    public function updateBindsTheRawBodyValuesWithoutCasting(): void
    {
        $this->givenProduct(3);

        $this->callUpdate(3, ['price' => '12.50', 'stock' => '4']);

        self::assertSame([['12.50', '4', 3]], $this->pdo->paramsOf(self::SQL_UPDATE_PREFIX));
    }

    #[Test]
    public function updateInvalidatesTheProductAndListCaches(): void
    {
        $this->givenProduct(3);
        $this->redis->seed('products:3', '{"stale":true}');
        $this->redis->seed('products:all', '[{"stale":true}]');

        $this->callUpdate(3, ['name' => 'Fresh']);

        self::assertSame([['products:3'], ['products:all']], $this->redis->callsTo('del'));
    }

    #[Test]
    public function updateRespondsThroughGetWithStatus200AndTheFreshRow(): void
    {
        $fresh = ['id' => 3, 'name' => 'Fresh', 'description' => '', 'price' => '1.00', 'stock' => 1];
        $this->givenProduct(3, $fresh);
        $this->redis->seed('products:3', '{"stale":true}');

        [$status, $body] = $this->callUpdate(3, ['name' => 'Fresh']);

        self::assertSame(200, $status);
        self::assertSame(json_encode($fresh), $body);
        self::assertSame([['products:3', 60, json_encode($fresh)]], $this->redis->callsTo('setex'));
    }

    #[Test]
    public function updateRunsExistenceCheckThenUpdateThenReadInThatOrder(): void
    {
        $this->givenProduct(3);

        $this->callUpdate(3, ['stock' => 2]);

        self::assertSame(
            [
                self::SQL_EXISTS,
                'UPDATE products SET stock = ? WHERE id = ?',
                self::SQL_SELECT,
            ],
            $this->executedSql()
        );
    }

    #[Test]
    public function updateDoesNotRunTheUpdateWhenThereAreNoFields(): void
    {
        $this->givenProduct(3);

        $this->callUpdate(3, []);

        self::assertSame([], $this->pdo->executions(self::SQL_UPDATE_PREFIX));
    }

    // ------------------------------------------------------------------
    // delete()
    // ------------------------------------------------------------------

    #[Test]
    public function deleteRespondsWith204WithAnEmptyBodyAndInvalidatesBothKeys(): void
    {
        $this->pdo->onSql(self::SQL_DELETE)->withParams([4])->affects(1);
        $this->redis->seed('products:4', '{"id":4}');
        $this->redis->seed('products:all', '[]');

        [$status, $body] = $this->callDelete(4);

        self::assertSame(204, $status);
        self::assertSame('', $body);
        self::assertSame([['products:4'], ['products:all']], $this->redis->callsTo('del'));
        self::assertNull($this->redis->stored('products:4'));
        self::assertNull($this->redis->stored('products:all'));
        self::assertSame([[4]], $this->pdo->paramsOf(self::SQL_DELETE));
    }

    #[Test]
    public function deleteOfAnUnknownProductRespondsWith404AndKeepsTheCaches(): void
    {
        $this->pdo->onSql(self::SQL_DELETE)->affects(0);
        $this->redis->seed('products:all', '[]');

        [$status, $body] = $this->callDelete(99);

        self::assertSame(404, $status);
        self::assertSame('{"error":"Product not found"}', $body);
        self::assertSame([], $this->redis->callsTo('del'));
        self::assertSame('[]', $this->redis->stored('products:all'));
    }

    #[Test]
    public function deleteWithoutAnyScriptedRuleCountsAsNothingDeleted(): void
    {
        [$status, $body] = $this->callDelete(1);

        self::assertSame(404, $status);
        self::assertSame('{"error":"Product not found"}', $body);
        self::assertSame([self::SQL_DELETE], $this->executedSql());
    }

    #[Test]
    public function deleteOfAProductReferencedByOrdersRespondsWith409(): void
    {
        $this->pdo->onSql(self::SQL_DELETE)->throws(ScriptedPdo::sqlStateException('23000', 'FK constraint'));
        $this->redis->seed('products:all', '[]');

        [$status, $body] = $this->callDelete(1);

        self::assertSame(409, $status);
        self::assertSame('{"error":"Cannot delete product that is referenced by orders"}', $body);
        self::assertSame([], $this->redis->callsTo('del'));
        self::assertSame('[]', $this->redis->stored('products:all'));
    }

    /** @return array<string, array{0: string}> */
    public static function otherSqlStates(): array
    {
        return [
            'base table not found' => ['42S02'],
            'general error' => ['HY000'],
            'connection failure' => ['08S01'],
            'deadlock' => ['40001'],
        ];
    }

    #[Test]
    #[DataProvider('otherSqlStates')]
    public function deleteRethrowsPdoExceptionsThatAreNotIntegrityViolations(string $sqlState): void
    {
        $failure = ScriptedPdo::sqlStateException($sqlState, 'boom');
        $this->pdo->onSql(self::SQL_DELETE)->throws($failure);
        $this->redis->seed('products:all', '[]');
        $controller = $this->controller();

        try {
            $this->capture(static function () use ($controller): void {
                $controller->delete(1);
            });
            self::fail('The PDOException must propagate out of delete().');
        } catch (PDOException $caught) {
            self::assertSame($failure, $caught);
        }

        self::assertSame([], $this->redis->callsTo('del'));
        self::assertSame('[]', $this->redis->stored('products:all'));
    }

    #[Test]
    public function deleteDoesNotTreatAnIntegerCodeAs23000(): void
    {
        $failure = new PDOException('numeric code', 23000);
        $this->pdo->onSql(self::SQL_DELETE)->throws($failure);
        $controller = $this->controller();

        $this->expectExceptionObject($failure);

        $this->capture(static function () use ($controller): void {
            $controller->delete(1);
        });
    }

    // ------------------------------------------------------------------
    // Construction and parameter binding
    // ------------------------------------------------------------------

    #[Test]
    public function constructingTheControllerPerformsNoIo(): void
    {
        new ProductController($this->pdo, $this->redis);

        self::assertSame([], $this->pdo->executed());
        self::assertSame([], $this->pdo->transactionEvents());
        self::assertSame([], $this->redis->calls());
        self::assertSame(0, $this->amqp->channelsOpened());
    }

    #[Test]
    public function actionsOnlyTouchTheInjectedCollaborators(): void
    {
        $this->pdo->onSql(self::SQL_LIST)->fetchesRows([]);

        $this->callList();

        self::assertSame([], $this->pdo->transactionEvents());
        self::assertSame(0, $this->amqp->channelsOpened());
        self::assertSame([], $this->amqp->published());
    }

    #[Test]
    public function idsAreBoundAsIntegersOnDirectCalls(): void
    {
        $this->pdo->onSql(self::SQL_DELETE)->affects(1);
        $this->givenProduct(12);

        $this->callGet(12);
        $this->callUpdate(12, ['stock' => 1]);
        $this->callDelete(12);

        self::assertSame([[12], [12]], $this->pdo->paramsOf(self::SQL_SELECT));
        self::assertSame([[12]], $this->pdo->paramsOf(self::SQL_EXISTS));
        self::assertSame([[1, 12]], $this->pdo->paramsOf(self::SQL_UPDATE_PREFIX));
        self::assertSame([[12]], $this->pdo->paramsOf(self::SQL_DELETE));
    }

    #[Test]
    public function cacheKeysAreBuiltFromTheIntegerId(): void
    {
        $this->redis->seed('products:5', '{"id":5}');

        $this->callGet(5);
        $this->callGet(6);

        self::assertSame([['products:5'], ['products:6']], $this->redis->callsTo('get'));
    }

    #[Test]
    public function aFailingDeleteWritesNothingElse(): void
    {
        $this->pdo->onSql(self::SQL_DELETE)->throws(ScriptedPdo::sqlStateException('23000'));

        $this->callDelete(1);

        self::assertSame([self::SQL_DELETE], $this->executedSql());
    }
}
