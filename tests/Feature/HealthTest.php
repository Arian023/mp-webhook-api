<?php

declare(strict_types=1);

use App\Http\Controllers\HealthController;
use App\Infrastructure\Database;
use Medoo\Medoo;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

it('returns ok status when database is connected', function (): void {
    $medoo = $this->createMock(Medoo::class);
    $stmt = $this->createMock(PDOStatement::class);
    $medoo->method('query')->willReturn($stmt);

    $db = $this->createMock(Database::class);
    $db->method('getConnection')->willReturn($medoo);

    $controller = new HealthController($db, new NullLogger());
    $request = (new ServerRequestFactory())->createServerRequest('GET', '/health');
    $response = (new ResponseFactory())->createResponse();

    $result = $controller->check($request, $response);

    $body = json_decode((string) $result->getBody(), true);
    expect($result->getStatusCode())->toBe(200);
    expect($body['status'])->toBe('ok');
    expect($body['database'])->toBe('connected');
});

it('returns error status when database is disconnected', function (): void {
    $medoo = $this->createMock(Medoo::class);
    $medoo->method('query')
        ->willThrowException(new RuntimeException('Connection failed'));

    $db = $this->createMock(Database::class);
    $db->method('getConnection')->willReturn($medoo);

    $controller = new HealthController($db, new NullLogger());
    $request = (new ServerRequestFactory())->createServerRequest('GET', '/health');
    $response = (new ResponseFactory())->createResponse();

    $result = $controller->check($request, $response);

    $body = json_decode((string) $result->getBody(), true);
    expect($result->getStatusCode())->toBe(500);
    expect($body['status'])->toBe('error');
    expect($body['database'])->toBe('disconnected');
});
