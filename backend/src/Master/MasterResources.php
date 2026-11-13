<?php

declare(strict_types=1);

namespace App\Master;

use App\Gst\GstCalculator;
use App\Shared\ApiException;
use App\Shared\CompanyScopedRepository;
use App\Shared\Context;
use App\Shared\Input;
use App\Shared\Money;

/**
 * Field rules and list queries for the simple master tables. Business documents (agreements, invoices,
 * receipts, ...) have their own services.
 */
final readonly class MasterResources
{
    /** URL segment => [table, label, roles allowed to write] */
    public const RESOURCES = [
        'tenants' => ['tenants', 'Tenant', [Context::ROLE_ACCOUNTANT, Context::ROLE_STAFF]],
        'vendors' => ['vendors', 'Vendor', [Context::ROLE_ACCOUNTANT, Context::ROLE_STAFF]],
        'warehouses' => ['warehouses', 'Warehouse', [Context::ROLE_ACCOUNTANT]],
        'units' => ['units', 'Unit', [Context::ROLE_ACCOUNTANT]],
        'tax-rates' => ['tax_rates', 'Tax rate', [Context::ROLE_ACCOUNTANT]],
        'accounts' => ['accounts', 'Account', [Context::ROLE_ACCOUNTANT]],
    ];

    public function __construct(private CompanyScopedRepository $repo) {}

    /**
     * @return array<string, mixed>
     */
    public function fields(string $resource, Input $in, bool $creating, ?array $existing = null): array
    {
        $row = match ($resource) {
            'tenants', 'vendors' => $this->party($in, $creating),
            'warehouses' => [
                'name' => $in->string('name', required: $creating, max: 150),
                'address' => $in->string('address', max: 1000),
                'city' => $in->string('city', max: 100),
                'state_code' => $in->stateCode('state_code'),
                'is_active' => $in->bool('is_active', true) ? 1 : 0,
            ],
            'units' => [
                'warehouse_id' => $in->int('warehouse_id', required: $creating, min: 1),
                'code' => $in->string('code', required: $creating, max: 30),
                'area_sqft' => $in->money('area_sqft'),
                'description' => $in->string('description', max: 255),
                'is_active' => $in->bool('is_active', true) ? 1 : 0,
            ],
            'tax-rates' => [
                'name' => $in->string('name', required: $creating, max: 100),
                'rate' => $in->money('rate', required: $creating),
                'sac' => $in->string('sac', max: 10),
                'is_default' => $in->bool('is_default') ? 1 : 0,
                'is_active' => $in->bool('is_active', true) ? 1 : 0,
            ],
            'accounts' => [
                'code' => $in->string('code', required: $creating, max: 20),
                'name' => $in->string('name', required: $creating, max: 150),
                'type' => $in->enum('type', ['asset', 'liability', 'equity', 'income', 'expense'], required: $creating),
                'is_cash_bank' => $in->bool('is_cash_bank') ? 1 : 0,
                'is_active' => $in->bool('is_active', true) ? 1 : 0,
            ],
            default => throw ApiException::notFound('Resource'),
        };

        if ($resource === 'units' && $row['warehouse_id'] !== null) {
            if ($this->repo->find('warehouses', $row['warehouse_id']) === null) {
                $in->error('warehouse_id', 'Warehouse not found.');
            }
        }
        if ($resource === 'tax-rates' && $row['rate'] !== null && Money::cmp($row['rate'], '28') > 0) {
            $in->error('rate', 'GST rate must be between 0 and 28.');
        }
        if ($resource === 'accounts' && $existing !== null && $existing['system_key'] !== null
            && $in->has('type') && $row['type'] !== $existing['type']) {
            $in->error('type', 'The type of a system account cannot be changed.');
        }
        $in->validate();

        if (!$creating) {
            $keepStateFromGstin = in_array($resource, ['tenants', 'vendors'], true) && $in->has('gstin');
            $row = array_filter(
                $row,
                static fn($k) => $in->has($k) || ($k === 'state_code' && $keepStateFromGstin),
                ARRAY_FILTER_USE_KEY,
            );
        }
        return $row;
    }

    /**
     * @return array{from: string, select: string, condition: string, params: array<string, mixed>, order: string}
     */
    public function listQuery(string $resource, array $query): array
    {
        $table = self::RESOURCES[$resource][0];
        $conditions = ['1 = 1'];
        $params = [];
        $search = trim((string) ($query['q'] ?? ''));
        $from = "$table t";
        $select = 't.*';
        $order = 't.name';

        $searchCols = match ($resource) {
            'tenants', 'vendors' => ['t.name', 't.phone', 't.gstin', 't.contact_person'],
            'units' => ['t.code', 't.description'],
            'accounts' => ['t.code', 't.name'],
            default => ['t.name'],
        };
        if ($search !== '') {
            $conditions[] = '(' . implode(' OR ', array_map(static fn($c) => "$c LIKE :q", $searchCols)) . ')';
            $params[':q'] = '%' . addcslashes($search, '%_\\') . '%';
        }
        if (isset($query['active']) && $query['active'] !== '') {
            $conditions[] = 't.is_active = :active';
            $params[':active'] = filter_var($query['active'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        }

        if ($resource === 'units') {
            $from = 'units t JOIN warehouses w ON w.id = t.warehouse_id';
            $select = 't.*, w.name AS warehouse_name,
                (SELECT a.id FROM agreements a WHERE a.unit_id = t.id AND a.status = \'active\' LIMIT 1) AS active_agreement_id';
            $order = 'w.name, t.code';
            if (!empty($query['warehouse_id'])) {
                $conditions[] = 't.warehouse_id = :wh';
                $params[':wh'] = (int) $query['warehouse_id'];
            }
        }
        if ($resource === 'accounts') {
            $order = 't.code';
            if (!empty($query['type'])) {
                $conditions[] = 't.type = :type';
                $params[':type'] = (string) $query['type'];
            }
            if (isset($query['cash_bank']) && $query['cash_bank'] !== '') {
                $conditions[] = 't.is_cash_bank = :cb';
                $params[':cb'] = filter_var($query['cash_bank'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
            }
        }
        if ($resource === 'tax-rates') {
            $order = 't.is_default DESC, t.rate DESC';
        }

        return [
            'from' => $from,
            'select' => $select,
            'condition' => implode(' AND ', $conditions),
            'params' => $params,
            'order' => $order,
        ];
    }

    /** @return array<string, mixed> */
    private function party(Input $in, bool $creating): array
    {
        $row = [
            'name' => $in->string('name', required: $creating, max: 200),
            'contact_person' => $in->string('contact_person', max: 150),
            'gstin' => $in->gstin('gstin'),
            'pan' => $in->string('pan', max: 10),
            'state_code' => $in->stateCode('state_code'),
            'phone' => $in->string('phone', max: 20),
            'whatsapp' => $in->string('whatsapp', max: 20),
            'email' => $in->email('email'),
            'address' => $in->string('address', max: 1000),
            'is_active' => $in->bool('is_active', true) ? 1 : 0,
        ];
        if ($row['gstin'] !== null) {
            $fromGstin = GstCalculator::stateFromGstin($row['gstin']);
            if ($row['state_code'] === null) {
                $row['state_code'] = $fromGstin;
            } elseif ($row['state_code'] !== $fromGstin) {
                $in->error('state_code', 'State code does not match the GSTIN.');
            }
        }
        if ($creating && $row['state_code'] === null) {
            $in->error('state_code', 'Enter the state code (needed for CGST/SGST vs IGST) or a GSTIN.');
        }
        foreach (['phone', 'whatsapp'] as $f) {
            if ($row[$f] !== null) {
                $row[$f] = preg_replace('/[^\d+]/', '', $row[$f]);
            }
        }
        return $row;
    }
}
