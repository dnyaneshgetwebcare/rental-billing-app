<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared;

use App\Shared\AmountInWords;
use App\Shared\FinancialYear;
use App\Shared\Money;
use App\Shared\NumberSeries;
use Codeception\Test\Unit;

final class MoneyTest extends Unit
{
    public function testOfNormalizesAndRoundsHalfUp(): void
    {
        $this->assertSame('10.00', Money::of('10'));
        $this->assertSame('10.01', Money::of('10.005'));
        $this->assertSame('-10.01', Money::of('-10.005'));
        $this->assertSame('0.00', Money::of(null));
        $this->assertSame('1.235', Money::of('1.2345', 3));
    }

    public function testArithmeticIsExact(): void
    {
        $this->assertSame('0.30', Money::add('0.1', '0.2'));
        $this->assertSame('6.00', Money::add('1', '2', '3'));
        $this->assertSame('-0.10', Money::sub('0.1', '0.2'));
        $this->assertSame('1800.00', Money::percent('10000', '18'));
        $this->assertSame('0.05', Money::percent('0.50', '9'));
        $this->assertSame('-5.00', Money::neg('5'));
    }

    public function testComparisons(): void
    {
        $this->assertSame(0, Money::cmp('1.00', '1'));
        $this->assertSame(1, Money::cmp('1.01', '1'));
        $this->assertTrue(Money::isZero('0.00'));
        $this->assertTrue(Money::isZero('-0.00'));
        $this->assertSame('5.00', Money::max('5', '-1'));
        $this->assertSame('-1.00', Money::min('5', '-1'));
    }

    public function testIsValid(): void
    {
        $this->assertTrue(Money::isValid('12.50'));
        $this->assertTrue(Money::isValid(12));
        $this->assertFalse(Money::isValid('12,50'));
        $this->assertFalse(Money::isValid('abc'));
        $this->assertFalse(Money::isValid(''));
    }

    public function testAmountInWordsUsesIndianNumbering(): void
    {
        $this->assertSame('Rupees Zero Only', AmountInWords::rupees('0'));
        $this->assertSame('Rupees Eleven Thousand Eight Hundred Only', AmountInWords::rupees('11800'));
        $this->assertSame(
            'Rupees One Lakh Twenty Five Thousand and Fifty Paise Only',
            AmountInWords::rupees('125000.50'),
        );
        $this->assertSame('Rupees Two Crore Three Lakh Forty Five Only', AmountInWords::rupees('20300045'));
    }

    public function testFinancialYear(): void
    {
        $fy = FinancialYear::of('2026-11-13');
        $this->assertSame('26-27', $fy->label);
        $this->assertSame('2026-04-01', $fy->start);
        $this->assertSame('2027-03-31', $fy->end);

        $this->assertSame('25-26', FinancialYear::of('2026-03-31')->label);
        $this->assertSame('26-27', FinancialYear::of('2026-04-01')->label);
        $this->assertSame('26', FinancialYear::of('2026-06-01', 1)->label);
    }

    public function testDocumentNumberFormat(): void
    {
        $company = ['invoice_prefix' => 'ABC'];
        $this->assertSame('ABC/26-27/0001', NumberSeries::format($company, NumberSeries::INVOICE, '26-27', 1));
        $this->assertSame('RCT/26-27/0042', NumberSeries::format($company, NumberSeries::RECEIPT, '26-27', 42));
        $this->assertSame('CN/25-26/12345', NumberSeries::format($company, NumberSeries::CREDIT_NOTE, '25-26', 12345));
    }
}
