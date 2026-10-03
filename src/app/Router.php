<?php

namespace App;

use App\Controllers\ProductController;
use PDO;
use Predis\Client as RedisClient;
use PhpAmqpLib\Connection\AMQPStreamConnection;

class Router
{
    private PDO $db;
    private RedisClient $cache;
    private AMQPStreamConnection $queue;
    private ProductController $products;

    public function __construct(PDO $db, RedisClient $cache, AMQPStreamConnection $queue)
    {
        $this->db = $db;
        $this->cache = $cache;
        $this->queue = $queue;
        $this->products = new ProductController($db, $cache);
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
                $this->health();
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
                $this->listOrders();
                break;
            case preg_match('#^/api/orders/(\d+)$#', $uri, $m) && $method === 'GET':
                $this->getOrder((int) $m[1]);
                break;
            case $uri === '/api/orders' && $method === 'POST':
                $this->createOrder();
                break;

            default:
                http_response_code(404);
                echo json_encode(['error' => 'Not found']);
        }
    }

    // --- Health ---

    private function health(): void
    {
        $status = ['status' => 'ok', 'services' => []];

        // MySQL
        try {
            $this->db->query('SELECT 1');
            $status['services']['mysql'] = 'connected';
        } catch (\Exception $e) {
            $status['services']['mysql'] = 'error';
            $status['status'] = 'degraded';
        }

        // Redis
        try {
            $this->cache->ping();
            $status['services']['redis'] = 'connected';
        } catch (\Exception $e) {
            $status['services']['redis'] = 'error';
            $status['status'] = 'degraded';
        }

        // RabbitMQ
        try {
            $this->queue->isConnected()
                ? $status['services']['rabbitmq'] = 'connected'
                : $status['services']['rabbitmq'] = 'error';
        } catch (\Exception $e) {
            $status['services']['rabbitmq'] = 'error';
            $status['status'] = 'degraded';
        }

        echo json_encode($status);
    }

    // --- Orders ---

    private function listOrders(): void
    {
        $stmt = $this->db->query('SELECT * FROM orders ORDER BY id DESC');
        echo json_encode($stmt->fetchAll());
    }

    private function getOrder(int $id): void
    {
        $stmt = $this->db->prepare('SELECT * FROM orders WHERE id = ?');
        $stmt->execute([$id]);
        $order = $stmt->fetch();

        if (!$order) {
            http_response_code(404);
            echo json_encode(['error' => 'Order not found']);
            return;
        }

        $stmt = $this->db->prepare(
            'SELECT oi.*, p.name as product_name FROM order_items oi
             JOIN products p ON p.id = oi.product_id
             WHERE oi.order_id = ?'
        );
        $stmt->execute([$id]);
        $order['items'] = $stmt->fetchAll();

        echo json_encode($order);
    }

    private function createOrder(): void
    {
        $data = json_decode(file_get_contents('php://input'), true);

        if (!isset($data['items']) || !is_array($data['items']) || empty($data['items'])) {
            http_response_code(400);
            echo json_encode(['error' => 'items array is required']);
            return;
        }

        $this->db->beginTransaction();
        try {
            // Create order
            $this->db->prepare('INSERT INTO orders (total) VALUES (0)')->execute();
            $orderId = (int) $this->db->lastInsertId();

            $total = 0;
            $productIds = [];
            foreach ($data['items'] as $item) {
                if (!isset($item['product_id'], $item['quantity'])) {
                    throw new \InvalidArgumentException('Each item needs product_id and quantity');
                }

                if ((int) $item['quantity'] < 1) {
                    throw new \InvalidArgumentException('Quantity must be at least 1');
                }

                // Get product and check stock
                $stmt = $this->db->prepare('SELECT * FROM products WHERE id = ? FOR UPDATE');
                $stmt->execute([$item['product_id']]);
                $product = $stmt->fetch();

                if (!$product) {
                    throw new \InvalidArgumentException("Product {$item['product_id']} not found");
                }
                if ($product['stock'] < $item['quantity']) {
                    throw new \InvalidArgumentException("Insufficient stock for {$product['name']}");
                }

                // Add order item
                $lineTotal = (float) $product['price'] * (int) $item['quantity'];
                $stmt = $this->db->prepare(
                    'INSERT INTO order_items (order_id, product_id, quantity, price) VALUES (?, ?, ?, ?)'
                );
                $stmt->execute([$orderId, $item['product_id'], $item['quantity'], $product['price']]);

                // Decrease stock
                $this->db->prepare('UPDATE products SET stock = stock - ? WHERE id = ?')
                    ->execute([$item['quantity'], $item['product_id']]);

                $productIds[] = $item['product_id'];
                $total += $lineTotal;
            }

            // Update order total
            $this->db->prepare('UPDATE orders SET total = ? WHERE id = ?')
                ->execute([$total, $orderId]);

            $this->db->commit();

            // Invalidate product caches
            foreach ($productIds as $pid) {
                $this->cache->del("products:{$pid}");
            }
            $this->cache->del('products:all');

            // Publish order event to RabbitMQ
            Queue::publish('order_created', [
                'order_id' => $orderId,
                'total' => $total,
                'created_at' => date('c'),
            ]);

            http_response_code(201);
            $this->getOrder($orderId);
        } catch (\InvalidArgumentException $e) {
            $this->db->rollBack();
            http_response_code(400);
            echo json_encode(['error' => $e->getMessage()]);
        } catch (\Exception $e) {
            $this->db->rollBack();
            http_response_code(500);
            echo json_encode(['error' => 'Order creation failed']);
        }
    }
}
