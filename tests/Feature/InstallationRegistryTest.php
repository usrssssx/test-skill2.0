<?php

namespace Tests\Feature;

use App\Services\Bitrix24\InstallationRegistry;
use App\Services\Bitrix24\OAuthTokenManager;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class InstallationRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('bitrix24.registry_path', sys_get_temp_dir().'/entity-registry-test-'.bin2hex(random_bytes(8)));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(config('bitrix24.registry_path'));
        parent::tearDown();
    }

    public function test_credentials_are_encrypted_private_and_portal_scoped(): void
    {
        $registry = app(InstallationRegistry::class);
        $registry->save('example.bitrix24.ru', ['access_token' => 'secret-token']);
        $registry->save('other.bitrix24.ru', ['access_token' => 'other-token']);
        $path = config('bitrix24.registry_path').'/'.hash('sha256', 'example.bitrix24.ru');
        $this->assertStringNotContainsString('secret-token', file_get_contents($path));
        $this->assertSame(0600, fileperms($path) & 0777);
        $this->assertSame('secret-token', $registry->read('example.bitrix24.ru')['access_token']);
        $this->assertSame('other-token', $registry->read('other.bitrix24.ru')['access_token']);
    }

    public function test_refresh_rotates_both_tokens_and_persists_them(): void
    {
        $registry = app(InstallationRegistry::class);
        $registry->save('example.bitrix24.ru', ['access_token' => 'old', 'refresh_token' => 'old-refresh', 'member_id' => 'member', 'expires_at' => 1]);
        config()->set(['bitrix24.client_id' => 'client', 'bitrix24.client_secret' => 'secret']);
        Http::fake(['https://oauth.bitrix24.tech/oauth/token/*' => Http::response([
            'access_token' => 'new', 'refresh_token' => 'new-refresh', 'member_id' => 'member', 'expires_in' => 3600,
        ])]);
        $credentials = app(OAuthTokenManager::class)->credentials('example.bitrix24.ru');
        $this->assertSame('new-refresh', $credentials['refresh_token']);
        $this->assertSame('new', $registry->read('example.bitrix24.ru')['access_token']);
        app(OAuthTokenManager::class)->credentials('example.bitrix24.ru');
        Http::assertSentCount(1);
    }

    public function test_failed_refresh_preserves_previous_credentials(): void
    {
        $registry = app(InstallationRegistry::class);
        $original = ['access_token' => 'old', 'refresh_token' => 'old-refresh', 'member_id' => 'member', 'expires_at' => 1];
        $registry->save('example.bitrix24.ru', $original);
        config()->set(['bitrix24.client_id' => 'client', 'bitrix24.client_secret' => 'secret']);
        Http::fake(['*' => Http::response(['error' => 'invalid_grant'], 401)]);
        try {
            app(OAuthTokenManager::class)->credentials('example.bitrix24.ru');
            $this->fail('Refresh must reject invalid credentials.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('OAuth renewal was not confirmed.', $exception->getMessage());
        }
        $this->assertSame($original, $registry->read('example.bitrix24.ru'));
    }
}
