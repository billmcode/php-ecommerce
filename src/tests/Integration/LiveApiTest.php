<?php

namespace Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Opt-in smoke tests against a running stack (docker compose up).
 *
 * Excluded from the default run by phpunit.xml.dist. Run with:
 *   vendor/bin/phpunit --group integration
 * Base URL comes from API_BASE_URL (default http://localhost:8080). When the stack
 * is unreachable every test is skipped, never failed.
 */
#[Group('integration')]
final class LiveApiTest extends TestCase
{
    /** @var array<string, bool> */
    private static array $reachable = [];

    private string $baseUrl;

    protected function setUp(): void
    {
        parent::setUp();

        $configured = getenv('API_BASE_URL');
        $this->baseUrl = rtrim($configured !== false && $configured !== '' ? $configured : 'http://localhost:8080', '/');

        // Probe once per run (and per base URL) so an absent stack costs one connect timeout, not one per test.
        self::$reachable[$this->baseUrl] ??= $this->call('GET', '/health')['status'] !== null;
        if (!self::$reachable[$this->baseUrl]) {
            self::markTestSkipped("Live API not reachable at {$this->baseUrl}; start the stack or set API_BASE_URL.");
        }
    }

    #[Test]
    public function health_reports_the_three_services(): void
    {
        $response = $this->call('GET', '/health');

        self::assertSame(200, $response['status']);
        self::assertIsArray($response['json']);
        self::assertContains($response['json']['status'] ?? null, ['ok', 'degraded']);
        self::assertSame(['mysql', 'redis', 'rabbitmq'], array_keys($response['json']['services'] ?? []));
        foreach ($response['json']['services'] as $state) {
            self::assertContains($state, ['connected', 'error']);
        }
    }

    #[Test]
    public function listing_products_returns_a_json_array(): void
    {
        $response = $this->call('GET', '/api/products');

        self::assertSame(200, $response['status']);
        self::assertIsArray($response['json']);
        self::assertTrue(array_is_list($response['json']));
    }

    #[Test]
    public function an_unknown_route_returns_the_json_404(): void
    {
        $response = $this->call('GET', '/api/does-not-exist');

        self::assertSame(404, $response['status']);
        self::assertSame('{"error":"Not found"}', $response['body']);
    }

    #[Test]
    public function a_product_can_be_created_read_updated_and_deleted(): void
    {
        $created = $this->call('POST', '/api/products', [
            'name' => 'Live test widget',
            'description' => 'created by LiveApiTest',
            'price' => 12.5,
            'stock' => 4,
        ]);
        self::assertSame(201, $created['status'], $created['body']);
        self::assertIsArray($created['json']);
        $id = (int) $created['json']['id'];
        self::assertGreaterThan(0, $id);

        try {
            self::assertSame('Live test widget', $created['json']['name']);
            self::assertEquals(12.5, (float) $created['json']['price']);
            self::assertSame(4, (int) $created['json']['stock']);

            $fetched = $this->call('GET', "/api/products/{$id}");
            self::assertSame(200, $fetched['status']);
            self::assertSame('Live test widget', $fetched['json']['name']);

            $updated = $this->call('PUT', "/api/products/{$id}", ['name' => 'Live test widget v2', 'stock' => 9]);
            self::assertSame(200, $updated['status'], $updated['body']);
            self::assertSame('Live test widget v2', $updated['json']['name']);
            self::assertSame(9, (int) $updated['json']['stock']);

            $refetched = $this->call('GET', "/api/products/{$id}");
            self::assertSame('Live test widget v2', $refetched['json']['name']);
        } finally {
            $deleted = $this->call('DELETE', "/api/products/{$id}");
        }

        self::assertSame(204, $deleted['status']);
        self::assertSame('', $deleted['body']);

        $gone = $this->call('GET', "/api/products/{$id}");
        self::assertSame(404, $gone['status']);
        self::assertSame('{"error":"Product not found"}', $gone['body']);
    }

    #[Test]
    public function an_order_without_items_is_rejected(): void
    {
        $response = $this->call('POST', '/api/orders', ['items' => []]);

        self::assertSame(400, $response['status']);
        self::assertSame('{"error":"items array is required"}', $response['body']);
    }

    /**
     * @param array<mixed>|null $payload
     *
     * @return array{status: int|null, body: string, json: mixed}
     */
    private function call(string $method, string $path, ?array $payload = null): array
    {
        $headers = "Accept: application/json\r\n";
        $http = ['method' => $method, 'ignore_errors' => true, 'timeout' => 5];
        if ($payload !== null) {
            $headers .= "Content-Type: application/json\r\n";
            $http['content'] = json_encode($payload);
        }
        $http['header'] = $headers;

        $body = @file_get_contents($this->baseUrl . $path, false, stream_context_create(['http' => $http]));

        $status = null;
        // PHP 8.5 deprecates the magic $http_response_header local in favour of http_get_last_response_headers().
        $headerLines = function_exists('http_get_last_response_headers')
            ? (http_get_last_response_headers() ?? [])
            : ($http_response_header ?? []);
        foreach ($headerLines as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                $status = (int) $m[1]; // keeps the last status when redirects add several status lines
            }
        }
        if ($body === false) {
            $status = null;
            $body = '';
        }

        return ['status' => $status, 'body' => $body, 'json' => json_decode($body, true)];
    }
}
