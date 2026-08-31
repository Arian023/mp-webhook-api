<?php

declare(strict_types=1);

namespace App\Infrastructure\MercadoPago;

use RuntimeException;

/**
 * Thrown when a Mercado Pago API call fails or returns an error status.
 * Carries the HTTP status code and endpoint for structured error handling.
 */
class MercadoPagoException extends RuntimeException
{
    private ?int $httpStatus;
    private ?string $endpoint;

    public function __construct(
        string $message,
        int $code = 0,
        ?\Throwable $previous = null,
        ?int $httpStatus = null,
        ?string $endpoint = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->httpStatus = $httpStatus;
        $this->endpoint = $endpoint;
    }

    public function getHttpStatus(): ?int
    {
        return $this->httpStatus;
    }

    public function getEndpoint(): ?string
    {
        return $this->endpoint;
    }
}
