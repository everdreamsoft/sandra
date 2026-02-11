<?php
declare(strict_types=1);

namespace SandraCore\Cache;

interface CacheInterface
{
    public function get(string $key, mixed $default = null): mixed;

    public function set(string $key, mixed $value, int $ttl = 0): bool;

    public function delete(string $key): bool;

    public function has(string $key): bool;

    public function flush(): bool;
}
