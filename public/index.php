<?php

declare(strict_types=1);

use Symfony\Component\Dotenv\Dotenv;
use DI\ContainerBuilder;
use Slim\Factory\AppFactory;

require __DIR__ . '/../vendor/autoload.php';

$dotenv = new Dotenv();
$dotenv->loadEnv(__DIR__ . '/../.env');

$containerBuilder = new ContainerBuilder();
$containerBuilder->addDefinitions(require __DIR__ . '/../bootstrap/container.php');
$container = $containerBuilder->build();

AppFactory::setContainer($container);
$app = AppFactory::create();

require __DIR__ . '/../bootstrap/middleware.php';
require __DIR__ . '/../bootstrap/routes.php';

$app->run();
