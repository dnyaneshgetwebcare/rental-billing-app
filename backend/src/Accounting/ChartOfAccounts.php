<?php

declare(strict_types=1);

namespace App\Accounting;

use App\Shared\ApiException;
use App\Shared\Db;

/**
 * Default ledgers created for every new company. Accounts with a system_key are used by automatic posting.
 */
final readonly class ChartOfAccounts
{
    public const CASH = 'CASH';
    public const BANK = 'BANK';
    public const DEBTORS = 'DEBTORS';
    public const CREDITORS = 'CREDITORS';
    public const INPUT_CGST = 'INPUT_CGST';
    public const INPUT_SGST = 'INPUT_SGST';
    public const INPUT_IGST = 'INPUT_IGST';
    public const OUTPUT_CGST = 'OUTPUT_CGST';
    public const OUTPUT_SGST = 'OUTPUT_SGST';
    public const OUTPUT_IGST = 'OUTPUT_IGST';
    public const TENANT_DEPOSITS = 'TENANT_DEPOSITS';
    public const CAPITAL = 'CAPITAL';
    public const OPENING = 'OPENING';
    public const RENT_INCOME = 'RENT_INCOME';
    public const OTHER_INCOME = 'OTHER_INCOME';
    public const ROUND_OFF = 'ROUND_OFF';
    public const PURCHASES = 'PURCHASES';
    public const GENERAL_EXPENSE = 'GENERAL_EXPENSE';

    /** @var list<array{0: string, 1: string, 2: string, 3: ?string, 4: int}> code, name, type, system_key, is_cash_bank */
    private const DEFAULTS = [
        ['1000', 'Cash in Hand', 'asset', self::CASH, 1],
        ['1010', 'Bank Account', 'asset', self::BANK, 1],
        ['1100', 'Sundry Debtors (Tenants)', 'asset', self::DEBTORS, 0],
        ['1200', 'Input CGST', 'asset', self::INPUT_CGST, 0],
        ['1201', 'Input SGST', 'asset', self::INPUT_SGST, 0],
        ['1202', 'Input IGST', 'asset', self::INPUT_IGST, 0],
        ['1500', 'Fixed Assets', 'asset', null, 0],
        ['2000', 'Sundry Creditors (Vendors)', 'liability', self::CREDITORS, 0],
        ['2100', 'Output CGST', 'liability', self::OUTPUT_CGST, 0],
        ['2101', 'Output SGST', 'liability', self::OUTPUT_SGST, 0],
        ['2102', 'Output IGST', 'liability', self::OUTPUT_IGST, 0],
        ['2200', 'Security Deposits from Tenants', 'liability', self::TENANT_DEPOSITS, 0],
        ['2300', 'Loans', 'liability', null, 0],
        ['3000', 'Capital Account', 'equity', self::CAPITAL, 0],
        ['3100', 'Opening Balance Adjustment', 'equity', self::OPENING, 0],
        ['4000', 'Rent Income', 'income', self::RENT_INCOME, 0],
        ['4010', 'Other Charges Income', 'income', self::OTHER_INCOME, 0],
        ['4900', 'Round Off', 'income', self::ROUND_OFF, 0],
        ['5000', 'Purchases', 'expense', self::PURCHASES, 0],
        ['5100', 'Electricity', 'expense', null, 0],
        ['5110', 'Repairs and Maintenance', 'expense', null, 0],
        ['5120', 'Salaries and Wages', 'expense', null, 0],
        ['5130', 'Office Expenses', 'expense', null, 0],
        ['5140', 'Security Charges', 'expense', null, 0],
        ['5150', 'Property Tax', 'expense', null, 0],
        ['5160', 'Bank Charges', 'expense', null, 0],
        ['5190', 'General Expenses', 'expense', self::GENERAL_EXPENSE, 0],
    ];

    private const TAX_RATES = [
        ['GST 18% (Warehouse rent)', '18.00', '997212', 1],
        ['GST 12%', '12.00', null, 0],
        ['GST 5%', '5.00', null, 0],
        ['Exempt / Nil', '0.00', null, 0],
    ];

    public function __construct(private Db $db) {}

    public function seed(int $companyId, ?int $userId): void
    {
        $now = Db::now();
        foreach (self::DEFAULTS as [$code, $name, $type, $key, $cashBank]) {
            $this->db->insert('accounts', [
                'company_id' => $companyId,
                'code' => $code,
                'name' => $name,
                'type' => $type,
                'system_key' => $key,
                'is_cash_bank' => $cashBank,
                'is_active' => 1,
                'created_by' => $userId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        foreach (self::TAX_RATES as [$name, $rate, $sac, $default]) {
            $this->db->insert('tax_rates', [
                'company_id' => $companyId,
                'name' => $name,
                'rate' => $rate,
                'sac' => $sac,
                'is_default' => $default,
                'is_active' => 1,
                'created_by' => $userId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function idFor(int $companyId, string $systemKey): int
    {
        $id = $this->db->scalar(
            'SELECT id FROM accounts WHERE company_id = :c AND system_key = :k',
            [':c' => $companyId, ':k' => $systemKey],
        );
        if ($id === null) {
            throw new ApiException("System account $systemKey is missing for this company.", 'account_missing', 500);
        }
        return (int) $id;
    }
}
