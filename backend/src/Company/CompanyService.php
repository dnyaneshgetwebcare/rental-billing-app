<?php

declare(strict_types=1);

namespace App\Company;

use App\Accounting\ChartOfAccounts;
use App\Gst\GstCalculator;
use App\Shared\ApiException;
use App\Shared\AuditLog;
use App\Shared\Context;
use App\Shared\Db;
use App\Shared\Input;

final readonly class CompanyService
{
    public function __construct(
        private Db $db,
        private ChartOfAccounts $chart,
        private Context $context,
        private AuditLog $audit,
    ) {}

    /** @return array<string, mixed> */
    public function fields(Input $in, bool $creating): array
    {
        $row = [
            'name' => $in->string('name', required: $creating, max: 150),
            'legal_name' => $in->string('legal_name', max: 200),
            'gstin' => $in->gstin('gstin'),
            'pan' => $in->string('pan', max: 10),
            'state_code' => $in->stateCode('state_code'),
            'address' => $in->string('address', max: 1000),
            'city' => $in->string('city', max: 100),
            'pincode' => $in->string('pincode', max: 10),
            'phone' => $in->string('phone', max: 20),
            'email' => $in->email('email'),
            'fy_start_month' => $in->int('fy_start_month', min: 1, max: 12, default: 4),
            'invoice_prefix' => $in->string('invoice_prefix', required: $creating, max: 5),
            'bank_details' => $in->string('bank_details', max: 1000),
            'invoice_terms' => $in->string('invoice_terms', max: 2000),
            'invoice_print_language' => $in->enum('invoice_print_language', ['en', 'en_mr'], default: 'en'),
        ];
        if ($row['state_code'] === null && $row['gstin'] !== null) {
            $row['state_code'] = GstCalculator::stateFromGstin($row['gstin']);
        }
        if ($creating && $row['state_code'] === null) {
            $in->error('state_code', 'Enter the state code or a GSTIN.');
        }
        if ($row['gstin'] !== null && $row['state_code'] !== null
            && GstCalculator::stateFromGstin($row['gstin']) !== $row['state_code']) {
            $in->error('state_code', 'State code does not match the GSTIN.');
        }
        if ($row['invoice_prefix'] !== null) {
            $row['invoice_prefix'] = strtoupper($row['invoice_prefix']);
            if (!preg_match('/^[A-Z0-9]{1,5}$/', $row['invoice_prefix'])) {
                $in->error('invoice_prefix', 'Use 1-5 letters or digits (invoice numbers must fit in 16 characters).');
            }
        }
        $in->validate();
        if (!$creating) {
            $row = array_filter($row, static fn($v, $k) => $in->has($k), ARRAY_FILTER_USE_BOTH);
        }
        return $row;
    }

    /**
     * Creates the company, makes the current user its admin and seeds ledgers and tax rates.
     *
     * @param array<string, mixed> $row
     */
    public function create(array $row, int $ownerUserId): int
    {
        return $this->db->transaction(function () use ($row, $ownerUserId): int {
            $now = Db::now();
            $id = $this->db->insert('companies', $row + ['created_at' => $now, 'updated_at' => $now]);
            $this->db->insert('company_users', [
                'company_id' => $id,
                'user_id' => $ownerUserId,
                'role' => Context::ROLE_ADMIN,
                'created_at' => $now,
            ]);
            $this->chart->seed($id, $ownerUserId);
            $this->audit->record('create', 'company', $id, $row, $id);
            return $id;
        });
    }

    /**
     * @param array<string, mixed> $row
     */
    public function update(int $id, array $row): void
    {
        if ($row === []) {
            return;
        }
        $this->db->update('companies', $row + ['updated_at' => Db::now()], ['id' => $id]);
        $this->audit->record('update', 'company', $id, $row);
    }

    /** @return array<string, mixed> */
    public function get(int $id): array
    {
        return $this->db->one('SELECT * FROM companies WHERE id = :id', [':id' => $id]) ?? throw ApiException::notFound('Company');
    }
}
