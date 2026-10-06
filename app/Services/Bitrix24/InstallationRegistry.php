<?php

namespace App\Services\Bitrix24;

use Illuminate\Support\Facades\Crypt;
use RuntimeException;

final class InstallationRegistry
{
    public function read(string $portal): array
    {
        return $this->locked($portal, fn (string $path): array => $this->load($path));
    }

    public function save(string $portal, #[\SensitiveParameter] array $credentials): void
    {
        $this->locked($portal, function (string $path) use ($credentials): void {
            $this->write($path, $credentials);
        });
    }

    public function update(string $portal, callable $update): array
    {
        return $this->locked($portal, function (string $path) use ($update): array {
            $next = $update($this->load($path));
            $this->write($path, $next);

            return $next;
        });
    }

    private function load(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException('Application installation is missing.');
        }

        return json_decode(Crypt::decryptString(file_get_contents($path)), true, 512, JSON_THROW_ON_ERROR);
    }

    private function locked(string $portal, callable $operation): mixed
    {
        if (! preg_match('/^[a-z0-9-]+\.bitrix24\.(ru|com|de|eu|es|ua|by|kz)$/', $portal)) {
            throw new RuntimeException('Invalid installation portal.');
        }
        $directory = config('bitrix24.registry_path');
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Installation registry is unavailable.');
        }
        $path = $directory.'/'.hash('sha256', $portal);
        $lock = fopen($path.'.lock', 'c');
        if ($lock === false) {
            throw new RuntimeException('Installation lock is unavailable.');
        }
        chmod($path.'.lock', 0600);
        try {
            if (! flock($lock, LOCK_EX)) {
                throw new RuntimeException('Installation lock failed.');
            }

            return $operation($path);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function write(string $path, #[\SensitiveParameter] array $credentials): void
    {
        $temporary = tempnam(dirname($path), '.installation-');
        if ($temporary === false) {
            throw new RuntimeException('Installation persistence failed.');
        }
        try {
            chmod($temporary, 0600);
            $encrypted = Crypt::encryptString(json_encode($credentials, JSON_THROW_ON_ERROR));
            if (file_put_contents($temporary, $encrypted) === false || ! rename($temporary, $path)) {
                throw new RuntimeException('Installation persistence failed.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
}
