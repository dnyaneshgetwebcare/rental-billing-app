<?php

declare(strict_types=1);

namespace App\Shared;

/** Indian numbering (lakh, crore): 125000.50 → "Rupees One Lakh Twenty Five Thousand and Fifty Paise Only". */
final class AmountInWords
{
    private const ONES = [
        '', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten',
        'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen',
    ];
    private const TENS = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];

    public static function rupees(string $amount): string
    {
        $amount = Money::of($amount);
        $negative = str_starts_with($amount, '-');
        [$rupees, $paise] = explode('.', ltrim($amount, '-'));

        $words = 'Rupees ' . ((int) $rupees === 0 ? 'Zero' : self::integer((int) $rupees));
        if ((int) $paise > 0) {
            $words .= ' and ' . self::belowHundred((int) $paise) . ' Paise';
        }
        return ($negative ? 'Minus ' : '') . $words . ' Only';
    }

    private static function integer(int $n): string
    {
        $parts = [];
        foreach ([10000000 => 'Crore', 100000 => 'Lakh', 1000 => 'Thousand', 100 => 'Hundred'] as $unit => $name) {
            if ($n >= $unit) {
                $count = intdiv($n, $unit);
                $parts[] = ($unit === 10000000 ? self::integer($count) : self::belowHundred($count)) . ' ' . $name;
                $n %= $unit;
            }
        }
        if ($n > 0) {
            $parts[] = self::belowHundred($n);
        }
        return implode(' ', $parts);
    }

    private static function belowHundred(int $n): string
    {
        if ($n < 20) {
            return self::ONES[$n];
        }
        return trim(self::TENS[intdiv($n, 10)] . ' ' . self::ONES[$n % 10]);
    }
}
