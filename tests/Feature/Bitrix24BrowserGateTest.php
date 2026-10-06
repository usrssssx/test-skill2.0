<?php

namespace Tests\Feature;

use App\Services\Bitrix24\EntityRestClient;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class Bitrix24BrowserGateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('bitrix24.registry_path', sys_get_temp_dir().'/entity-gate-test-'.bin2hex(random_bytes(8)));
        $this->app->bind(EntityRestClient::class, static fn ($app, $parameters) => new EntityRestClient($parameters['portal'], $parameters['accessToken'], '8.8.8.8'));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(config('bitrix24.registry_path'));
        parent::tearDown();
    }

    public function test_direct_browser_access_is_denied(): void
    {
        $this->assertFileExists(public_path('brand/business-base-logo.png'));

        $this->get('/')
            ->assertOk()
            ->assertSeeText('Приложение доступно только внутри Битрикс24')
            ->assertSeeText('Вас приветствует команда')
            ->assertSeeText('База Бизнеса')
            ->assertSee('/brand/business-base-logo.png', false)
            ->assertHeader('Content-Security-Policy', "default-src 'self'; style-src 'unsafe-inline'; frame-ancestors 'none'");
    }

    public function test_browser_headers_do_not_grant_access(): void
    {
        $this->withHeaders([
            'Referer' => 'https://example.bitrix24.ru/',
            'Sec-Fetch-Dest' => 'iframe',
        ])->get('/')->assertSeeText('Приложение доступно только внутри Битрикс24');
    }

    public function test_direct_bitrix24_endpoints_are_denied(): void
    {
        foreach (['/bitrix24/launch', '/bitrix24/install', '/bitrix24/settings'] as $path) {
            $this->get($path)
                ->assertOk()
                ->assertSeeText('Приложение доступно только внутри Битрикс24');
        }
    }

    public function test_verified_installation_context_renders_install_finish_page(): void
    {
        Http::fake([
            'https://example.bitrix24.ru/rest/entity.add.json' => Http::response(['result' => true]),
            'https://example.bitrix24.ru/rest/app.info.json' => Http::response([
                'result' => [
                    'ID' => 17,
                    'CODE' => 'vendor.application',
                    'INSTALLED' => false,
                ],
            ]),
        ]);
        config()->set('bitrix24.allowed_portal_hosts', ['example.bitrix24.ru']);

        $this->post('/bitrix24/install', [
            'DOMAIN' => 'example.bitrix24.ru',
            'AUTH_ID' => 'valid-access-token',
            'REFRESH_ID' => 'valid-refresh-token',
            'AUTH_EXPIRES' => 3600,
            'member_id' => 'portal-member-id',
        ])->assertOk()
            ->assertSee('window.BX24.installFinish()', false)
            ->assertSee('script.onload = finish', false)
            ->assertDontSee('valid-access-token')
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_invalid_launch_does_not_create_a_session(): void
    {
        Http::fake([
            'https://example.bitrix24.ru/rest/app.info.json' => Http::response([
                'error' => 'expired_token',
            ], 401),
        ]);
        config()->set('bitrix24.allowed_portal_hosts', ['example.bitrix24.ru']);

        $this->post('/bitrix24/launch', [
            'DOMAIN' => 'example.bitrix24.ru',
            'AUTH_ID' => 'expired-access-token',
            'member_id' => 'portal-member-id',
        ])->assertForbidden()->assertSeeText('Приложение доступно только внутри Битрикс24');

        $this->assertFalse(session()->has('bitrix24.context'));
    }

    public function test_verified_launch_opens_the_empty_application_shell(): void
    {
        Http::fake([
            'https://example.bitrix24.ru/rest/app.info.json' => Http::response([
                'result' => [
                    'ID' => 17,
                    'CODE' => 'vendor.application',
                    'INSTALLED' => true,
                ],
            ]),
        ]);
        config()->set('bitrix24.allowed_portal_hosts', ['example.bitrix24.ru']);

        $this->post('/bitrix24/launch', [
            'DOMAIN' => 'example.bitrix24.ru',
            'AUTH_ID' => 'valid-access-token',
            'member_id' => 'portal-member-id',
        ])->assertRedirect('/');

        $this->get('/')
            ->assertOk()
            ->assertSeeText('Приложение доступно только внутри Битрикс24');

        $this->withHeader('Sec-Fetch-Dest', 'iframe')->get('/')
            ->assertOk()
            ->assertDontSee('valid-access-token')
            ->assertHeader(
                'Content-Security-Policy',
                "default-src 'self'; frame-ancestors https://example.bitrix24.ru",
            );

        $this->assertSame('example.bitrix24.ru', session('bitrix24.context.portal'));
        $this->assertArrayNotHasKey('access_token', session('bitrix24.context'));
    }

    public function test_expired_application_session_returns_to_the_gate(): void
    {
        $this->withSession([
            'bitrix24.context' => [
                'portal' => 'example.bitrix24.ru',
                'expires_at' => time() - 1,
            ],
        ])->get('/')->assertSeeText('Приложение доступно только внутри Битрикс24');
    }
}
