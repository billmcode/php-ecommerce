#!/bin/bash
set -e

echo "=== PHP Ecommerce Sandbox ==="
echo ""

case "${1:-up}" in
  up)
    echo "Starting all services..."
    docker compose up --build -d
    echo ""
    echo "Waiting for services to be healthy..."
    docker compose ps
    echo ""
    echo "API is running at: http://localhost:8080"
    echo "RabbitMQ Management: http://localhost:15672 (guest/guest)"
    echo ""
    echo "Try it:"
    echo "  curl http://localhost:8080/health"
    echo "  curl http://localhost:8080/api/products"
    ;;
  down)
    echo "Stopping all services..."
    docker compose down
    ;;
  reset)
    echo "Removing all services and data..."
    docker compose down -v
    ;;
  logs)
    docker compose logs -f "${2:-}"
    ;;
  test)
    shift
    echo "Installing dev dependencies and running PHPUnit in the php container..."
    docker compose exec php composer install --no-interaction
    docker compose exec php vendor/bin/phpunit "$@"
    ;;
  *)
    echo "Usage: ./run.sh [up|down|reset|logs [service]|test [phpunit args]]"
    ;;
esac
