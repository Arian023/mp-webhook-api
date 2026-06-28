<?php

declare(strict_types=1);

use App\Http\Middleware\ErrorHandlerMiddleware;
use App\Http\Middleware\RequestLoggerMiddleware;

/** @var \Slim\App $app */
/** @var \Psr\Container\ContainerInterface $container */

$app->add($container->get(ErrorHandlerMiddleware::class));
$app->add($container->get(RequestLoggerMiddleware::class));
