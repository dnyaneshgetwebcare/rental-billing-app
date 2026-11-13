<?php

declare(strict_types=1);

namespace App\Pdf;

use App\Shared\Settings;

/**
 * Public, expiring links to a document PDF for sharing on WhatsApp: /api/share/invoice/{id}?exp=...&sig=...
 */
final class ShareLink
{
    public const TTL_DAYS = 7;

    public function url(string $type, int $id, ?int $expires = null): string
    {
        $expires ??= time() + self::TTL_DAYS * 86400;
        return sprintf('%s/api/share/%s/%d?exp=%d&sig=%s', Settings::appUrl(), $type, $id, $expires, $this->sign($type, $id, $expires));
    }

    public function verify(string $type, int $id, int $expires, string $signature): bool
    {
        return $expires >= time() && hash_equals($this->sign($type, $id, $expires), $signature);
    }

    private function sign(string $type, int $id, int $expires): string
    {
        $raw = hash_hmac('sha256', "$type:$id:$expires", 'share|' . Settings::jwtSecret(), true);
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
