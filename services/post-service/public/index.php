<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Slim\Factory\AppFactory;

$app = AppFactory::create();
$app->addBodyParsingMiddleware();

// Errors → JSON
$errorMiddleware = $app->addErrorMiddleware(
    (bool) ($_ENV['APP_DEBUG'] ?? false),
    true,
    true
);
$errorMiddleware->setDefaultErrorHandler(new App\JsonErrorHandler(
    $app->getCallableResolver(),
    $app->getResponseFactory()
));

(require __DIR__ . '/../src/routes.php')($app);

$app->run();
