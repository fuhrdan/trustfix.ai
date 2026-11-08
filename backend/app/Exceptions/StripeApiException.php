<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class StripeApiException extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        public readonly ?int $httpStatus,
        public readonly ?string $requestId,
        public readonly bool $outcomeUnknown
    ) {
        // Never attach provider bodies, request headers, or a previous exception.
        parent::__construct('Stripe request failed (' . $reason . ').');
    }
}
