<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

class RouteOptimizationException extends RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus = 503)
    {
        parent::__construct($message);
    }
}
