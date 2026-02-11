<?php
declare(strict_types=1);

namespace SandraCore\Cache;

class MemoryCache implements CacheInterface
{
    private array $store = [];
    private array $expiry = [];

    public function get(string $key, mixed $default = null): mixed
    {
        if (!$this->has($key)) {
            return $default;
        }

        return $this->store[$key];
    }

    public function set(string $key, mixed $value, int $ttl = 0): bool
    {
        $this->store[$key] = $value;
        $this->expiry[$key] = $ttl > 0 ? time() + $ttl : 0;
        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->store[$key], $this->expiry[$key]);
        return true;
    }

    public function has(string $key): bool
    {
        if (!array_key_exists($key, $this->store)) {
            return false;
        }

        if ($this->expiry[$key] > 0 && $this->expiry[$key] < time()) {
            $this->delete($key);
            return false;
        }

        return true;
    }

    public function flush(): bool
    {
        $this->store = [];
        $this->expiry = [];
        return true;
    }
}
