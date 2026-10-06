<?php

namespace App\Http\Controllers;

use App\Services\Bitrix24\EntityRestClient;
use App\Services\Bitrix24\EntityStorage;
use App\Services\Bitrix24\InstallationRegistry;
use App\Services\Bitrix24\LaunchVerifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Throwable;

final class Bitrix24AppController extends Controller
{
    public function index(Request $request): Response
    {
        $context = $request->session()->get('bitrix24.context');

        if (! is_array($context) || (int) ($context['expires_at'] ?? 0) <= time()) {
            $request->session()->forget('bitrix24.context');

            return $this->denied();
        }

        if ($request->header('Sec-Fetch-Dest') !== 'iframe') {
            return $this->denied();
        }

        $portal = (string) $context['portal'];

        return response()
            ->view('bitrix24.app')
            ->header('Cache-Control', 'no-store')
            ->header('Content-Security-Policy', "default-src 'self'; frame-ancestors https://{$portal}");
    }

    public function launch(Request $request, LaunchVerifier $verifier): Response|RedirectResponse
    {
        if (! $request->isMethod('post')) {
            return $this->denied();
        }

        $payload = $request->only(['DOMAIN', 'AUTH_ID', 'member_id']);
        $validation = Validator::make($payload, [
            'DOMAIN' => ['required', 'string', 'max:253'],
            'AUTH_ID' => ['required', 'string', 'min:10', 'max:2048'],
            'member_id' => ['required', 'string', 'max:128'],
        ]);

        if ($validation->fails()) {
            return $this->denied(403);
        }

        try {
            $verified = $verifier->verify($payload['DOMAIN'], $payload['AUTH_ID']);
        } catch (Throwable $exception) {
            Log::warning('Bitrix24 launch verification failed.', ['reason' => $exception::class]);

            return $this->denied(403);
        }

        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $request->session()->put('bitrix24.context', [
            ...$verified,
            'member_id' => $payload['member_id'],
            'authorized_at' => time(),
            'expires_at' => time() + config('bitrix24.launch_session_ttl'),
        ]);

        return redirect()->route('bitrix24.app');
    }

    public function install(Request $request, LaunchVerifier $verifier, InstallationRegistry $registry): Response
    {
        if (! $request->isMethod('post')) {
            return $this->denied();
        }

        $payload = $request->only(['DOMAIN', 'AUTH_ID', 'REFRESH_ID', 'AUTH_EXPIRES', 'member_id']);
        $validation = Validator::make($payload, [
            'DOMAIN' => ['required', 'string', 'max:253'],
            'AUTH_ID' => ['required', 'string', 'min:10', 'max:2048'],
            'REFRESH_ID' => ['required', 'string', 'min:10', 'max:2048'],
            'AUTH_EXPIRES' => ['required', 'integer', 'min:1', 'max:86400'],
            'member_id' => ['required', 'string', 'max:128'],
        ]);

        if ($validation->fails()) {
            return $this->denied(403);
        }

        try {
            $verified = $verifier->verify($payload['DOMAIN'], $payload['AUTH_ID'], false);
            $client = app()->makeWith(EntityRestClient::class, ['portal' => $verified['portal'], 'accessToken' => $payload['AUTH_ID']]);
            $storage = new EntityStorage($client, config('bitrix24.entity'));
            $storage->ensureExists('Skill test storage');
            $registry->save($verified['portal'], [
                'member_id' => $payload['member_id'],
                'app_code' => $verified['app_code'],
                'access_token' => $payload['AUTH_ID'],
                'refresh_token' => $payload['REFRESH_ID'],
                'expires_at' => time() + (int) $payload['AUTH_EXPIRES'],
            ]);
        } catch (Throwable $exception) {
            Log::warning('Bitrix24 installation verification failed.', ['reason' => $exception::class]);

            return $this->denied(403);
        }

        $nonce = base64_encode(random_bytes(18));

        return response()
            ->view('bitrix24.install', ['nonce' => $nonce])
            ->header('Cache-Control', 'no-store')
            ->header('Content-Security-Policy', "default-src 'self'; script-src 'self' https://api.bitrix24.com https://api.bitrix24.tech 'nonce-{$nonce}'; frame-ancestors https://{$verified['portal']}");
    }

    private function denied(int $status = 200): Response
    {
        return response()
            ->view('bitrix24.gate', status: $status)
            ->header('Cache-Control', 'no-store')
            ->header('Content-Security-Policy', "default-src 'self'; style-src 'unsafe-inline'; frame-ancestors 'none'");
    }
}
