<?php

namespace App\Services\Bitrix24;

use App\Contracts\RecordStorage;
use InvalidArgumentException;
use RuntimeException;

final class EntityStorage implements RecordStorage
{
    public function __construct(private readonly EntityRestClient $client, private readonly string $entity)
    {
        if (! preg_match('/^[a-zA-Z0-9_]{1,12}$/', $entity)) {
            throw new InvalidArgumentException('Use a short application storage identifier.');
        }
    }

    public function ensureExists(string $name): void
    {
        try {
            $this->requireSuccess($this->client->call('entity.add', ['ENTITY' => $this->entity, 'NAME' => $name]));
        } catch (EntityRestException $exception) {
            if ($exception->errorCode !== 'ERROR_ENTITY_ALREADY_EXISTS') {
                throw $exception;
            }
        }
    }

    public function create(string $name): int
    {
        $result = $this->client->call('entity.item.add', ['ENTITY' => $this->entity, 'NAME' => $name])['result'];
        if (! is_numeric($result) || (int) $result < 1) {
            throw new RuntimeException('Invalid storage item identifier.');
        }

        return (int) $result;
    }

    public function find(int $id): ?array
    {
        $this->validateId($id);
        $items = $this->client->call('entity.item.get', ['ENTITY' => $this->entity, 'FILTER' => ['ID' => $id]])['result'];
        if (! is_array($items)) {
            throw new RuntimeException('Invalid storage item list.');
        }
        foreach ($items as $item) {
            if (is_array($item) && (int) ($item['ID'] ?? 0) === $id) {
                return $item;
            }
        }

        return null;
    }

    public function rename(int $id, string $name): void
    {
        $this->validateId($id);
        $this->requireSuccess($this->client->call('entity.item.update', ['ENTITY' => $this->entity, 'ID' => $id, 'NAME' => $name]));
    }

    public function delete(int $id): void
    {
        $this->validateId($id);
        $this->requireSuccess($this->client->call('entity.item.delete', ['ENTITY' => $this->entity, 'ID' => $id]));
    }

    private function requireSuccess(array $response): void
    {
        if ($response['result'] !== true) {
            throw new RuntimeException('Storage mutation was not confirmed.');
        }
    }

    private function validateId(int $id): void
    {
        if ($id < 1) {
            throw new InvalidArgumentException('Storage item ID must be positive.');
        }
    }
}
