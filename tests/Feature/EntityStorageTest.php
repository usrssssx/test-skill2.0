<?php

namespace Tests\Feature;

use App\Services\Bitrix24\EntityRestClient;
use App\Services\Bitrix24\EntityRestException;
use App\Services\Bitrix24\EntityStorage;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class EntityStorageTest extends TestCase
{
    private function storage(string $portal = 'example.bitrix24.ru', string $token = 'test-secret'): EntityStorage
    {
        Http::preventStrayRequests();

        return new EntityStorage(new EntityRestClient($portal, $token, '93.184.216.34'), 'skill_test');
    }

    public function test_crud_contract_and_missing_record(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push(['result' => true])
            ->push(['result' => 17])
            ->push(['result' => [['ID' => '17', 'NAME' => 'before']]])
            ->push(['result' => true])
            ->push(['result' => [['ID' => '17', 'NAME' => 'after']]])
            ->push(['result' => true])
            ->push(['result' => []]),
        ]);
        $storage = $this->storage();
        $storage->ensureExists('Test');
        $id = $storage->create('before');
        $this->assertSame(17, $id);
        $this->assertSame('before', $storage->find($id)['NAME']);
        $storage->rename($id, 'after');
        $this->assertSame('after', $storage->find($id)['NAME']);
        $storage->delete($id);
        $this->assertNull($storage->find($id));
        Http::assertSentCount(7);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/entity.item.update.json')
            && $request['ID'] === 17 && $request['NAME'] === 'after' && $request['ENTITY'] === 'skill_test');
    }

    public function test_existing_storage_is_idempotent(): void
    {
        Http::fake(['*' => Http::response(['error' => 'ERROR_ENTITY_ALREADY_EXISTS'])]);
        $this->storage()->ensureExists('Test');
        Http::assertSentCount(1);
    }

    public function test_portal_contexts_do_not_share_authentication(): void
    {
        Http::fake(['*' => Http::response(['result' => []])]);
        $this->storage('first.bitrix24.ru', 'first-token')->find(1);
        $this->storage('second.bitrix24.ru', 'second-token')->find(1);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'first.bitrix24.ru') && $request['auth'] === 'first-token');
        Http::assertSent(fn ($request) => str_contains($request->url(), 'second.bitrix24.ru') && $request['auth'] === 'second-token');
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'first.bitrix24.ru') && $request['auth'] === 'second-token');
    }

    public function test_expired_token_does_not_leak_credentials(): void
    {
        Http::fake(['*' => Http::response(['error' => 'expired_token', 'error_description' => 'contains test-secret'])]);
        try {
            $this->storage()->find(1);
            $this->fail('Expired token should be rejected.');
        } catch (EntityRestException $exception) {
            $this->assertSame('expired_token', $exception->errorCode);
            $this->assertStringNotContainsString('test-secret', $exception->getMessage());
        }
        Http::assertSentCount(1);
    }

    public function test_rate_limit_has_bounded_retries(): void
    {
        Http::fake(['*' => Http::sequence()->push(['error' => 'QUERY_LIMIT_EXCEEDED'], 429)
            ->push(['error' => 'QUERY_LIMIT_EXCEEDED'], 429)->push(['result' => []])]);
        $this->assertNull($this->storage()->find(1));
        Http::assertSentCount(3);
    }

    public function test_ambiguous_write_failure_is_not_replayed(): void
    {
        Http::fake(['*' => Http::response('bad gateway test-secret', 502)]);
        try {
            $this->storage()->create('Test');
            $this->fail('Failure should be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringNotContainsString('test-secret', $exception->getMessage());
        }
        Http::assertSentCount(1);
    }

    public function test_private_network_endpoint_is_rejected(): void
    {
        Http::fake();
        $client = new EntityRestClient('example.bitrix24.ru', 'test-secret', '127.0.0.1');
        try {
            $client->call('entity.get', []);
            $this->fail('Private address should be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Portal has no verified public address.', $exception->getMessage());
        }
        Http::assertNothingSent();
    }
}
