<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;
use LogicException;

final class PaymentAmounts
{
    private const MAX_CENTS = 99_999_999;

    // Parse a database decimal string, including negative change-order credits.
    // Checkout eligibility and the Stripe minimum remain separate validations.
    public static function decimalToCents(mixed $decimal): int
    {
        self::requireIntegerRuntime();
        if (!is_string($decimal)
            || preg_match('/\A(-?)(0|[1-9][0-9]{0,5})(?:\.([0-9]{1,2}))?\z/', $decimal, $parts) !== 1) {
            throw new InvalidArgumentException('A supported decimal USD string is required.');
        }

        $fraction = str_pad($parts[3] ?? '', 2, '0');
        $cents = ((int) $parts[2]) * 100 + (int) $fraction;

        return $parts[1] === '-' ? -$cents : $cents;
    }

    // Basis points and the resulting fee must be snapshotted with the payment.
    // Use integer half-up rounding; the approved staging rate is 1,000 bps.
    public static function platformFeeCents(mixed $amountCents, mixed $basisPoints = 1000): int
    {
        self::requireIntegerRuntime();
        $amount = self::cents($amountCents);
        if (!is_int($basisPoints) || $basisPoints < 0 || $basisPoints > 10_000) {
            throw new InvalidArgumentException('Platform fee basis points are invalid.');
        }

        return intdiv($amount * $basisPoints + 5000, 10_000);
    }

    // Return only the incremental fee for this cumulative refund interval.
    // Use the original recorded fee, independently of today's configured rate.
    public static function refundFeeDeltaCents(
        mixed $originalAmountCents,
        mixed $originalFeeCents,
        mixed $previousRefundedCents,
        mixed $newRefundedCents
    ): int {
        self::requireIntegerRuntime();
        $amount = self::cents($originalAmountCents);
        $fee = self::cents($originalFeeCents);
        $previous = self::cents($previousRefundedCents);
        $next = self::cents($newRefundedCents);

        if ($amount === 0 || $fee > $amount || $previous > $next || $next > $amount) {
            throw new InvalidArgumentException('Cumulative refund amounts are invalid.');
        }

        // At the supported bounds the product fits a signed 64-bit integer.
        $allocated = static fn (int $refunded): int =>
            intdiv($fee * $refunded + intdiv($amount, 2), $amount);

        return $allocated($next) - $allocated($previous);
    }

    private static function cents(mixed $value): int
    {
        if (!is_int($value) || $value < 0 || $value > self::MAX_CENTS) {
            throw new InvalidArgumentException('Supported nonnegative integer cents are required.');
        }

        return $value;
    }

    private static function requireIntegerRuntime(): void
    {
        if (PHP_INT_SIZE < 8) {
            throw new LogicException('Payment calculations require a 64-bit PHP runtime.');
        }
    }
}
