<?php

declare(strict_types=1);

namespace App\Tests\Unit\Gst;

use App\Gst\GstCalculator;
use Codeception\Test\Unit;

final class GstCalculatorTest extends Unit
{
    private GstCalculator $gst;

    protected function _before(): void
    {
        $this->gst = new GstCalculator();
    }

    public function testIntraStateSplitsCgstAndSgst(): void
    {
        $r = $this->gst->calculate([['description' => 'Rent', 'rate' => '25000', 'gst_rate' => '18']], '27', '27');

        $this->assertFalse($r->interState);
        $this->assertSame('25000.00', $r->taxable);
        $this->assertSame('2250.00', $r->cgst);
        $this->assertSame('2250.00', $r->sgst);
        $this->assertSame('0.00', $r->igst);
        $this->assertSame('29500.00', $r->grandTotal);
        $this->assertSame('0.00', $r->roundOff);
        $this->assertSame('4500.00', $r->totalTax());
    }

    public function testInterStateChargesIgst(): void
    {
        $r = $this->gst->calculate([['rate' => '10000', 'gst_rate' => '18']], '27', '24');

        $this->assertTrue($r->interState);
        $this->assertSame('0.00', $r->cgst);
        $this->assertSame('1800.00', $r->igst);
        $this->assertSame('11800.00', $r->grandTotal);
    }

    public function testStateCodesAreNormalized(): void
    {
        $this->assertFalse($this->gst->calculate([['rate' => '1', 'gst_rate' => '18']], '7', '07')->interState);
        $this->assertSame('07', GstCalculator::normalizeState('7'));
    }

    public function testPerLineRoundingAndRoundOffToRupee(): void
    {
        // 999.99 @ 18%: CGST = SGST = 90.00 (89.9991 rounded); exact 1179.99 -> 1180.00, round-off +0.01
        $r = $this->gst->calculate([['rate' => '999.99', 'gst_rate' => '18']], '27', '27');

        $this->assertSame('90.00', $r->cgst);
        $this->assertSame('90.00', $r->sgst);
        $this->assertSame('1180.00', $r->grandTotal);
        $this->assertSame('0.01', $r->roundOff);
    }

    public function testNegativeRoundOff(): void
    {
        $r = $this->gst->calculate([['rate' => '100.40', 'gst_rate' => '0']], '27', '27');

        $this->assertSame('100.00', $r->grandTotal);
        $this->assertSame('-0.40', $r->roundOff);
    }

    public function testQuantityAndMixedRates(): void
    {
        $r = $this->gst->calculate([
            ['description' => 'Rent', 'qty' => '1', 'rate' => '20000', 'gst_rate' => '18'],
            ['description' => 'Loading', 'qty' => '2.5', 'rate' => '400', 'gst_rate' => '5'],
            ['description' => 'Exempt', 'qty' => '1', 'rate' => '300', 'gst_rate' => '0'],
        ], '29', '29');

        $this->assertSame('21300.00', $r->taxable);
        $this->assertSame('1825.00', $r->cgst); // 1800 + 25
        $this->assertSame('1825.00', $r->sgst);
        $this->assertSame('1000.00', $r->lines[1]['taxable_amount']);
        $this->assertSame('1050.00', $r->lines[1]['total']);
        $this->assertSame('24950.00', $r->grandTotal);
    }

    public function testExtraLineKeysPassThrough(): void
    {
        $r = $this->gst->calculateLines([['rate' => '100', 'gst_rate' => '18', 'account_id' => 42]], false);
        $this->assertSame(42, $r->lines[0]['account_id']);
    }

    public function testGstinHelpers(): void
    {
        $this->assertTrue(GstCalculator::isValidGstin('27AABCA1234F1Z5'));
        $this->assertFalse(GstCalculator::isValidGstin('27AABCA1234F1Z'));
        $this->assertFalse(GstCalculator::isValidGstin('XXAABCA1234F1Z5'));
        $this->assertSame('27', GstCalculator::stateFromGstin('27AABCA1234F1Z5'));
        $this->assertNull(GstCalculator::stateFromGstin(null));
    }
}
