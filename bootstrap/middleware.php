<?php

declare(strict_types=1);

use Psr\Container\ContainerInterface;
use App\Http\Middleware\ErrorHandlerMiddleware;
use App\Http\Middleware\RequestLoggerMiddleware;

/** @var \Slim\App $app */
/** @var ContainerInterface $container */
$app->add($container->get(ErrorHandlerMiddleware::class));
$app->add($container->get(RequestLoggerMiddleware::class));
