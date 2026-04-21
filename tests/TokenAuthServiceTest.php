<?php
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/SandraTestCase.php';

use SandraCore\Mcp\TokenAuthService;
use SandraCore\SandraDatabaseDefinition;

/**
 * Tests for the shared-token authentication service.
 *
 * TokenAuthService uses the shared `sandra_api_tokens` table, defined with
 * MySQL-specific schema (AUTO_INCREMENT, timestamp ON UPDATE). Skipped in the
 * default SQLite suite until the driver gains shared-token support.
 *
 * @group mysql-only
 *
 * Covers:
 *  - token hash lookup and routing info resolution
 *  - scope validation (mcp:r, mcp:w, api:r, api:w)
 *  - static token fallback (SANDRA_AUTH_TOKEN)
 *  - expired / disabled token rejection
 *  - requiredScope() endpoint mapping
 *  - touchLastUsed() updates the timestamp
 */
class TokenAuthServiceTest extends SandraTestCase
{
    private \PDO $pdo;
    private string $tokenTable = 'sandra_api_tokens';

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = $this->system->getConnection();

        // Ensure a fresh token table for each test
        $this->pdo->exec("DROP TABLE IF EXISTS `{$this->tokenTable}`");
        SandraDatabaseDefinition::createEnvTables(
            $this->system->conceptTable,
            $this->system->linkTable,
            $this->system->tableReference,
            $this->system->tableStorage,
            $this->system->conceptTable . '_conf_dummy',
            null,
            null,
            $this->tokenTable
        );
    }

    // ── Helpers ──────────────────────────────────────────────────────

    private function insertToken(string $token, string $env, string $scopes, array $overrides = []): int
    {
        $hash = hash('sha256', $token);
        $sql = "INSERT INTO `{$this->tokenTable}` (token_hash, name, env, scopes, expires_at, disabled_at)
                VALUES (:hash, :name, :env, :scopes, :expires, :disabled)";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':hash' => $hash,
            ':name' => $overrides['name'] ?? 'test',
            ':env' => $env,
            ':scopes' => $scopes,
            ':expires' => $overrides['expires_at'] ?? null,
            ':disabled' => $overrides['disabled_at'] ?? null,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    private function makeService(?string $staticToken = null): TokenAuthService
    {
        return new TokenAuthService($this->pdo, $this->tokenTable, $staticToken, 'phpUnit_', null);
    }

    // ── Token lookup ─────────────────────────────────────────────────

    public function testValidateAndRouteReturnsEnvAndScopesForKnownToken(): void
    {
        $this->insertToken('tok_abc', 'shaban', 'mcp:r,mcp:w,api:r');
        $service = $this->makeService();

        $result = $service->validateAndRoute('tok_abc');

        $this->assertNotNull($result);
        $this->assertEquals('shaban', $result['env']);
        $this->assertEqualsCanonicalizing(['mcp:r', 'mcp:w', 'api:r'], $result['scopes']);
        $this->assertFalse($result['is_static']);
        $this->assertEquals(hash('sha256', 'tok_abc'), $result['token_hash']);
    }

    public function testValidateAndRouteReturnsNullForUnknownToken(): void
    {
        $service = $this->makeService();
        $this->assertNull($service->validateAndRoute('tok_does_not_exist'));
    }

    public function testValidateAndRouteReturnsNullForEmptyToken(): void
    {
        $service = $this->makeService();
        $this->assertNull($service->validateAndRoute(''));
    }

    public function testTokensAreHashedNotStoredInPlaintext(): void
    {
        $this->insertToken('tok_secret', 'shaban', 'mcp:r');

        $sql = "SELECT token_hash FROM `{$this->tokenTable}` WHERE name = 'test'";
        $row = $this->pdo->query($sql)->fetch(\PDO::FETCH_ASSOC);

        $this->assertNotEquals('tok_secret', $row['token_hash']);
        $this->assertEquals(hash('sha256', 'tok_secret'), $row['token_hash']);
    }

    // ── Disabled / expired ────────────────────────────────────────────

    public function testDisabledTokenIsRejected(): void
    {
        $this->insertToken('tok_disabled', 'shaban', 'mcp:r', ['disabled_at' => date('Y-m-d H:i:s')]);
        $service = $this->makeService();

        $this->assertNull($service->validateAndRoute('tok_disabled'));
    }

    public function testExpiredTokenIsRejected(): void
    {
        $past = date('Y-m-d H:i:s', time() - 3600);
        $this->insertToken('tok_expired', 'shaban', 'mcp:r', ['expires_at' => $past]);
        $service = $this->makeService();

        $this->assertNull($service->validateAndRoute('tok_expired'));
    }

    public function testFutureExpirationIsAccepted(): void
    {
        // Use a far-future date to avoid timezone skew between PHP and MySQL.
        // Read MySQL's current time so the expiration is in the server's clock frame.
        $row = $this->pdo->query("SELECT DATE_ADD(NOW(), INTERVAL 30 DAY) AS future")->fetch(\PDO::FETCH_ASSOC);
        $future = $row['future'];

        $this->insertToken('tok_future', 'shaban', 'mcp:r', ['expires_at' => $future]);
        $service = $this->makeService();

        $this->assertNotNull($service->validateAndRoute('tok_future'));
    }

    // ── Static token fallback ─────────────────────────────────────────

    public function testStaticTokenFallbackWorksWhenDbHasNoMatch(): void
    {
        $service = $this->makeService('STATIC_TOKEN');

        $result = $service->validateAndRoute('STATIC_TOKEN');

        $this->assertNotNull($result);
        $this->assertEquals('phpUnit_', $result['env']);
        $this->assertTrue($result['is_static']);
        $this->assertEqualsCanonicalizing(
            ['mcp:r', 'mcp:w', 'api:r', 'api:w'],
            $result['scopes']
        );
    }

    public function testStaticTokenDoesNotMatchIfDifferent(): void
    {
        $service = $this->makeService('STATIC_TOKEN');
        $this->assertNull($service->validateAndRoute('wrong-token'));
    }

    public function testDbTokenTakesPrecedenceOverStaticToken(): void
    {
        $this->insertToken('DUAL_TOKEN', 'client_env', 'mcp:r');
        $service = $this->makeService('DUAL_TOKEN');

        $result = $service->validateAndRoute('DUAL_TOKEN');

        $this->assertNotNull($result);
        $this->assertEquals('client_env', $result['env'], 'DB token should win over static');
        $this->assertFalse($result['is_static']);
    }

    // ── Scope checking ───────────────────────────────────────────────

    public function testHasScope(): void
    {
        $service = $this->makeService();
        $scopes = ['mcp:r', 'api:r'];

        $this->assertTrue($service->hasScope($scopes, 'mcp:r'));
        $this->assertTrue($service->hasScope($scopes, 'api:r'));
        $this->assertFalse($service->hasScope($scopes, 'mcp:w'));
        $this->assertFalse($service->hasScope($scopes, 'api:w'));
    }

    public function testHasScopeWithEmptyScopes(): void
    {
        $service = $this->makeService();
        $this->assertFalse($service->hasScope([], 'mcp:r'));
    }

    // ── Required scope mapping ───────────────────────────────────────

    public function testRequiredScopeForMcpRead(): void
    {
        $this->assertEquals('mcp:r', TokenAuthService::requiredScope('/mcp', 'GET'));
        $this->assertEquals('mcp:r', TokenAuthService::requiredScope('/mcp/', 'HEAD'));
        $this->assertEquals('mcp:r', TokenAuthService::requiredScope('/mcp', 'OPTIONS'));
    }

    public function testRequiredScopeForMcpWrite(): void
    {
        $this->assertEquals('mcp:w', TokenAuthService::requiredScope('/mcp', 'POST'));
        $this->assertEquals('mcp:w', TokenAuthService::requiredScope('/mcp', 'PUT'));
        $this->assertEquals('mcp:w', TokenAuthService::requiredScope('/mcp', 'DELETE'));
    }

    public function testRequiredScopeForApiRead(): void
    {
        $this->assertEquals('api:r', TokenAuthService::requiredScope('/api/person', 'GET'));
        $this->assertEquals('api:r', TokenAuthService::requiredScope('/api/person/5', 'GET'));
    }

    public function testRequiredScopeForApiWrite(): void
    {
        $this->assertEquals('api:w', TokenAuthService::requiredScope('/api/person', 'POST'));
        $this->assertEquals('api:w', TokenAuthService::requiredScope('/api/person/5', 'PUT'));
        $this->assertEquals('api:w', TokenAuthService::requiredScope('/api/person/5', 'DELETE'));
    }

    // ── touchLastUsed ────────────────────────────────────────────────

    public function testTouchLastUsedUpdatesTimestamp(): void
    {
        $this->insertToken('tok_touch', 'shaban', 'mcp:r');
        $service = $this->makeService();

        $hash = hash('sha256', 'tok_touch');
        $service->touchLastUsed($hash);

        $sql = "SELECT last_used_at FROM `{$this->tokenTable}` WHERE token_hash = :hash";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':hash' => $hash]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertNotNull($row['last_used_at']);
    }

    public function testTouchLastUsedDoesNotThrowOnUnknownHash(): void
    {
        $service = $this->makeService();
        // Should silently succeed even if hash doesn't exist
        $service->touchLastUsed('deadbeef' . str_repeat('0', 56));
        $this->assertTrue(true); // no exception
    }

    // ── DB override fields ────────────────────────────────────────────

    public function testDbHostAndDbNameOverridesAreReturned(): void
    {
        $hash = hash('sha256', 'tok_override');
        $sql = "INSERT INTO `{$this->tokenTable}` (token_hash, name, env, scopes, db_host, db_name)
                VALUES (:hash, 'test', 'alice', 'mcp:r', 'other.host', 'alice_db')";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':hash' => $hash]);

        $service = $this->makeService();
        $result = $service->validateAndRoute('tok_override');

        $this->assertEquals('other.host', $result['db_host']);
        $this->assertEquals('alice_db', $result['db_name']);
    }

    public function testNullDbHostAndNameDefaultToNull(): void
    {
        $this->insertToken('tok_nullh', 'shaban', 'mcp:r');
        $service = $this->makeService();
        $result = $service->validateAndRoute('tok_nullh');

        $this->assertNull($result['db_host']);
        $this->assertNull($result['db_name']);
    }

    // ── Caching ──────────────────────────────────────────────────────

    public function testValidateAndRouteUsesCacheWithinSameInstance(): void
    {
        $this->insertToken('tok_cache', 'shaban', 'mcp:r');
        $service = $this->makeService();

        $first = $service->validateAndRoute('tok_cache');

        // Delete the token from DB
        $this->pdo->exec("DELETE FROM `{$this->tokenTable}`");

        // Cached call should still succeed
        $second = $service->validateAndRoute('tok_cache');

        $this->assertEquals($first, $second);
    }

    // ── Missing table graceful fallback ──────────────────────────────

    public function testMissingTokensTableFallsBackToStaticToken(): void
    {
        $this->pdo->exec("DROP TABLE IF EXISTS `{$this->tokenTable}`");
        $service = $this->makeService('STATIC_ONLY');

        // DB lookup should fail silently, static token should still work
        $this->assertNotNull($service->validateAndRoute('STATIC_ONLY'));
        $this->assertNull($service->validateAndRoute('anything_else'));
    }
}
