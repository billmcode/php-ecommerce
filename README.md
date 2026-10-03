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
./run.sh test     # install dev dependencies and run the PHPUnit suite
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
├── entrypoint.sh           # composer install (--no-dev) at startup
├── run.sh                  # convenience shell runner (up/down/reset/logs/test)
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
    │   ├── Router.php      # method/path matching and delegation only
    │   └── Controllers/
    │       ├── ProductController.php  # list, get, create, update, delete
    │       ├── OrderController.php    # list, get, create (+ order_created publish)
    │       └── HealthController.php   # GET /health
    └── tests/
        ├── Support/        # test doubles: Predis fake, scripted PDO, AMQP/publisher spies, php://input wrapper
        ├── Characterization/  # tests through Router that pin the HTTP contract
        ├── Controllers/    # controller-level unit tests
        ├── Integration/    # opt-in tests against the live compose stack
        └── RouterTest.php  # dispatch tests
```

## Running tests

The PHPUnit suite needs no live MySQL, Redis or RabbitMQ: it uses in-memory doubles for all three.

`entrypoint.sh` installs dependencies with `--no-dev`, and `./src` is bind-mounted into the container, so PHPUnit is removed whenever the `php` container restarts. Reinstall the dev dependencies before running the tests:

```bash
docker compose exec php composer install
docker compose exec php vendor/bin/phpunit      # or: docker compose exec php composer test
```

`./run.sh test` does both steps; extra arguments are passed to PHPUnit (e.g. `./run.sh test --filter RouterTest`).

With a local PHP >= 8.2 and Composer you can also run the suite directly from `src/`:

```bash
cd src && composer install && vendor/bin/phpunit
```

The `integration` group calls the live API at `http://localhost:8080` (override with `API_BASE_URL`; inside the compose network the API is `http://nginx`), skips itself when the stack is not reachable, and is excluded from the default run:

```bash
docker compose exec -e API_BASE_URL=http://nginx php vendor/bin/phpunit --group integration
```

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
