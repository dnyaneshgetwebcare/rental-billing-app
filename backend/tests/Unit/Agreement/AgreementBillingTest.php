<?php

declare(strict_types=1);

namespace App\Tests\Unit\Agreement;

use App\Agreement\AgreementService;
use Codeception\Test\Unit;

final class AgreementBillingTest extends Unit
{
    private const AGREEMENT = [
        'start_date' => '2025-06-15',
        'end_date' => null,
        'status' => 'active',
        'rent_amount' => '50000.00',
        'escalation_pct' => '5.00',
        'escalation_months' => 12,
        'gst_rate' => '18.00',
        'sac' => '997212',
        'unit_code' => 'A-101',
    ];

    public function testRentEscalatesEveryTwelveMonthsCompounded(): void
    {
        $a = self::AGREEMENT;
        $this->assertSame('50000.00', AgreementService::rentForPeriod($a, '2025-06'));
        $this->assertSame('50000.00', AgreementService::rentForPeriod($a, '2026-05'));
        $this->assertSame('52500.00', AgreementService::rentForPeriod($a, '2026-06'));
        $this->assertSame('55125.00', AgreementService::rentForPeriod($a, '2027-06'));
        $this->assertSame('57881.25', AgreementService::rentForPeriod($a, '2028-07'));
    }

    public function testCustomEscalationInterval(): void
    {
        $a = ['escalation_months' => 6] + self::AGREEMENT;
        $this->assertSame('50000.00', AgreementService::rentForPeriod($a, '2025-11'));
        $this->assertSame('52500.00', AgreementService::rentForPeriod($a, '2025-12'));
    }

    public function testNoEscalation(): void
    {
        $a = ['escalation_pct' => '0'] + self::AGREEMENT;
        $this->assertSame('50000.00', AgreementService::rentForPeriod($a, '2030-01'));
    }

    public function testBillableWindow(): void
    {
        $a = self::AGREEMENT;
        $this->assertFalse(AgreementService::isBillable($a, '2025-05'));
        $this->assertTrue(AgreementService::isBillable($a, '2025-06'));
        $this->assertTrue(AgreementService::isBillable($a, '2030-01'));

        $ended = ['status' => 'ended', 'end_date' => '2026-03-10'] + $a;
        $this->assertTrue(AgreementService::isBillable($ended, '2026-03'));
        $this->assertFalse(AgreementService::isBillable($ended, '2026-04'));
    }

    public function testBillLinesIncludeRentAndCharges(): void
    {
        $lines = AgreementService::billLines(
            self::AGREEMENT,
            [['description' => 'Maintenance', 'amount' => '2000.00', 'gst_rate' => '18.00', 'sac' => '997212']],
            '2026-07',
            10,
            11,
        );

        $this->assertCount(2, $lines);
        $this->assertSame('Warehouse rent for Jul 2026 - Unit A-101', $lines[0]['description']);
        $this->assertSame('52500.00', $lines[0]['rate']);
        $this->assertSame(10, $lines[0]['account_id']);
        $this->assertSame('Maintenance (Jul 2026)', $lines[1]['description']);
        $this->assertSame(11, $lines[1]['account_id']);
    }
}
