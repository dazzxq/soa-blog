<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Controllers\AggregateController;
use App\Controllers\AuthController;
use App\Controllers\ConnectionsController;
use App\Controllers\FeedController;
use App\Controllers\HealthController;
use App\Controllers\ProfilesController;
use App\Controllers\SearchController;
use App\JsonErrorHandler;
use App\Middleware\CorsMiddleware;
use App\Middleware\JwtAuthMiddleware;
use App\Middleware\OptionalJwtMiddleware;
use App\Middleware\LoggingMiddleware;
use App\Middleware\RateLimitMiddleware;
use App\Middleware\RequestIdMiddleware;
use App\Services\ConnectionClient;
use App\Services\FeedClient;
use App\Services\NotificationClient;
use App\Services\ProfileClient;
use App\Services\SearchClient;
use DI\Container;
use Slim\Factory\AppFactory;

$jwtSecret = (string) (getenv('JWT_SECRET') ?: '');
$rateLimit = (int)    (getenv('RATE_LIMIT_PER_MIN') ?: 120);
if (strlen($jwtSecret) < 16) {
    fwrite(STDERR, "FATAL: JWT_SECRET must be set (>=16 chars)\n");
    http_response_code(500);
    echo json_encode([
        'error' => ['code' => 'CONFIG_ERROR', 'message' => 'Server chưa được cấu hình đúng.'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// --- Container & singletons ---
$container = new Container();

$container->set(ProfileClient::class,      fn() => new ProfileClient());
$container->set(ConnectionClient::class,   fn() => new ConnectionClient());
$container->set(FeedClient::class,         fn() => new FeedClient());
$container->set(SearchClient::class,       fn() => new SearchClient());
$container->set(NotificationClient::class, fn() => new NotificationClient());

$container->set(AuthController::class, fn(Container $c) => new AuthController(
    $c->get(ProfileClient::class),
    $jwtSecret,
));
$container->set(HealthController::class, fn(Container $c) => new HealthController(
    $c->get(ProfileClient::class),
    $c->get(ConnectionClient::class),
    $c->get(FeedClient::class),
    $c->get(SearchClient::class),
    $c->get(NotificationClient::class),
));
$container->set(ProfilesController::class, fn(Container $c) => new ProfilesController($c->get(ProfileClient::class)));
$container->set(AggregateController::class, fn(Container $c) => new AggregateController(
    $c->get(ProfileClient::class),
    $c->get(ConnectionClient::class),
));
$container->set(ConnectionsController::class, fn(Container $c) => new ConnectionsController(
    $c->get(ProfileClient::class),
    $c->get(ConnectionClient::class),
));
$container->set(FeedController::class, fn(Container $c) => new FeedController(
    $c->get(FeedClient::class),
    $c->get(ProfileClient::class),
    $c->get(ConnectionClient::class),
));
$container->set(SearchController::class, fn(Container $c) => new SearchController(
    $c->get(SearchClient::class),
    $c->get(ProfileClient::class),
    $c->get(ConnectionClient::class),
));
$container->set(JwtAuthMiddleware::class, fn() => new JwtAuthMiddleware($jwtSecret));
$container->set(OptionalJwtMiddleware::class, fn() => new OptionalJwtMiddleware($jwtSecret));

AppFactory::setContainer($container);
$app = AppFactory::create();

$app->addBodyParsingMiddleware();

// Slim runs middlewares LIFO. First add = innermost.
// Order request flow (outer→inner): logging → CORS → rate-limit → request-id → routing
$app->add(new LoggingMiddleware());
$app->add(new CorsMiddleware());
$app->add(new RateLimitMiddleware($rateLimit));
$app->add(new RequestIdMiddleware());

$app->addRoutingMiddleware();

$errorMw = $app->addErrorMiddleware(
    getenv('APP_DEBUG') === '1',
    true,
    true,
);
$errorMw->setDefaultErrorHandler(new JsonErrorHandler(
    $app->getCallableResolver(),
    $app->getResponseFactory(),
));

(require __DIR__ . '/../src/routes.php')($app);

$app->run();
