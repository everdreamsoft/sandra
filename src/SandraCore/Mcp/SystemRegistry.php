<?php
declare(strict_types=1);

namespace SandraCore\Mcp;

use SandraCore\System;

/**
 * Caches System instances per (db_host, db_name, env) combination.
 *
 * Enables multi-env routing in a single MCP process: each token can map to
 * a different env (or even a different DB), and the System for that env is
 * loaded once and reused across requests.
 *
 * The default env is what the server was bootstrapped with. Other envs are
 * loaded lazily on first access via a token that routes to them.
 */
class SystemRegistry
{
    private string $defaultDbHost;
    private string $defaultDbName;
    private string $dbUser;
    private string $dbPass;
    private string $defaultEnv;

    /** @var array<string, System> */
    private array $cache = [];

    public function __construct(
        string $defaultDbHost,
        string $defaultDbName,
        string $dbUser,
        string $dbPass,
        string $defaultEnv
    ) {
        $this->defaultDbHost = $defaultDbHost;
        $this->defaultDbName = $defaultDbName;
        $this->dbUser = $dbUser;
        $this->dbPass = $dbPass;
        $this->defaultEnv = $defaultEnv;
    }

    /**
     * Get a System instance for the given env. Lazy-instantiated and cached.
     *
     * @param string $env The SANDRA_ENV (table prefix) to use
     * @param string|null $dbHost Override DB host (null = default)
     * @param string|null $dbName Override DB name (null = default)
     */
    public function get(string $env, ?string $dbHost = null, ?string $dbName = null): System
    {
        $host = $dbHost ?? $this->defaultDbHost;
        $name = $dbName ?? $this->defaultDbName;
        $key = "$host:$name:$env";

        if (!isset($this->cache[$key])) {
            $this->cache[$key] = new System($env, false, $host, $name, $this->dbUser, $this->dbPass);
        }

        return $this->cache[$key];
    }

    public function getDefault(): System
    {
        return $this->get($this->defaultEnv);
    }

    public function getDefaultEnv(): string
    {
        return $this->defaultEnv;
    }

    /**
     * Clear cached System instances. Useful for testing or when config changes.
     */
    public function clear(): void
    {
        $this->cache = [];
    }
}
