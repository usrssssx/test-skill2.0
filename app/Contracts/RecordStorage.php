<?php

namespace App\Contracts;

interface RecordStorage
{
    public function create(string $name): int;

    public function find(int $id): ?array;

    public function rename(int $id, string $name): void;

    public function delete(int $id): void;
}
