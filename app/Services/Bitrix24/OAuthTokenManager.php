<?php

namespace App\Services\Bitrix24;

use Illuminate\Support\Facades\Http;
use RuntimeException;

final class OAuthTokenManager
{
    public function __construct(private readonly InstallationRegistry $registry) {}

    public function credentials(string $portal, bool $forceRefresh = false): array
    {
        return $this->registry->update($portal, function (array $current) use ($forceRefresh): array {
            if (! $forceRefresh && $current['expires_at'] > time() + 60) {
                return $current;
            }
            $clientId = config('bitrix24.client_id');
            $secret = config('bitrix24.client_secret');
            if (! $clientId || ! $secret) {
                throw new RuntimeException('OAuth client configuration is missing.');
            }
            try {
                $response = Http::acceptJson()->withOptions(['allow_redirects' => false])
                    ->connectTimeout(3)->timeout(15)
                    ->get('https://oauth.bitrix24.tech/oauth/token/', [
                        'grant_type' => 'refresh_token',
                        'client_id' => $clientId,
                        'client_secret' => $secret,
                        'refresh_token' => $current['refresh_token'],
                    ]);
            } catch (\Throwable) {
                throw new RuntimeException('OAuth renewal transport failed.');
            }
            $result = $response->json();
            if (! $response->successful() || ! is_array($result)
                || ! is_string($result['access_token'] ?? null)
                || ! is_string($result['refresh_token'] ?? null)
                || ($result['member_id'] ?? null) !== $current['member_id']
                || ! is_numeric($result['expires_in'] ?? null)
                || (int) $result['expires_in'] < 1) {
                throw new RuntimeException('OAuth renewal was not confirmed.');
            }

            return [...$current, 'access_token' => $result['access_token'],
                'refresh_token' => $result['refresh_token'],
                'expires_at' => time() + (int) $result['expires_in']];
        });
    }
}
