<?php

declare(strict_types=1);

namespace App\Agreement;

use App\Shared\ApiException;
use App\Shared\AuditLog;
use App\Shared\CompanyScopedRepository;
use App\Shared\Db;
use App\Shared\Input;
use App\Shared\Money;
use DateTimeImmutable;

final readonly class AgreementService
{
    public function __construct(
        private CompanyScopedRepository $repo,
        private Db $db,
        private AuditLog $audit,
    ) {}

    /**
     * @return array{row: array<string, mixed>, charges: list<array<string, mixed>>|null}
     */
    public function fields(Input $in, bool $creating, ?int $id = null): array
    {
        $row = [
            'tenant_id' => $in->int('tenant_id', required: $creating, min: 1),
            'unit_id' => $in->int('unit_id', required: $creating, min: 1),
            'agreement_no' => $in->string('agreement_no', max: 50),
            'start_date' => $in->date('start_date', required: $creating),
            'end_date' => $in->date('end_date'),
            'rent_amount' => $in->money('rent_amount', required: $creating),
            'gst_rate' => $in->money('gst_rate', default: '18.00'),
            'sac' => $in->string('sac', max: 10, default: '997212'),
            'billing_day' => $in->int('billing_day', min: 1, max: 28, default: 1),
            'due_days' => $in->int('due_days', min: 0, max: 90, default: 7),
            'escalation_pct' => $in->money('escalation_pct', default: '0.00'),
            'escalation_months' => $in->int('escalation_months', min: 1, max: 120, default: 12),
            'deposit_amount' => $in->money('deposit_amount', default: '0.00'),
            'auto_bill' => $in->bool('auto_bill', true) ? 1 : 0,
            'notes' => $in->string('notes', max: 2000),
        ];

        $charges = null;
        $chargeInputs = [];
        if ($creating || $in->has('charges')) {
            $charges = [];
            $chargeInputs = $in->list('charges', max: 20);
            foreach ($chargeInputs as $ci) {
                $charges[] = [
                    'description' => $ci->string('description', required: true, max: 150),
                    'amount' => $ci->money('amount', required: true),
                    'gst_rate' => $ci->money('gst_rate', default: '18.00'),
                    'sac' => $ci->string('sac', max: 10, default: '997212'),
                ];
            }
        }

        if ($row['start_date'] !== null && $row['end_date'] !== null && $row['end_date'] < $row['start_date']) {
            $in->error('end_date', 'End date must be after the start date.');
        }
        $in->validate($chargeInputs);

        if (!$creating) {
            $row = array_filter($row, static fn($k) => $in->has($k), ARRAY_FILTER_USE_KEY);
        }
        if (isset($row['tenant_id'])) {
            $this->repo->assertExists('tenants', $row['tenant_id'], 'tenant_id', 'Tenant');
        }
        if (isset($row['unit_id'])) {
            $this->repo->assertExists('units', $row['unit_id'], 'unit_id', 'Unit');
            $busy = $this->db->scalar(
                "SELECT id FROM agreements WHERE company_id = :c AND unit_id = :u AND status = 'active' AND id <> :id",
                [':c' => $this->repo->companyId(), ':u' => $row['unit_id'], ':id' => $id ?? 0],
            );
            if ($busy !== null) {
                throw ApiException::validation(['unit_id' => "This unit already has an active agreement (#$busy). End it first."]);
            }
        }
        return ['row' => $row, 'charges' => $charges];
    }

    /**
     * @param array<string, mixed> $row
     * @param list<array<string, mixed>>|null $charges
     */
    public function save(?int $id, array $row, ?array $charges): int
    {
        return $this->db->transaction(function () use ($id, $row, $charges): int {
            $isNew = $id === null;
            if ($id === null) {
                $id = $this->repo->insert('agreements', $row + ['status' => 'active']);
            } else {
                $this->repo->get('agreements', $id, 'Agreement', forUpdate: true);
                if ($row !== []) {
                    $this->repo->update('agreements', $id, $row);
                }
            }
            if ($charges !== null) {
                $this->db->delete('agreement_charges', ['agreement_id' => $id, 'company_id' => $this->repo->companyId()]);
                foreach ($charges as $charge) {
                    $this->repo->insert('agreement_charges', $charge + ['agreement_id' => $id], timestamps: false, createdBy: false);
                }
            }
            $this->audit->record($isNew ? 'create' : 'update', 'agreement', $id, $row + ['charges' => $charges]);
            return $id;
        });
    }

    public function end(int $id, string $endDate): void
    {
        $agreement = $this->repo->get('agreements', $id, 'Agreement');
        if ($endDate < (string) $agreement['start_date']) {
            throw ApiException::validation(['end_date' => 'End date must be after the start date.']);
        }
        $this->repo->update('agreements', $id, ['status' => 'ended', 'end_date' => $endDate]);
        $this->audit->record('end', 'agreement', $id, ['end_date' => $endDate]);
    }

    /** @return array<string, mixed> */
    public function detail(int $id): array
    {
        $a = $this->db->one(
            'SELECT a.*, t.name AS tenant_name, t.state_code AS tenant_state, u.code AS unit_code,
                    w.name AS warehouse_name, w.state_code AS property_state
             FROM agreements a JOIN tenants t ON t.id = a.tenant_id JOIN units u ON u.id = a.unit_id
             JOIN warehouses w ON w.id = u.warehouse_id
             WHERE a.id = :id AND a.company_id = :c',
            [':id' => $id, ':c' => $this->repo->companyId()],
        ) ?? throw ApiException::notFound('Agreement');
        $a['charges'] = $this->db->all('SELECT * FROM agreement_charges WHERE agreement_id = :id ORDER BY id', [':id' => $id]);
        $a['current_rent'] = self::rentForPeriod($a, date('Y-m'));
        return $a;
    }

    /**
     * Rent after yearly (or every N months) escalation, compounded and rounded at each step.
     *
     * @param array<string, mixed> $agreement
     */
    public static function rentForPeriod(array $agreement, string $period): string
    {
        $rent = Money::of((string) $agreement['rent_amount']);
        $pct = Money::of((string) ($agreement['escalation_pct'] ?? '0'));
        $every = max(1, (int) ($agreement['escalation_months'] ?? 12));
        if (Money::isZero($pct)) {
            return $rent;
        }
        $start = new DateTimeImmutable(substr((string) $agreement['start_date'], 0, 7) . '-01');
        $current = new DateTimeImmutable($period . '-01');
        $months = ((int) $current->format('Y') - (int) $start->format('Y')) * 12 + (int) $current->format('n') - (int) $start->format('n');
        $steps = intdiv(max(0, $months), $every);
        for ($i = 0; $i < $steps; $i++) {
            $rent = Money::add($rent, Money::percent($rent, $pct));
        }
        return $rent;
    }

    /**
     * @param array<string, mixed> $agreement
     */
    public static function isBillable(array $agreement, string $period): bool
    {
        if ($agreement['status'] !== 'active' && $agreement['end_date'] === null) {
            return false;
        }
        $startPeriod = substr((string) $agreement['start_date'], 0, 7);
        if ($period < $startPeriod) {
            return false;
        }
        if ($agreement['end_date'] !== null && $period > substr((string) $agreement['end_date'], 0, 7)) {
            return false;
        }
        return true;
    }

    /**
     * Invoice lines for one month: rent plus the agreement's fixed monthly charges.
     *
     * @param array<string, mixed> $agreement with unit_code
     * @param list<array<string, mixed>> $charges
     * @return list<array<string, mixed>>
     */
    public static function billLines(array $agreement, array $charges, string $period, ?int $rentAccountId, ?int $otherAccountId): array
    {
        $month = (new DateTimeImmutable($period . '-01'))->format('M Y');
        $unit = isset($agreement['unit_code']) ? ' - Unit ' . $agreement['unit_code'] : '';
        $lines = [[
            'description' => "Warehouse rent for $month$unit",
            'sac' => (string) $agreement['sac'],
            'qty' => '1',
            'rate' => self::rentForPeriod($agreement, $period),
            'gst_rate' => (string) $agreement['gst_rate'],
            'account_id' => $rentAccountId,
        ]];
        foreach ($charges as $charge) {
            $lines[] = [
                'description' => $charge['description'] . " ($month)",
                'sac' => (string) $charge['sac'],
                'qty' => '1',
                'rate' => (string) $charge['amount'],
                'gst_rate' => (string) $charge['gst_rate'],
                'account_id' => $otherAccountId,
            ];
        }
        return $lines;
    }
}
