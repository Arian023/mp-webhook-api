<?php

declare(strict_types=1);

use App\Http\Controllers\HealthController;
use App\Http\Controllers\IntentController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\WebhookController;
use App\Http\Middleware\BearerAuthMiddleware;
use App\Http\Middleware\ErrorHandlerMiddleware;
use App\Http\Middleware\RequestLoggerMiddleware;
use Slim\Factory\AppFactory;
use Slim\Routing\RouteCollectorProxy;

require __DIR__ . '/../vendor/autoload.php';

$dotenv = new Symfony\Component\Dotenv\Dotenv();
$dotenv->loadEnv(__DIR__ . '/../.env');

$containerBuilder = new \DI\ContainerBuilder();
$containerBuilder->addDefinitions(require __DIR__ . '/../bootstrap/container.php');
$container = $containerBuilder->build();

AppFactory::setContainer($container);
$app = AppFactory::create();

require __DIR__ . '/../bootstrap/middleware.php';
require __DIR__ . '/../bootstrap/routes.php';

$app->run();
