<?php

declare(strict_types=1);

namespace App\Accounting;

use App\Api\Shared\ResponseFactory;
use App\Shared\ApiException;
use App\Shared\AuditLog;
use App\Shared\CompanyScopedRepository;
use App\Shared\Context;
use App\Shared\Db;
use App\Shared\Input;
use App\Shared\Page;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\CurrentRoute;

/**
 * All posted vouchers (read-only) plus manual journal entries for opening balances, deposits, adjustments.
 */
final readonly class VoucherAction
{
    public function __construct(
        private PostingService $posting,
        private CompanyScopedRepository $repo,
        private Db $db,
        private Context $context,
        private AuditLog $audit,
        private ResponseFactory $response,
    ) {}

    public function list(ServerRequestInterface $request): ResponseInterface
    {
        $q = $request->getQueryParams();
        $conditions = ['1 = 1'];
        $params = [];
        if (!empty($q['type'])) {
            $conditions[] = 't.voucher_type = :type';
            $params[':type'] = (string) $q['type'];
        }
        if (!empty($q['from'])) {
            $conditions[] = 't.voucher_date >= :from';
            $params[':from'] = (string) $q['from'];
        }
        if (!empty($q['to'])) {
            $conditions[] = 't.voucher_date <= :to';
            $params[':to'] = (string) $q['to'];
        }
        if (!empty($q['q'])) {
            $conditions[] = '(t.number LIKE :q OR t.narration LIKE :q)';
            $params[':q'] = '%' . addcslashes((string) $q['q'], '%_\\') . '%';
        }
        return $this->response->success($this->repo->paginate(
            'vouchers t',
            implode(' AND ', $conditions),
            $params,
            't.voucher_date DESC, t.id DESC',
            Page::fromRequest($request),
            't.*, (SELECT SUM(debit) FROM voucher_lines vl WHERE vl.voucher_id = t.id) AS amount',
        ));
    }

    public function view(CurrentRoute $route): ResponseInterface
    {
        return $this->response->success($this->detail((int) $route->getArgument('id')));
    }

    public function createJournal(ServerRequestInterface $request): ResponseInterface
    {
        $this->context->requireRole(Context::ROLE_ACCOUNTANT);
        $in = Input::fromRequest($request);
        $date = $in->date('voucher_date', default: date('Y-m-d'));
        $narration = $in->string('narration', required: true, max: 255);
        $lineInputs = $in->list('lines', required: true, max: 100);
        $lines = [];
        foreach ($lineInputs as $li) {
            $partyType = $li->enum('party_type', ['tenant', 'vendor']);
            $lines[] = [
                'account_id' => $li->int('account_id', required: true, min: 1),
                'debit' => $li->money('debit', default: '0.00'),
                'credit' => $li->money('credit', default: '0.00'),
                'party_type' => $partyType,
                'party_id' => $partyType === null ? null : $li->int('party_id', required: true, min: 1),
                'narration' => $li->string('narration', max: 255),
            ];
        }
        $in->validate($lineInputs);

        foreach ($lines as $i => $line) {
            $this->repo->assertExists('accounts', $line['account_id'], "lines.$i.account_id", 'Account');
            if ($line['party_type'] !== null) {
                $this->repo->assertExists($line['party_type'] . 's', $line['party_id'], "lines.$i.party_id", ucfirst($line['party_type']));
            }
        }
        $id = $this->posting->post('journal', (string) $date, 'journal', null, (string) $narration, $lines);
        $this->audit->record('create', 'voucher', $id, ['narration' => $narration]);
        return $this->response->created($this->detail($id));
    }

    public function reverse(ServerRequestInterface $request, CurrentRoute $route): ResponseInterface
    {
        $this->context->requireRole(Context::ROLE_ACCOUNTANT);
        $id = (int) $route->getArgument('id');
        $voucher = $this->repo->get('vouchers', $id, 'Voucher');
        if ($voucher['voucher_type'] !== 'journal') {
            throw ApiException::conflict('Only journal vouchers can be reversed here. Cancel the invoice, receipt or bill instead.');
        }
        $in = Input::fromRequest($request);
        $date = $in->date('voucher_date', default: date('Y-m-d'));
        $in->validate();
        $newId = $this->posting->reverse($id, (string) $date, 'Reversal of ' . $voucher['number']);
        $this->audit->record('reverse', 'voucher', $id, ['reversal_id' => $newId]);
        return $this->response->success($this->detail($newId));
    }

    /** @return array<string, mixed> */
    private function detail(int $id): array
    {
        $v = $this->repo->get('vouchers', $id, 'Voucher');
        $v['lines'] = $this->db->all(
            "SELECT vl.*, a.code AS account_code, a.name AS account_name,
                    CASE vl.party_type WHEN 'tenant' THEN t.name WHEN 'vendor' THEN vd.name END AS party_name
             FROM voucher_lines vl JOIN accounts a ON a.id = vl.account_id
             LEFT JOIN tenants t ON vl.party_type = 'tenant' AND t.id = vl.party_id
             LEFT JOIN vendors vd ON vl.party_type = 'vendor' AND vd.id = vl.party_id
             WHERE vl.voucher_id = :id ORDER BY vl.debit DESC, vl.id",
            [':id' => $id],
        );
        return $v;
    }
}
