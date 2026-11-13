<?php

declare(strict_types=1);

namespace App\Gst;

final readonly class GstResult
{
    /**
     * @param list<array<string, mixed>> $lines
     */
    public function __construct(
        public array $lines,
        public bool $interState,
        public string $taxable,
        public string $cgst,
        public string $sgst,
        public string $igst,
        public string $roundOff,
        public string $grandTotal,
    ) {}

    public function totalTax(): string
    {
        return \App\Shared\Money::add($this->cgst, $this->sgst, $this->igst);
    }
}
