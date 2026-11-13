<?php

declare(strict_types=1);

namespace App\Agreement;

use App\Accounting\ChartOfAccounts;
use App\Invoice\InvoiceService;
use App\Shared\CompanyScopedRepository;
use App\Shared\Context;
use App\Shared\Db;
use App\Shared\Money;
use DateTimeImmutable;
use Throwable;

/**
 * Creates monthly rent invoices for active agreements on or after their billing day.
 *
 * Safe to run many times a day: an agreement is billed at most once per period. The previous month is
 * also checked so a missed cron day is caught up. If a user cancels an auto invoice, that period is not
 * auto-billed again (bill it manually if needed).
 */
final readonly class AutoBillingService
{
    public function __construct(
        private Db $db,
        private Context $context,
        private InvoiceService $invoices,
        private ChartOfAccounts $chart,
    ) {}

    /**
     * @return array{created: list<array<string, mixed>>, skipped: int, errors: list<array<string, mixed>>}
     */
    public function run(string $date, ?int $onlyCompanyId = null, bool $dryRun = false): array
    {
        $result = ['created' => [], 'skipped' => 0, 'errors' => []];
        $companies = $onlyCompanyId === null
            ? $this->db->all('SELECT * FROM companies ORDER BY id')
            : $this->db->all('SELECT * FROM companies WHERE id = :id', [':id' => $onlyCompanyId]);

        $previousCompany = null;
        try {
            $previousCompany = [$this->context->company(), (string) $this->context->role()];
        } catch (Throwable) {
        }

        foreach ($companies as $company) {
            $this->context->setCompany($company, Context::ROLE_ADMIN);
            foreach ($this->due($date) as $due) {
                if ($dryRun) {
                    $result['created'][] = $due + ['dry_run' => true];
                    continue;
                }
                try {
                    $id = $this->invoices->create(
                        [
                            'tenant_id' => $due['tenant_id'],
                            'agreement_id' => $due['agreement_id'],
                            'invoice_date' => $date,
                            'due_date' => null,
                            'place_of_supply' => null,
                            'notes' => null,
                        ],
                        $due['lines'],
                        'auto',
                        $due['period'],
                    );
                    $result['created'][] = ['invoice_id' => $id] + $due;
                } catch (Throwable $e) {
                    if (CompanyScopedRepository::isDuplicateError($e)) {
                        $result['skipped']++;
                        continue;
                    }
                    $result['errors'][] = [
                        'company_id' => $company['id'],
                        'agreement_id' => $due['agreement_id'],
                        'period' => $due['period'],
                        'message' => $e->getMessage(),
                    ];
                }
            }
        }

        if ($previousCompany !== null) {
            $this->context->setCompany($previousCompany[0], $previousCompany[1]);
        } else {
            $this->context->clearCompany();
        }
        return $result;
    }

    /**
     * Agreements of the current company that need a bill on $date.
     *
     * @return list<array{agreement_id: int, tenant_id: int, tenant_name: string, unit_code: string, period: string, billing_date: string, taxable_amount: string, lines: list<array<string, mixed>>}>
     */
    public function due(string $date, bool $includeFuture = false): array
    {
        $companyId = $this->context->companyId();
        $today = new DateTimeImmutable($date);
        $periods = [
            $today->modify('first day of last month')->format('Y-m'),
            $today->format('Y-m'),
        ];

        $agreements = $this->db->all(
            "SELECT a.*, u.code AS unit_code, t.name AS tenant_name
             FROM agreements a JOIN units u ON u.id = a.unit_id JOIN tenants t ON t.id = a.tenant_id
             WHERE a.company_id = :c AND a.status = 'active' AND a.auto_bill = 1 ORDER BY a.id",
            [':c' => $companyId],
        );
        if ($agreements === []) {
            return [];
        }
        $rentAccount = $this->chart->idFor($companyId, ChartOfAccounts::RENT_INCOME);
        $otherAccount = $this->chart->idFor($companyId, ChartOfAccounts::OTHER_INCOME);

        $billed = [];
        foreach ($this->db->all(
            'SELECT agreement_id, period_label FROM invoices WHERE company_id = :c AND agreement_id IS NOT NULL AND period_label IN (:p0, :p1)',
            [':c' => $companyId, ':p0' => $periods[0], ':p1' => $periods[1]],
        ) as $row) {
            $billed[$row['agreement_id'] . '|' . $row['period_label']] = true;
        }

        $due = [];
        foreach ($agreements as $a) {
            $charges = null;
            foreach ($periods as $i => $period) {
                $isCurrent = $i === 1;
                if (isset($billed[$a['id'] . '|' . $period]) || !AgreementService::isBillable($a, $period)) {
                    continue;
                }
                if ($isCurrent && !$includeFuture && (int) $today->format('j') < (int) $a['billing_day']) {
                    continue;
                }
                $charges ??= $this->db->all('SELECT * FROM agreement_charges WHERE agreement_id = :a ORDER BY id', [':a' => $a['id']]);
                $lines = AgreementService::billLines($a, $charges, $period, $rentAccount, $otherAccount);
                $taxable = '0.00';
                foreach ($lines as $l) {
                    $taxable = Money::add($taxable, (string) $l['rate']);
                }
                $due[] = [
                    'agreement_id' => (int) $a['id'],
                    'tenant_id' => (int) $a['tenant_id'],
                    'tenant_name' => (string) $a['tenant_name'],
                    'unit_code' => (string) $a['unit_code'],
                    'period' => $period,
                    'billing_date' => $period . '-' . str_pad((string) $a['billing_day'], 2, '0', STR_PAD_LEFT),
                    'taxable_amount' => $taxable,
                    'lines' => $lines,
                ];
            }
        }
        return $due;
    }
}
