<?php
declare(strict_types=1);

namespace SandraCore\Mcp;

use SandraCore\Sandra7LegacySystem;
use SandraCore\System;

/**
 * Caches System instances per (db_host, db_name, env, version) combination.
 *
 * Enables multi-env routing in a single MCP process: each token can map to
 * a different env (or even a different DB), and the System for that env is
 * loaded once and reused across requests.
 *
 * A token can also declare a `datagraph_version` (7 = legacy Sandra 7,
 * 8 = current). Version 7 instantiates Sandra7LegacySystem instead of System
 * so the legacy table-naming scheme is used transparently.
 *
 * The default env is what the server was bootstrapped with. Other envs are
 * loaded lazily on first access via a token that routes to them.
 */
class SystemRegistry
{
    public const DEFAULT_VERSION = 8;
    public const LEGACY_VERSION = 7;

    private string $defaultDbHost;
    private string $defaultDbName;
    private string $dbUser;
    private string $dbPass;
    private string $defaultEnv;
    private int $defaultVersion;

    /** @var array<string, System> */
    private array $cache = [];

    public function __construct(
        string $defaultDbHost,
        string $defaultDbName,
        string $dbUser,
        string $dbPass,
        string $defaultEnv,
        int $defaultVersion = self::DEFAULT_VERSION
    ) {
        $this->defaultDbHost = $defaultDbHost;
        $this->defaultDbName = $defaultDbName;
        $this->dbUser = $dbUser;
        $this->dbPass = $dbPass;
        $this->defaultEnv = $defaultEnv;
        $this->defaultVersion = $defaultVersion;
    }

    public function getDefaultVersion(): int
    {
        return $this->defaultVersion;
    }

    /**
     * Get a System instance for the given env. Lazy-instantiated and cached.
     *
     * @param string $env The SANDRA_ENV (table prefix) to use
     * @param string|null $dbHost Override DB host (null = default)
     * @param string|null $dbName Override DB name (null = default)
     * @param int|null $version Datagraph version (7 = legacy, 8 = current). null defaults to 8.
     */
    public function get(
        string $env,
        ?string $dbHost = null,
        ?string $dbName = null,
        ?int $version = null
    ): System {
        $host = $dbHost ?? $this->defaultDbHost;
        $name = $dbName ?? $this->defaultDbName;
        $v = $version ?? $this->defaultVersion;
        $key = "$host:$name:$env:v$v";

        if (!isset($this->cache[$key])) {
            $this->cache[$key] = $v === self::LEGACY_VERSION
                ? new Sandra7LegacySystem($env, false, $host, $name, $this->dbUser, $this->dbPass)
                : new System($env, false, $host, $name, $this->dbUser, $this->dbPass);
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
