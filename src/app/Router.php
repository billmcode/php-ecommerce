<?php

namespace App;

use App\Controllers\HealthController;
use App\Controllers\OrderController;
use App\Controllers\ProductController;
use PDO;
use Predis\Client as RedisClient;
use PhpAmqpLib\Connection\AMQPStreamConnection;

class Router
{
    private ProductController $products;
    private OrderController $orders;
    private HealthController $health;

    public function __construct(PDO $db, RedisClient $cache, AMQPStreamConnection $queue)
    {
        $this->products = new ProductController($db, $cache);
        $this->orders = new OrderController($db, $cache);
        $this->health = new HealthController($db, $cache, $queue);
    }

    public function handleRequest(): void
    {
        $method = $_SERVER['REQUEST_METHOD'];
        $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $uri = rtrim($uri, '/');

        // Route matching
        switch (true) {
            // Health check
            case $uri === '/health' && $method === 'GET':
                $this->health->check();
                break;

            // Products
            case $uri === '/api/products' && $method === 'GET':
                $this->products->list();
                break;
            case preg_match('#^/api/products/(\d+)$#', $uri, $m) && $method === 'GET':
                $this->products->get((int) $m[1]);
                break;
            case $uri === '/api/products' && $method === 'POST':
                $this->products->create();
                break;
            case preg_match('#^/api/products/(\d+)$#', $uri, $m) && $method === 'PUT':
                $this->products->update((int) $m[1]);
                break;
            case preg_match('#^/api/products/(\d+)$#', $uri, $m) && $method === 'DELETE':
                $this->products->delete((int) $m[1]);
                break;

            // Orders
            case $uri === '/api/orders' && $method === 'GET':
                $this->orders->list();
                break;
            case preg_match('#^/api/orders/(\d+)$#', $uri, $m) && $method === 'GET':
                $this->orders->get((int) $m[1]);
                break;
            case $uri === '/api/orders' && $method === 'POST':
                $this->orders->create();
                break;

            default:
                http_response_code(404);
                echo json_encode(['error' => 'Not found']);
        }
    }
}
