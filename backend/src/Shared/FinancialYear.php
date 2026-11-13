<?php

declare(strict_types=1);

namespace App\Shared;

use DateTimeImmutable;

final readonly class FinancialYear
{
    private function __construct(
        public string $label,
        public string $start,
        public string $end,
    ) {}

    /** Indian FY starts in April by default: 2026-05-10 → "26-27". */
    public static function of(string $date, int $startMonth = 4): self
    {
        $d = new DateTimeImmutable($date);
        $year = (int) $d->format('Y');
        if ((int) $d->format('n') < $startMonth) {
            $year--;
        }
        $start = sprintf('%04d-%02d-01', $year, $startMonth);
        $end = (new DateTimeImmutable($start))->modify('+1 year -1 day')->format('Y-m-d');
        $label = $startMonth === 1
            ? sprintf('%02d', $year % 100)
            : sprintf('%02d-%02d', $year % 100, ($year + 1) % 100);

        return new self($label, $start, $end);
    }
}
