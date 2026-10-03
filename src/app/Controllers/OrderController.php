<?php

namespace App\Controllers;

use App\Queue;
use PDO;
use Predis\Client as RedisClient;

class OrderController
{
    private PDO $db;
    private RedisClient $cache;
    private \Closure $publisher;
    private \Closure $inputReader;

    /**
     * @param callable|null $publisher   receives (string $queueName, array $data); defaults to Queue::publish
     * @param callable|null $inputReader returns the raw request body; defaults to php://input
     */
    public function __construct(
        PDO $db,
        RedisClient $cache,
        ?callable $publisher = null,
        ?callable $inputReader = null
    ) {
        $this->db = $db;
        $this->cache = $cache;
        $this->publisher = \Closure::fromCallable($publisher ?? [Queue::class, 'publish']);
        $this->inputReader = \Closure::fromCallable(
            $inputReader ?? fn() => file_get_contents('php://input')
        );
    }

    public function list(): void
    {
        $stmt = $this->db->query('SELECT * FROM orders ORDER BY id DESC');
        echo json_encode($stmt->fetchAll());
    }

    public function get(int $id): void
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

    public function create(): void
    {
        $data = json_decode(($this->inputReader)(), true);

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
            ($this->publisher)('order_created', [
                'order_id' => $orderId,
                'total' => $total,
                'created_at' => date('c'),
            ]);

            http_response_code(201);
            $this->get($orderId);
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
