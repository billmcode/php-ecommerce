<?php
/**
 * Router script for `php -S`. Boots the REAL, unmodified src/public/index.php
 * (front controller -> App\Router -> controllers). The only substitution is the
 * Database and Queue singletons, which are pre-seeded with stand-ins because
 * MySQL and RabbitMQ are unavailable. Cache::connect() is untouched and talks
 * real Predis over TCP to the fake Redis server started by pytest.
 */

$appRoot = realpath(__DIR__ . '/../../../../src');
require $appRoot . '/vendor/autoload.php';
require __DIR__ . '/fakes.php';

FtHarness\State::load(getenv('FT_STATE_FILE'));

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/__ft/ping') {
    header('Content-Type: text/plain');
    echo 'pong';
    return;
}

$pdo = new FtHarness\FtPdo();

$seed = function (string $class, object $value): void {
    $p = new ReflectionProperty($class, 'instance');
    $p->setAccessible(true);
    $p->setValue(null, $value);
};
$seed(App\Database::class, $pdo);
$seed(App\Queue::class, new FtHarness\FtAmqp());

register_shutdown_function(function () use ($pdo) {
    $pdo->rollBackOnShutdown();
    FtHarness\State::save();
});

require $appRoot . '/public/index.php';
