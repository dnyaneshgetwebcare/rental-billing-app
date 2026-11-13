<?php

declare(strict_types=1);

use App\Accounting\VoucherAction;
use App\Agreement\AgreementAction;
use App\Api;
use App\Attachment\AttachmentAction;
use App\Auth\AuthAction;
use App\Auth\AuthMiddleware;
use App\Auth\CompanyMiddleware;
use App\Company\CompanyAction;
use App\Company\UserAction;
use App\Expense\ExpenseAction;
use App\Invoice\InvoiceAction;
use App\Master\MasterAction;
use App\Pdf\PublicShareAction;
use App\Purchase\PurchaseAction;
use App\Receipt\ReceiptAction;
use App\Report\DashboardAction;
use App\Report\ReportAction;
use Yiisoft\Router\Group;
use Yiisoft\Router\Route;

/**
 * @var array $params
 */

$id = '{id:\d+}';
$masters = '{resource:tenants|vendors|warehouses|units|tax-rates|accounts}';

return [
    Group::create('/api')->routes(
        Route::get('[/]')->action(Api\IndexAction::class)->name('app/index'),
        Route::get("/share/invoice/$id")->action([PublicShareAction::class, 'invoice'])->name('share/invoice'),

        Group::create('/v1')->routes(
            Route::post('/auth/login')->action([AuthAction::class, 'login']),
            Route::post('/auth/google')->action([AuthAction::class, 'google']),
            Route::post('/auth/refresh')->action([AuthAction::class, 'refresh']),
            Route::post('/auth/logout')->action([AuthAction::class, 'logout']),

            Group::create()
                ->middleware(AuthMiddleware::class)
                ->routes(
                    Route::get('/me')->action([AuthAction::class, 'me']),
                    Route::post('/auth/change-password')->action([AuthAction::class, 'changePassword']),
                    Route::post('/companies')->action([CompanyAction::class, 'create']),

                    Group::create()
                        ->middleware(CompanyMiddleware::class)
                        ->routes(
                            Route::get('/company')->action([CompanyAction::class, 'current']),
                            Route::put('/company')->action([CompanyAction::class, 'update']),

                            Route::get('/users')->action([UserAction::class, 'list']),
                            Route::post('/users')->action([UserAction::class, 'create']),
                            Route::put("/users/$id")->action([UserAction::class, 'update']),
                            Route::delete("/users/$id")->action([UserAction::class, 'delete']),

                            Route::get('/dashboard')->action([DashboardAction::class, 'summary']),
                            Route::get('/audit-logs')->action([DashboardAction::class, 'auditLogs']),

                            Route::get('/agreements/upcoming-bills')->action([AgreementAction::class, 'upcoming']),
                            Route::post('/agreements/run-billing')->action([AgreementAction::class, 'runBilling']),
                            Route::get('/agreements')->action([AgreementAction::class, 'list']),
                            Route::post('/agreements')->action([AgreementAction::class, 'create']),
                            Route::get("/agreements/$id")->action([AgreementAction::class, 'view']),
                            Route::put("/agreements/$id")->action([AgreementAction::class, 'update']),
                            Route::post("/agreements/$id/end")->action([AgreementAction::class, 'end']),

                            Route::get('/invoices')->action([InvoiceAction::class, 'list']),
                            Route::post('/invoices')->action([InvoiceAction::class, 'create']),
                            Route::get("/invoices/$id")->action([InvoiceAction::class, 'view']),
                            Route::post("/invoices/$id/cancel")->action([InvoiceAction::class, 'cancel']),
                            Route::get("/invoices/$id/pdf")->action([InvoiceAction::class, 'pdf']),
                            Route::post("/invoices/$id/share-link")->action([InvoiceAction::class, 'shareLink']),

                            Route::get('/credit-notes')->action([InvoiceAction::class, 'creditNoteList']),
                            Route::post('/credit-notes')->action([InvoiceAction::class, 'creditNoteCreate']),
                            Route::get("/credit-notes/$id")->action([InvoiceAction::class, 'creditNoteView']),
                            Route::post("/credit-notes/$id/cancel")->action([InvoiceAction::class, 'creditNoteCancel']),
                            Route::get("/credit-notes/$id/pdf")->action([InvoiceAction::class, 'creditNotePdf']),

                            Route::get('/receipts')->action([ReceiptAction::class, 'list']),
                            Route::post('/receipts')->action([ReceiptAction::class, 'create']),
                            Route::get("/receipts/$id")->action([ReceiptAction::class, 'view']),
                            Route::post("/receipts/$id/cancel")->action([ReceiptAction::class, 'cancel']),

                            Route::get('/purchases')->action([PurchaseAction::class, 'list']),
                            Route::post('/purchases')->action([PurchaseAction::class, 'create']),
                            Route::get("/purchases/$id")->action([PurchaseAction::class, 'view']),
                            Route::post("/purchases/$id/cancel")->action([PurchaseAction::class, 'cancel']),
                            Route::get('/vendor-payments')->action([PurchaseAction::class, 'paymentList']),
                            Route::post('/vendor-payments')->action([PurchaseAction::class, 'paymentCreate']),
                            Route::post("/vendor-payments/$id/cancel")->action([PurchaseAction::class, 'paymentCancel']),

                            Route::get('/expenses')->action([ExpenseAction::class, 'list']),
                            Route::post('/expenses')->action([ExpenseAction::class, 'create']),
                            Route::get("/expenses/$id")->action([ExpenseAction::class, 'view']),
                            Route::post("/expenses/$id/cancel")->action([ExpenseAction::class, 'cancel']),

                            Route::get('/vouchers')->action([VoucherAction::class, 'list']),
                            Route::post('/vouchers')->action([VoucherAction::class, 'createJournal']),
                            Route::get("/vouchers/$id")->action([VoucherAction::class, 'view']),
                            Route::post("/vouchers/$id/reverse")->action([VoucherAction::class, 'reverse']),

                            Route::get('/attachments')->action([AttachmentAction::class, 'list']),
                            Route::post('/attachments')->action([AttachmentAction::class, 'upload']),
                            Route::delete("/attachments/$id")->action([AttachmentAction::class, 'delete']),

                            Route::get('/reports/ledger')->action([ReportAction::class, 'ledger']),
                            Route::get('/reports/trial-balance')->action([ReportAction::class, 'trialBalance']),
                            Route::get('/reports/outstanding')->action([ReportAction::class, 'outstanding']),
                            Route::get('/reports/party-ledger')->action([ReportAction::class, 'partyLedger']),
                            Route::get('/reports/gst-summary')->action([ReportAction::class, 'gstSummary']),
                            Route::get('/reports/sales-register')->action([ReportAction::class, 'salesRegister']),

                            Route::get("/$masters")->action([MasterAction::class, 'list']),
                            Route::post("/$masters")->action([MasterAction::class, 'create']),
                            Route::get("/$masters/$id")->action([MasterAction::class, 'view']),
                            Route::put("/$masters/$id")->action([MasterAction::class, 'update']),
                            Route::delete("/$masters/$id")->action([MasterAction::class, 'delete']),
                        ),
                ),
        ),
    ),
];
