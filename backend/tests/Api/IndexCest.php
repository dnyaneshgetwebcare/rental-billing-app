<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\Support\ApiTester;
use Codeception\Util\HttpCode;

final readonly class IndexCest
{
    public function getHome(ApiTester $I): void
    {
        $I->sendGET('/');
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->seeResponseIsJson();
        $I->seeResponseContainsJson(['status' => 'success', 'data' => ['name' => 'Rental Billing API']]);
    }

    public function protectedEndpointNeedsToken(ApiTester $I): void
    {
        $I->sendGET('/v1/me');
        $I->seeResponseCodeIs(HttpCode::UNAUTHORIZED);
        $I->seeResponseContainsJson(['status' => 'failed', 'error' => ['code' => 'unauthorized']]);
    }

    public function companyEndpointNeedsToken(ApiTester $I): void
    {
        $I->haveHttpHeader('X-Company-Id', '1');
        $I->sendGET('/v1/invoices');
        $I->seeResponseCodeIs(HttpCode::UNAUTHORIZED);
    }

    public function badLoginIsRejected(ApiTester $I): void
    {
        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPOST('/v1/auth/login', ['email' => 'nobody@example.com', 'password' => 'wrong-password']);
        $I->seeResponseCodeIs(HttpCode::UNAUTHORIZED);
        $I->seeResponseContainsJson(['status' => 'failed']);
    }

    public function tamperedShareLinkIsGone(ApiTester $I): void
    {
        $I->sendGET('/share/invoice/1?exp=' . (time() + 3600) . '&sig=bad');
        $I->seeResponseCodeIs(410);
    }
}
