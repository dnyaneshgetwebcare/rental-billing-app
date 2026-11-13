<?php

declare(strict_types=1);

namespace App\Agreement;

use App\Api\Shared\ResponseFactory;
use App\Shared\CompanyScopedRepository;
use App\Shared\Context;
use App\Shared\Input;
use App\Shared\Page;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\CurrentRoute;

final readonly class AgreementAction
{
    public function __construct(
        private AgreementService $agreements,
        private AutoBillingService $billing,
        private CompanyScopedRepository $repo,
        private Context $context,
        private ResponseFactory $response,
    ) {}

    public function list(ServerRequestInterface $request): ResponseInterface
    {
        $q = $request->getQueryParams();
        $conditions = ['1 = 1'];
        $params = [];
        if (!empty($q['status'])) {
            $conditions[] = 't.status = :status';
            $params[':status'] = (string) $q['status'];
        }
        if (!empty($q['tenant_id'])) {
            $conditions[] = 't.tenant_id = :tenant';
            $params[':tenant'] = (int) $q['tenant_id'];
        }
        if (!empty($q['q'])) {
            $conditions[] = '(tn.name LIKE :q OR u.code LIKE :q OR t.agreement_no LIKE :q)';
            $params[':q'] = '%' . addcslashes((string) $q['q'], '%_\\') . '%';
        }
        return $this->response->success($this->repo->paginate(
            'agreements t JOIN tenants tn ON tn.id = t.tenant_id JOIN units u ON u.id = t.unit_id JOIN warehouses w ON w.id = u.warehouse_id',
            implode(' AND ', $conditions),
            $params,
            "t.status = 'active' DESC, tn.name",
            Page::fromRequest($request),
            't.*, tn.name AS tenant_name, u.code AS unit_code, w.name AS warehouse_name',
        ));
    }

    public function view(CurrentRoute $route): ResponseInterface
    {
        return $this->response->success($this->agreements->detail((int) $route->getArgument('id')));
    }

    public function create(ServerRequestInterface $request): ResponseInterface
    {
        $this->context->requireRole(Context::ROLE_ACCOUNTANT);
        ['row' => $row, 'charges' => $charges] = $this->agreements->fields(Input::fromRequest($request), creating: true);
        $id = $this->agreements->save(null, $row, $charges);
        return $this->response->created($this->agreements->detail($id));
    }

    public function update(ServerRequestInterface $request, CurrentRoute $route): ResponseInterface
    {
        $this->context->requireRole(Context::ROLE_ACCOUNTANT);
        $id = (int) $route->getArgument('id');
        $this->repo->get('agreements', $id, 'Agreement');
        ['row' => $row, 'charges' => $charges] = $this->agreements->fields(Input::fromRequest($request), creating: false, id: $id);
        $this->agreements->save($id, $row, $charges);
        return $this->response->success($this->agreements->detail($id));
    }

    public function end(ServerRequestInterface $request, CurrentRoute $route): ResponseInterface
    {
        $this->context->requireRole(Context::ROLE_ACCOUNTANT);
        $in = Input::fromRequest($request);
        $endDate = $in->date('end_date', required: true);
        $in->validate();
        $id = (int) $route->getArgument('id');
        $this->agreements->end($id, (string) $endDate);
        return $this->response->success($this->agreements->detail($id));
    }

    /** Bills that the next cron run will create, plus those coming later this month. */
    public function upcoming(): ResponseInterface
    {
        $today = date('Y-m-d');
        $dueNow = $this->billing->due($today);
        $all = $this->billing->due($today, includeFuture: true);
        $nowKeys = array_flip(array_map(static fn($d) => $d['agreement_id'] . '|' . $d['period'], $dueNow));
        $strip = static fn(array $d): array => array_diff_key($d, ['lines' => true]);
        return $this->response->success([
            'due_now' => array_map($strip, $dueNow),
            'later_this_month' => array_values(array_map($strip, array_filter(
                $all,
                static fn($d) => !isset($nowKeys[$d['agreement_id'] . '|' . $d['period']]),
            ))),
        ]);
    }

    /** "Generate bills now" button: same as the cron, for the current company only. */
    public function runBilling(): ResponseInterface
    {
        $this->context->requireRole(Context::ROLE_ACCOUNTANT);
        $result = $this->billing->run(date('Y-m-d'), $this->context->companyId());
        $strip = static fn(array $d): array => array_diff_key($d, ['lines' => true]);
        return $this->response->success([
            'created' => array_map($strip, $result['created']),
            'skipped' => $result['skipped'],
            'errors' => $result['errors'],
        ]);
    }
}
