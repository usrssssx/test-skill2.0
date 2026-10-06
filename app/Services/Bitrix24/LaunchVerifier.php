<?php

namespace App\Services\Bitrix24;

use Illuminate\Support\Facades\Http;
use RuntimeException;

final class LaunchVerifier
{
    /** @return array{portal: string, app_id: int, app_code: string, installed: bool} */
    public function verify(string $portal, string $accessToken, bool $requireInstalled = true): array
    {
        $host = $this->normalizeHost($portal);
        if (config('bitrix24.portal') && $host !== config('bitrix24.portal')) {
            throw new RuntimeException('This portal is not configured for the application.');
        }
        $pinnedAddress = $this->trustedAddress($host);
        $options = ['allow_redirects' => false];

        if ($pinnedAddress !== null) {
            if (! defined('CURLOPT_RESOLVE')) {
                throw new RuntimeException('Secure portal resolution is unavailable.');
            }

            $options['curl'] = [CURLOPT_RESOLVE => ["{$host}:443:{$pinnedAddress}"]];
        }

        $response = Http::asForm()
            ->acceptJson()
            ->connectTimeout(min(3, config('bitrix24.launch_timeout')))
            ->timeout(config('bitrix24.launch_timeout'))
            ->withOptions($options)
            ->post("https://{$host}/rest/app.info.json", ['auth' => $accessToken]);

        $result = $response->successful() ? $response->json('result') : null;

        $installed = is_array($result) && ($result['INSTALLED'] ?? false) === true;

        if (! is_array($result)
            || ! is_numeric($result['ID'] ?? null)
            || ! is_string($result['CODE'] ?? null)
            || ($result['CODE'] ?? '') === ''
            || (config('bitrix24.client_id') && $result['CODE'] !== config('bitrix24.client_id'))
            || ($requireInstalled && ! $installed)) {
            throw new RuntimeException('Bitrix24 rejected the application context.');
        }

        return [
            'portal' => $host,
            'app_id' => (int) $result['ID'],
            'app_code' => $result['CODE'],
            'installed' => $installed,
        ];
    }

    private function normalizeHost(string $portal): string
    {
        $host = strtolower(trim($portal));

        if ($host === ''
            || strlen($host) > 253
            || filter_var($host, FILTER_VALIDATE_IP)
            || ! preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $host)) {
            throw new RuntimeException('Invalid Bitrix24 portal host.');
        }

        return $host;
    }

    private function trustedAddress(string $host): ?string
    {
        if (in_array($host, config('bitrix24.allowed_portal_hosts', []), true)) {
            return null;
        }

        $addresses = gethostbynamel($host) ?: [];

        foreach ($addresses as $address) {
            if (filter_var(
                $address,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
            )) {
                return $address;
            }
        }

        throw new RuntimeException('Bitrix24 portal did not resolve to a public address.');
    }
}
