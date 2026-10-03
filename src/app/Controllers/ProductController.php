<?php

namespace App\Controllers;

use PDO;
use Predis\Client as RedisClient;

class ProductController
{
    private PDO $db;
    private RedisClient $cache;

    public function __construct(PDO $db, RedisClient $cache)
    {
        $this->db = $db;
        $this->cache = $cache;
    }

    public function list(): void
    {
        // Try cache first
        $cached = $this->cache->get('products:all');
        if ($cached) {
            echo $cached;
            return;
        }

        $stmt = $this->db->query('SELECT * FROM products ORDER BY id');
        $products = $stmt->fetchAll();

        $json = json_encode($products);
        $this->cache->setex('products:all', 60, $json);
        echo $json;
    }

    public function get(int $id): void
    {
        $cached = $this->cache->get("products:{$id}");
        if ($cached) {
            echo $cached;
            return;
        }

        $stmt = $this->db->prepare('SELECT * FROM products WHERE id = ?');
        $stmt->execute([$id]);
        $product = $stmt->fetch();

        if (!$product) {
            http_response_code(404);
            echo json_encode(['error' => 'Product not found']);
            return;
        }

        $json = json_encode($product);
        $this->cache->setex("products:{$id}", 60, $json);
        echo $json;
    }

    public function create(): void
    {
        $data = json_decode(file_get_contents('php://input'), true);

        if (!isset($data['name'], $data['price'])) {
            http_response_code(400);
            echo json_encode(['error' => 'name and price are required']);
            return;
        }

        if ((float) $data['price'] <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'price must be greater than zero']);
            return;
        }

        if (isset($data['stock']) && (int) $data['stock'] < 0) {
            http_response_code(400);
            echo json_encode(['error' => 'stock cannot be negative']);
            return;
        }

        $stmt = $this->db->prepare(
            'INSERT INTO products (name, description, price, stock) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([
            $data['name'],
            $data['description'] ?? '',
            (float) $data['price'],
            (int) ($data['stock'] ?? 0),
        ]);

        $id = (int) $this->db->lastInsertId();
        $this->cache->del('products:all');

        http_response_code(201);
        $this->get($id);
    }

    public function update(int $id): void
    {
        $data = json_decode(file_get_contents('php://input'), true);

        $stmt = $this->db->prepare('SELECT id FROM products WHERE id = ?');
        $stmt->execute([$id]);
        if (!$stmt->fetch()) {
            http_response_code(404);
            echo json_encode(['error' => 'Product not found']);
            return;
        }

        $fields = [];
        $values = [];
        foreach (['name', 'description', 'price', 'stock'] as $field) {
            if (isset($data[$field])) {
                $fields[] = "{$field} = ?";
                $values[] = $data[$field];
            }
        }

        if (empty($fields)) {
            http_response_code(400);
            echo json_encode(['error' => 'No fields to update']);
            return;
        }

        $values[] = $id;
        $sql = 'UPDATE products SET ' . implode(', ', $fields) . ' WHERE id = ?';
        $this->db->prepare($sql)->execute($values);

        $this->cache->del("products:{$id}");
        $this->cache->del('products:all');

        $this->get($id);
    }

    public function delete(int $id): void
    {
        try {
            $stmt = $this->db->prepare('DELETE FROM products WHERE id = ?');
            $stmt->execute([$id]);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                http_response_code(409);
                echo json_encode(['error' => 'Cannot delete product that is referenced by orders']);
                return;
            }
            throw $e;
        }

        if ($stmt->rowCount() === 0) {
            http_response_code(404);
            echo json_encode(['error' => 'Product not found']);
            return;
        }

        $this->cache->del("products:{$id}");
        $this->cache->del('products:all');

        http_response_code(204);
    }
}
