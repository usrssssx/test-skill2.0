<?php

namespace App\Services\Bitrix24;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class EntityRestClient
{
    public function __construct(
        public readonly string $portal,
        #[\SensitiveParameter] private readonly string $accessToken,
        private readonly ?string $pinnedAddress = null,
    ) {}

    public function call(string $method, array $parameters): array
    {
        if (! preg_match('/^[a-z0-9-]+\.bitrix24\.(ru|com|de|eu|es|ua|by|kz)$/', $this->portal)
            || ! in_array($method, ['entity.add', 'entity.get', 'entity.item.add', 'entity.item.get', 'entity.item.update', 'entity.item.delete'], true)) {
            throw new RuntimeException('Invalid portal or storage method.');
        }

        $addresses = $this->pinnedAddress !== null ? [$this->pinnedAddress] : (gethostbynamel($this->portal) ?: []);
        $address = null;
        foreach ($addresses as $candidate) {
            if (filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                $address = $candidate;
                break;
            }
        }
        if ($address === null) {
            throw new RuntimeException('Portal has no verified public address.');
        }

        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $response = Http::asForm()->acceptJson()->connectTimeout(3)->timeout(15)
                    ->withOptions(['allow_redirects' => false, 'curl' => [CURLOPT_RESOLVE => ["{$this->portal}:443:{$address}"]]])
                    ->post("https://{$this->portal}/rest/{$method}.json", [...$parameters, 'auth' => $this->accessToken]);
            } catch (\Throwable) {
                // Mutation timeouts are ambiguous; do not replay a possibly completed write.
                throw new RuntimeException('Bitrix24 transport failed; result is unknown.');
            }

            $body = $response->json();
            $code = is_array($body) ? ($body['error'] ?? null) : null;
            if ($code === 'QUERY_LIMIT_EXCEEDED' && $attempt < 2) {
                usleep(100000 * ($attempt + 1));

                continue;
            }
            if (! $response->successful() || ! is_array($body) || $code !== null || ! array_key_exists('result', $body)) {
                $safeCode = is_string($code) && preg_match('/^[a-zA-Z0-9_]{1,80}$/', $code) ? $code : 'INVALID_RESPONSE';
                throw new EntityRestException($safeCode);
            }

            return $body;
        }

        throw new EntityRestException('QUERY_LIMIT_EXCEEDED');
    }
}
