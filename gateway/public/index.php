<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Controllers\AggregateController;
use App\Controllers\AuthController;
use App\Controllers\HealthController;
use App\Controllers\PostsController;
use App\Controllers\UsersController;
use App\JsonErrorHandler;
use App\Middleware\CorsMiddleware;
use App\Middleware\JwtAuthMiddleware;
use App\Middleware\LoggingMiddleware;
use App\Middleware\RateLimitMiddleware;
use App\Middleware\RequestIdMiddleware;
use App\Services\CommentClient;
use App\Services\PostClient;
use App\Services\UserClient;
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

$container->set(UserClient::class,    fn() => new UserClient());
$container->set(PostClient::class,    fn() => new PostClient());
$container->set(CommentClient::class, fn() => new CommentClient());

$container->set(AuthController::class, fn(Container $c) => new AuthController(
    $c->get(UserClient::class),
    $jwtSecret,
));
$container->set(HealthController::class, fn(Container $c) => new HealthController(
    $c->get(UserClient::class),
    $c->get(PostClient::class),
    $c->get(CommentClient::class),
));
$container->set(UsersController::class, fn(Container $c) => new UsersController($c->get(UserClient::class)));
$container->set(PostsController::class, fn(Container $c) => new PostsController(
    $c->get(PostClient::class),
    $c->get(UserClient::class),
    $c->get(CommentClient::class),
));
$container->set(AggregateController::class, fn(Container $c) => new AggregateController(
    $c->get(PostClient::class),
    $c->get(UserClient::class),
    $c->get(CommentClient::class),
));
$container->set(JwtAuthMiddleware::class, fn() => new JwtAuthMiddleware($jwtSecret));

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
