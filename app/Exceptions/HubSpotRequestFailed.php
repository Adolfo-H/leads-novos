<?php

namespace App\Exceptions;

use RuntimeException;

final class HubSpotRequestFailed extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        string $operation,
    ) {
        parent::__construct(
            "HubSpot rejected the operation to {$operation} (HTTP {$status})."
        );
    }
}
