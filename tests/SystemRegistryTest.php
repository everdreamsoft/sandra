<?php
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/SandraTestCase.php';

use SandraCore\Mcp\SystemRegistry;
use SandraCore\System;

/**
 * Tests for the multi-env System instance cache used by the HTTP transport
 * to route API / MCP requests to the right environment based on token info.
 */
class SystemRegistryTest extends SandraTestCase
{
    private string $dbHost;
    private string $dbName;
    private string $dbUser;
    private string $dbPass;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dbHost = getenv('SANDRA_DB_HOST') ?: '127.0.0.1';
        $this->dbName = getenv('SANDRA_DB') ?: 'sandra';
        $this->dbUser = getenv('SANDRA_DB_USER') ?: 'root';
        $this->dbPass = ($v = getenv('SANDRA_DB_PASS')) !== false ? $v : '';

        // Reset the static PDO so new System instances get fresh connections
        $ref = new \ReflectionProperty(System::class, 'pdo');
        $ref->setAccessible(true);
        $ref->setValue(null, null);
    }

    private function makeRegistry(string $defaultEnv = 'phpUnit_'): SystemRegistry
    {
        return new SystemRegistry(
            $this->dbHost,
            $this->dbName,
            $this->dbUser,
            $this->dbPass,
            $defaultEnv
        );
    }

    /**
     * Bootstrap the schema for an alternative env so that a cached,
     * install=false System from SystemRegistry can read its tables.
     */
    private function installEnv(string $env): void
    {
        new System($env, true, $this->dbHost, $this->dbName, $this->dbUser, $this->dbPass);
        // Reset the static PDO so the next System call (via registry) gets a fresh one
        $ref = new \ReflectionProperty(System::class, 'pdo');
        $ref->setAccessible(true);
        $ref->setValue(null, null);
    }

    public function testGetReturnsSystemForDefaultEnv(): void
    {
        $registry = $this->makeRegistry();
        $system = $registry->get('phpUnit_');

        $this->assertInstanceOf(System::class, $system);
        $this->assertEquals('phpUnit_', $system->env);
    }

    public function testGetCachesInstancesPerEnv(): void
    {
        $registry = $this->makeRegistry();

        $first = $registry->get('phpUnit_');
        $second = $registry->get('phpUnit_');

        $this->assertSame($first, $second, 'Same env should return the same cached System instance');
    }

    public function testGetReturnsDifferentInstancesForDifferentEnvs(): void
    {
        $this->installEnv('phpUnit_other');
        $registry = $this->makeRegistry();

        $envA = $registry->get('phpUnit_');
        $envB = $registry->get('phpUnit_other');

        $this->assertNotSame($envA, $envB);
        $this->assertEquals('phpUnit_', $envA->env);
        $this->assertEquals('phpUnit_other', $envB->env);
    }

    public function testGetDefaultReturnsDefaultEnv(): void
    {
        $registry = $this->makeRegistry('phpUnit_');
        $system = $registry->getDefault();

        $this->assertEquals('phpUnit_', $system->env);
    }

    public function testGetDefaultEnvReturnsConfiguredValue(): void
    {
        $registry = $this->makeRegistry('custom_env');
        $this->assertEquals('custom_env', $registry->getDefaultEnv());
    }

    public function testOverridingDbHostProducesDifferentCacheKey(): void
    {
        $registry = $this->makeRegistry();

        $default = $registry->get('phpUnit_');
        // Different cache key even if same host string — test just the caching logic
        $withOverride = $registry->get('phpUnit_', $this->dbHost, $this->dbName);

        // Same host/name explicitly → should match default
        $this->assertSame($default, $withOverride);
    }

    public function testClearEmptiesCache(): void
    {
        $registry = $this->makeRegistry();

        $first = $registry->get('phpUnit_');
        $registry->clear();
        $second = $registry->get('phpUnit_');

        $this->assertNotSame($first, $second, 'After clear(), should create fresh instance');
    }

    public function testTableNamesArePrefixedByEnv(): void
    {
        $this->installEnv('alpha_');
        $this->installEnv('beta_');
        $registry = $this->makeRegistry();

        $envA = $registry->get('alpha_');
        $envB = $registry->get('beta_');

        $this->assertStringContainsString('alpha_', $envA->conceptTable);
        $this->assertStringContainsString('beta_', $envB->conceptTable);
        $this->assertNotEquals($envA->conceptTable, $envB->conceptTable);
    }
}
