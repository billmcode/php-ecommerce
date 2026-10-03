# PHP Ecommerce Sandbox

A simple PHP REST API for ecommerce, backed by MySQL, Redis, and RabbitMQ. Runs entirely via Docker Compose.

## Architecture

```
Client  -->  Nginx (:8080)  -->  PHP-FPM  -->  MySQL (data)
                                           -->  Redis (cache)
                                           -->  RabbitMQ (events)
```

- **Nginx** reverse-proxies to PHP-FPM
- **MySQL 8.0** stores products, orders, and order items
- **Redis 7** caches product listings and individual products (60s TTL)
- **RabbitMQ 3** receives order-created events for async processing

Services start in dependency order via health checks: MySQL, Redis, and RabbitMQ must be healthy before the PHP container starts.

## Prerequisites

- Docker and Docker Compose

## Quick Start

```bash
./run.sh          # build and start all services
./run.sh down     # stop all services
./run.sh reset    # stop and remove all data
./run.sh logs     # tail all logs
./run.sh logs php # tail PHP logs only
```

Wait for `ready to handle connections` in the PHP logs before sending requests:

```bash
docker logs -f ecommerce-php
```

## API Endpoints

### Health

```
GET /health
```

Returns connection status for all backing services.

### Products

```
GET    /api/products          # list all (cached 60s)
GET    /api/products/:id      # get one (cached 60s)
POST   /api/products          # create
PUT    /api/products/:id      # update
DELETE /api/products/:id      # delete
```

**Create / Update body:**

```json
{
  "name": "Widget",
  "description": "A fine widget",
  "price": 19.99,
  "stock": 100
}
```

### Orders

```
GET  /api/orders          # list all
GET  /api/orders/:id      # get one (includes line items)
POST /api/orders          # create
```

**Create body:**

```json
{
  "items": [
    { "product_id": 1, "quantity": 2 },
    { "product_id": 3, "quantity": 1 }
  ]
}
```

Order creation validates stock, decrements inventory, calculates totals, and publishes an `order_created` event to RabbitMQ.

## Example Session

```bash
# Check services
curl http://localhost:8080/health

# Browse products (5 seeded)
curl http://localhost:8080/api/products

# Place an order
curl -X POST http://localhost:8080/api/orders \
  -H 'Content-Type: application/json' \
  -d '{"items":[{"product_id":1,"quantity":2}]}'

# Verify stock decreased
curl http://localhost:8080/api/products/1
```

## Project Structure

```
.
├── docker-compose.yml      # service definitions
├── Dockerfile              # PHP 8.2-FPM + extensions
├── entrypoint.sh           # composer install at startup
├── run.sh                  # convenience shell runner
├── nginx/default.conf      # nginx -> php-fpm proxy
├── db/init.sql             # schema + seed data
└── src/
    ├── composer.json
    ├── phpunit.xml.dist    # PHPUnit configuration
    ├── public/index.php    # entry point
    ├── app/
    │   ├── Database.php    # PDO/MySQL connection
    │   ├── Cache.php       # Redis (Predis) connection
    │   ├── Queue.php       # RabbitMQ publisher
    │   ├── Router.php      # route matching + dispatch only
    │   └── Controllers/
    │       ├── ProductController.php  # product handlers (Redis-cached)
    │       ├── OrderController.php    # order handlers (transactional create + event publish)
    │       └── HealthController.php   # /health probe
    └── tests/
        ├── Support/        # Redis fake, PDO helper, php://input stream, base test case
        └── Unit/           # characterization, controller and Router tests
```

## Running Tests

The unit suite uses PHPUnit 11.5 and doubles MySQL, Redis and RabbitMQ, so no
running services are needed. From `src/`:

```bash
composer install       # installs dev dependencies (PHPUnit)
vendor/bin/phpunit     # or: composer test
```

Or inside the PHP container (the entrypoint installs with `--no-dev`, so install
dev dependencies first):

```bash
docker compose run --rm php sh -c "composer install && vendor/bin/phpunit"
```

Code coverage needs a coverage driver (pcov or Xdebug) in a development
environment, for example `vendor/bin/phpunit --coverage-text`. The production
image does not include one.

## Ports

| Service           | Port  |
| ----------------- | ----- |
| API (Nginx)       | 8080  |
| MySQL             | 3306  |
| Redis             | 6379  |
| RabbitMQ          | 5672  |
| RabbitMQ Management | 15672 |

RabbitMQ Management UI: http://localhost:15672 (guest / guest)

## License

MIT
