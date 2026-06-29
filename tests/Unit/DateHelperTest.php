<?php

declare(strict_types=1);

use App\Infrastructure\Helpers\DateHelper;

it('converts MP timezone to local', function (): void {
    $result = DateHelper::toLocal('2026-06-28T20:39:45.000-04:00');
    expect($result)->toBeString();
    expect($result)->toMatch('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/');
});

it('formats date for database', function (): void {
    $result = DateHelper::formatForDb('2026-06-28T20:39:45.000-04:00');
    expect($result)->toBe('2026-06-28 20:39:45');
});

it('calculates delay in seconds', function (): void {
    $recent = date('Y-m-d H:i:s', strtotime('-10 seconds'));
    $result = DateHelper::calculateDelay($recent);
    expect($result)->toMatch('/\d+(\.\d+)? (seg|min|hs)/');
});

it('calculates delay in minutes', function (): void {
    $recent = date('Y-m-d H:i:s', strtotime('-300 seconds'));
    $result = DateHelper::calculateDelay($recent);
    expect($result)->toMatch('/\d+(\.\d+)? (seg|min|hs)/');
});
