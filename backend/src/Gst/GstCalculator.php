<?php

declare(strict_types=1);

namespace App\Gst;

use App\Shared\Money;

/**
 * Splits GST per line: CGST + SGST when supplier and place of supply are in the same state, IGST otherwise.
 * Tax is rounded per line; the document total is rounded to the rupee with a round-off amount.
 */
final class GstCalculator
{
    /**
     * @param list<array{description?: string, sac?: string, qty?: string|int|float, rate: string|int|float, gst_rate: string|int|float}> $lines
     */
    public function calculate(array $lines, string $supplierStateCode, string $placeOfSupplyStateCode): GstResult
    {
        return $this->calculateLines(
            $lines,
            self::normalizeState($supplierStateCode) !== self::normalizeState($placeOfSupplyStateCode),
        );
    }

    /**
     * @param list<array{description?: string, sac?: string, qty?: string|int|float, rate: string|int|float, gst_rate: string|int|float}> $lines
     */
    public function calculateLines(array $lines, bool $interState): GstResult
    {
        $out = [];
        $taxable = $cgst = $sgst = $igst = '0.00';
        foreach ($lines as $line) {
            $qty = Money::of($line['qty'] ?? 1, 3);
            $rate = Money::of($line['rate']);
            $gstRate = Money::of($line['gst_rate']);
            $lineTaxable = Money::mul($qty, $rate);

            if ($interState) {
                $lineIgst = Money::percent($lineTaxable, $gstRate);
                $lineCgst = $lineSgst = '0.00';
            } else {
                $half = bcdiv($gstRate, '2', 4);
                $lineCgst = Money::percent($lineTaxable, $half);
                $lineSgst = $lineCgst;
                $lineIgst = '0.00';
            }

            $out[] = [
                'description' => (string) ($line['description'] ?? ''),
                'sac' => (string) ($line['sac'] ?? ''),
                'qty' => $qty,
                'rate' => $rate,
                'gst_rate' => $gstRate,
                'taxable_amount' => $lineTaxable,
                'cgst' => $lineCgst,
                'sgst' => $lineSgst,
                'igst' => $lineIgst,
                'total' => Money::add($lineTaxable, $lineCgst, $lineSgst, $lineIgst),
            ] + array_diff_key($line, array_flip(['description', 'sac', 'qty', 'rate', 'gst_rate']));

            $taxable = Money::add($taxable, $lineTaxable);
            $cgst = Money::add($cgst, $lineCgst);
            $sgst = Money::add($sgst, $lineSgst);
            $igst = Money::add($igst, $lineIgst);
        }

        $exact = Money::add($taxable, $cgst, $sgst, $igst);
        $grand = Money::round($exact, 0) . '.00';
        $roundOff = Money::sub($grand, $exact);

        return new GstResult($out, $interState, $taxable, $cgst, $sgst, $igst, $roundOff, Money::of($grand));
    }

    public static function normalizeState(string $code): string
    {
        return str_pad(ltrim(trim($code), '0'), 2, '0', STR_PAD_LEFT);
    }

    /** First two digits of a GSTIN are the state code. */
    public static function stateFromGstin(?string $gstin): ?string
    {
        if ($gstin === null || strlen($gstin) < 2 || !ctype_digit(substr($gstin, 0, 2))) {
            return null;
        }
        return substr($gstin, 0, 2);
    }

    public static function isValidGstin(string $gstin): bool
    {
        return preg_match('/^\d{2}[A-Z]{5}\d{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/', strtoupper($gstin)) === 1;
    }
}
