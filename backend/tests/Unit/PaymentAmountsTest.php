<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\PaymentAmounts;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class PaymentAmountsTest extends TestCase
{
    public function test_database_decimal_strings_convert_exactly_to_signed_cents(): void
    {
        foreach ([
            ['0', 0], ['0.00', 0], ['0.5', 50], ['0.05', 5], ['1', 100],
            ['1.2', 120], ['12.50', 1250], ['100.10', 10010], ['999999.99', 99999999],
            ['-0.00', 0], ['-0.5', -50], ['-12.50', -1250], ['-999999.99', -99999999],
        ] as [$decimal, $expected]) {
            self::assertSame($expected, PaymentAmounts::decimalToCents($decimal));
        }
    }

    public function test_ambiguous_precision_excess_and_non_string_prices_are_rejected(): void
    {
        foreach ([
            12, 12.50, false, null, [], new \stdClass(), '', ' 12.00', '12.00 ',
            '+1.00', '01.00', '1e2', '1,000.00', '1.001', '1.', '.50',
            '1000000.00', '-1000000.00', 'NaN', 'INF', "1.00\n", "1\0.00",
        ] as $value) {
            $this->invalid(fn () => PaymentAmounts::decimalToCents($value));
        }
    }

    public function test_platform_fee_rounding_and_supported_extremes_are_exact(): void
    {
        foreach ([
            [0, 0], [50, 5], [55, 6], [101, 10], [105, 11],
            [12500, 1250], [99999999, 10000000],
        ] as [$amount, $expected]) {
            self::assertSame($expected, PaymentAmounts::platformFeeCents($amount));
        }
        self::assertSame(0, PaymentAmounts::platformFeeCents(99999999, 0));
        self::assertSame(99999999, PaymentAmounts::platformFeeCents(99999999, 10000));
        self::assertSame(1, PaymentAmounts::platformFeeCents(1, 5000));
        self::assertSame(0, PaymentAmounts::platformFeeCents(1, 4999));
    }

    public function test_invalid_cent_values_and_fee_rates_are_rejected(): void
    {
        foreach ([-1, 100000000, 50.0, '50', null, false] as $amount) {
            $this->invalid(fn () => PaymentAmounts::platformFeeCents($amount));
        }
        foreach ([-1, 10001, '1000', 1000.0, null] as $rate) {
            $this->invalid(fn () => PaymentAmounts::platformFeeCents(100, $rate));
        }
    }

    public function test_split_refunds_do_not_repeat_rounding_credits(): void
    {
        self::assertSame(1, PaymentAmounts::refundFeeDeltaCents(100, 10, 0, 5));
        self::assertSame(0, PaymentAmounts::refundFeeDeltaCents(100, 10, 5, 10));
        self::assertSame(1, PaymentAmounts::refundFeeDeltaCents(100, 10, 10, 15));
        self::assertSame(0, PaymentAmounts::refundFeeDeltaCents(100, 10, 15, 20));

        $total = 0;
        for ($next = 5; $next <= 100; $next += 5) {
            $total += PaymentAmounts::refundFeeDeltaCents(100, 10, $next - 5, $next);
        }

        self::assertSame(10, $total);
        self::assertSame($total, PaymentAmounts::refundFeeDeltaCents(100, 10, 0, 100));
        self::assertSame(0, PaymentAmounts::refundFeeDeltaCents(100, 10, 0, 0));
    }

    public function test_cumulative_allocations_are_monotone_capped_and_complete(): void
    {
        foreach ([50, 51, 55, 99, 101, 1000, 12500, 99999999] as $amount) {
            foreach ([0, 1000, 10000] as $rate) {
                $fee = PaymentAmounts::platformFeeCents($amount, $rate);
                $points = array_unique([
                    0, 1, 2, intdiv($amount, 3), intdiv($amount, 2), $amount - 1, $amount,
                ]);
                sort($points, SORT_NUMERIC);
                $previous = 0;
                $total = 0;

                foreach ($points as $next) {
                    $delta = PaymentAmounts::refundFeeDeltaCents($amount, $fee, $previous, $next);
                    self::assertGreaterThanOrEqual(0, $delta);
                    self::assertLessThanOrEqual($fee - $total, $delta);
                    $total += $delta;
                    $previous = $next;
                }

                self::assertSame($fee, $total);
            }
        }
    }

    public function test_invalid_or_excessive_refund_intervals_are_rejected(): void
    {
        foreach ([
            [0, 0, 0, 0], [100000000, 10, 0, 1], [100, -1, 0, 1],
            [100, 101, 0, 1], [100, 10, -1, 1], [100, 10, 20, 19],
            [100, 10, 0, 101], [100.0, 10, 0, 1], ['100', 10, 0, 1],
            [100, 10.0, 0, 1], [100, 10, '0', 1], [100, 10, 0, 1.0],
        ] as $values) {
            $this->invalid(fn () => PaymentAmounts::refundFeeDeltaCents(...$values));
        }
    }

    public function test_refunds_use_the_recorded_fee_and_handle_odd_cent_boundaries(): void
    {
        self::assertSame(425, PaymentAmounts::refundFeeDeltaCents(10000, 850, 0, 5000));
        self::assertSame(425, PaymentAmounts::refundFeeDeltaCents(10000, 850, 5000, 10000));
        self::assertSame(850, PaymentAmounts::refundFeeDeltaCents(10000, 850, 0, 10000));
        self::assertSame(0, PaymentAmounts::refundFeeDeltaCents(99, 1, 0, 49));
        self::assertSame(1, PaymentAmounts::refundFeeDeltaCents(99, 1, 49, 50));
        self::assertSame(10000000, PaymentAmounts::refundFeeDeltaCents(99999999, 10000000, 0, 99999999));
        self::assertSame(1, PaymentAmounts::refundFeeDeltaCents(99999999, 99999999, 99999998, 99999999));
    }

    private function invalid(callable $action): void
    {
        try {
            $action();
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
            return;
        }
        self::fail('Expected invalid payment amount rejection.');
    }
}
