<?php

declare(strict_types=1);

namespace App\Infrastructure\MercadoPago;

use RuntimeException;

/**
 * Thrown when a Mercado Pago API call fails after retries.
 */
class MercadoPagoException extends RuntimeException
{
}
