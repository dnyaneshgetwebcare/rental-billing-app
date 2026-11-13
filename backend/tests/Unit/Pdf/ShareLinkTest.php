<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pdf;

use App\Pdf\ShareLink;
use Codeception\Test\Unit;

final class ShareLinkTest extends Unit
{
    protected function _before(): void
    {
        putenv('JWT_SECRET=' . str_repeat('k', 40));
        putenv('APP_URL=https://billing.example.com');
    }

    protected function _after(): void
    {
        putenv('JWT_SECRET');
        putenv('APP_URL');
    }

    public function testSignedLinkVerifies(): void
    {
        $link = new ShareLink();
        $url = $link->url('invoice', 15);
        $this->assertStringStartsWith('https://billing.example.com/api/share/invoice/15?exp=', $url);

        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $this->assertTrue($link->verify('invoice', 15, (int) $q['exp'], (string) $q['sig']));
        $this->assertFalse($link->verify('invoice', 16, (int) $q['exp'], (string) $q['sig']));
        $this->assertFalse($link->verify('invoice', 15, (int) $q['exp'] + 1, (string) $q['sig']));
    }

    public function testExpiredLinkIsRejected(): void
    {
        $link = new ShareLink();
        $exp = time() - 10;
        parse_str((string) parse_url($link->url('invoice', 1, $exp), PHP_URL_QUERY), $q);
        $this->assertFalse($link->verify('invoice', 1, $exp, (string) $q['sig']));
    }
}
