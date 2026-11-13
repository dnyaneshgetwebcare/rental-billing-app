<?php

declare(strict_types=1);

namespace App\Shared;

use InvalidArgumentException;

/**
 * Decimal money arithmetic on numeric strings (bcmath). Never use floats for amounts.
 */
final class Money
{
    private const WORK_SCALE = 6;

    public static function of(string|int|float|null $value, int $scale = 2): string
    {
        if ($value === null || $value === '') {
            return self::round('0', $scale);
        }
        if (is_float($value)) {
            $value = sprintf('%.6F', $value);
        }
        $value = trim((string) $value);
        if (!preg_match('/^-?\d+(\.\d+)?$/', $value)) {
            throw new InvalidArgumentException("Invalid amount: $value");
        }
        return self::round($value, $scale);
    }

    public static function isValid(mixed $value): bool
    {
        if (is_int($value) || is_float($value)) {
            return is_finite((float) $value);
        }
        return is_string($value) && preg_match('/^-?\d+(\.\d+)?$/', trim($value)) === 1;
    }

    public static function add(string ...$values): string
    {
        $sum = '0';
        foreach ($values as $v) {
            $sum = bcadd($sum, $v, self::WORK_SCALE);
        }
        return self::round($sum);
    }

    public static function sub(string $a, string $b): string
    {
        return self::round(bcsub($a, $b, self::WORK_SCALE));
    }

    public static function mul(string $a, string $b, int $scale = 2): string
    {
        return self::round(bcmul($a, $b, self::WORK_SCALE), $scale);
    }

    /** round($amount * $rate / 100) */
    public static function percent(string $amount, string $rate): string
    {
        return self::round(bcdiv(bcmul($amount, $rate, self::WORK_SCALE), '100', self::WORK_SCALE));
    }

    public static function neg(string $value): string
    {
        return self::round(bcmul($value, '-1', self::WORK_SCALE));
    }

    public static function cmp(string $a, string $b): int
    {
        return bccomp($a, $b, self::WORK_SCALE);
    }

    public static function isZero(string $value): bool
    {
        return self::cmp($value, '0') === 0;
    }

    public static function max(string $a, string $b): string
    {
        return self::cmp($a, $b) >= 0 ? self::round($a) : self::round($b);
    }

    public static function min(string $a, string $b): string
    {
        return self::cmp($a, $b) <= 0 ? self::round($a) : self::round($b);
    }

    /** Half-up rounding (away from zero), like Excel and the GST portal. */
    public static function round(string $value, int $scale = 2): string
    {
        $offset = '0.' . str_repeat('0', $scale) . '5';
        $rounded = str_starts_with($value, '-')
            ? bcsub($value, $offset, $scale)
            : bcadd($value, $offset, $scale);
        if (bccomp($rounded, '0', $scale) === 0) {
            return bcadd('0', '0', $scale);
        }
        return $rounded;
    }
}
