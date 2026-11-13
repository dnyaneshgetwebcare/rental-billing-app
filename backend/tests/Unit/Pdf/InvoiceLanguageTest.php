<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pdf;

use App\Pdf\InvoicePdf;
use Codeception\Test\Unit;

final class InvoiceLanguageTest extends Unit
{
    public function testEnglishInvoiceOmitsMarathi(): void
    {
        $html = (new InvoicePdf())->html($this->company('en'), $this->doc());
        $this->assertStringContainsString('TAX INVOICE', $html);
        $this->assertStringNotContainsString('कर बीजक', $html);
    }

    public function testBilingualInvoicePrintsEnglishAndMarathi(): void
    {
        $html = (new InvoicePdf())->html($this->company('en_mr'), $this->doc());
        $this->assertStringContainsString('TAX INVOICE', $html);
        $this->assertStringContainsString('कर बीजक', $html);
        $this->assertStringContainsString('बिल प्राप्तकर्ता', $html);
        $this->assertStringContainsString('मार्च 2026', $html);
    }

    /** @return array<string, mixed> */
    private function company(string $language): array
    {
        return [
            'name' => 'Adhiraj',
            'legal_name' => 'Adhiraj Warehouses',
            'address' => 'Pune',
            'city' => 'Pune',
            'pincode' => '411001',
            'gstin' => '27AAAAA0000A1Z5',
            'state_code' => '27',
            'phone' => '',
            'email' => '',
            'bank_details' => 'HDFC',
            'invoice_terms' => 'Rent',
            'invoice_print_language' => $language,
        ];
    }

    /** @return array<string, mixed> */
    private function doc(): array
    {
        return [
            'number' => 'ADH/26-27/0001',
            'invoice_date' => '2026-03-01',
            'is_inter_state' => 0,
            'place_of_supply' => '27',
            'period_label' => '2026-03',
            'due_date' => '2026-03-10',
            'taxable_amount' => '1000.00',
            'cgst' => '90.00',
            'sgst' => '90.00',
            'igst' => '0.00',
            'round_off' => '0.00',
            'total' => '1180.00',
            'tenant' => ['name' => 'Tenant', 'address' => 'Pune', 'gstin' => null, 'state_code' => '27'],
            'unit' => ['warehouse_name' => 'Warehouse A', 'code' => 'U1', 'area_sqft' => '1000'],
            'lines' => [[
                'description' => 'Rent',
                'sac' => '997212',
                'qty' => '1',
                'rate' => '1000.00',
                'taxable_amount' => '1000.00',
                'gst_rate' => '18',
                'cgst' => '90.00',
                'sgst' => '90.00',
                'igst' => '0.00',
                'total' => '1180.00',
            ]],
        ];
    }
}
