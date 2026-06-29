<?php

declare(strict_types=1);

it('returns ok status', function (): void {
    $response = $this->get('/health');

    $response->assertStatus(200);
    $data = json_decode($response->getBody()->getContents(), true);
    expect($data['status'])->toBe('ok');
    expect($data['database'])->toBe('connected');
});
