<?php

declare(strict_types=1);

namespace App\Auth;

use App\Shared\ApiException;
use App\Shared\Settings;

/**
 * Checks a Google Identity Services ID token. Sign-in with Google is free; this only calls Google's tokeninfo endpoint.
 */
final class GoogleIdentity
{
    /**
     * @return array{sub: string, email: string, name: string}
     */
    public function verify(string $idToken): array
    {
        $clientId = Settings::get('GOOGLE_CLIENT_ID');
        if ($clientId === null) {
            throw new ApiException('Google sign-in is not set up yet.', 'google_not_configured', 503);
        }

        $url = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . rawurlencode($idToken);
        $ch = curl_init($url);
        if ($ch === false) {
            throw new ApiException('Google sign-in is unavailable.', 'google_unavailable', 503);
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data) || $status !== 200 || isset($data['error_description']) || isset($data['error'])) {
            throw ApiException::unauthorized('Google sign-in was rejected.');
        }
        $iss = (string) ($data['iss'] ?? '');
        if (!in_array($iss, ['accounts.google.com', 'https://accounts.google.com'], true)) {
            throw ApiException::unauthorized('Google sign-in was rejected.');
        }
        if ((string) ($data['aud'] ?? '') !== $clientId) {
            throw ApiException::unauthorized('Google sign-in was rejected.');
        }
        $verified = $data['email_verified'] ?? false;
        if ($verified !== true && $verified !== 'true') {
            throw ApiException::unauthorized('That Google account has no verified email.');
        }
        $email = strtolower(trim((string) ($data['email'] ?? '')));
        $sub = trim((string) ($data['sub'] ?? ''));
        if ($email === '' || $sub === '') {
            throw ApiException::unauthorized('Google sign-in was rejected.');
        }
        $name = trim((string) ($data['name'] ?? ''));

        return ['sub' => $sub, 'email' => $email, 'name' => $name !== '' ? $name : $email];
    }
}
