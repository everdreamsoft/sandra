<?php
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/SandraTestCase.php';

use SandraCore\Mcp\SessionStore;
use SandraCore\SandraDatabaseDefinition;

/**
 * Tests for SessionStore — the persistent MCP session layer.
 */
class SessionStoreTest extends SandraTestCase
{
    private \PDO $pdo;
    private string $table = 'sandra_mcp_sessions';

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = $this->system->getConnection();

        $this->pdo->exec("DROP TABLE IF EXISTS `{$this->table}`");
        SandraDatabaseDefinition::createEnvTables(
            $this->system->conceptTable,
            $this->system->linkTable,
            $this->system->tableReference,
            $this->system->tableStorage,
            $this->system->conceptTable . '_conf_dummy',
            null,
            null,
            null,
            $this->table
        );
    }

    private function makeStore(): SessionStore
    {
        return new SessionStore($this->pdo, $this->table, null);
    }

    private function sampleRoute(array $overrides = []): array
    {
        return array_merge([
            'env' => 'shaban',
            'scopes' => ['mcp:r', 'mcp:w', 'api:r', 'api:w'],
            'db_host' => null,
            'db_name' => null,
            'datagraph_version' => 8,
            'is_static' => false,
            'token_hash' => hash('sha256', 'tok_test'),
        ], $overrides);
    }

    public function testCreatePersistsAllFields(): void
    {
        $store = $this->makeStore();
        $store->create('sess_a', $this->sampleRoute([
            'env' => 'shaban',
            'scopes' => ['mcp:r', 'api:w'],
            'db_host' => 'lindt.alwaysdata.net',
            'db_name' => 'shaban_claudia',
            'datagraph_version' => 8,
        ]));

        $row = $this->pdo->query("SELECT * FROM `{$this->table}` WHERE id = 'sess_a'")->fetch(\PDO::FETCH_ASSOC);
        $this->assertEquals('sess_a', $row['id']);
        $this->assertEquals('shaban', $row['env']);
        $this->assertEquals('mcp:r,api:w', $row['scopes']);
        $this->assertEquals('lindt.alwaysdata.net', $row['db_host']);
        $this->assertEquals('shaban_claudia', $row['db_name']);
        $this->assertEquals(8, (int)$row['datagraph_version']);
        $this->assertEquals(hash('sha256', 'tok_test'), $row['token_hash']);
        $this->assertNull($row['deleted_at']);
    }

    public function testCreateIsIdempotent(): void
    {
        $store = $this->makeStore();
        $store->create('sess_b', $this->sampleRoute());
        $store->create('sess_b', $this->sampleRoute(['env' => 'updated_env']));

        $row = $this->pdo->query("SELECT env FROM `{$this->table}` WHERE id = 'sess_b'")->fetch(\PDO::FETCH_ASSOC);
        $this->assertEquals('updated_env', $row['env']);
    }

    public function testCreateUndeletesSoftDeletedSession(): void
    {
        $store = $this->makeStore();
        $store->create('sess_c', $this->sampleRoute());
        $store->delete('sess_c');
        $this->assertNull($store->load('sess_c'));

        $store->create('sess_c', $this->sampleRoute());
        $this->assertNotNull($store->load('sess_c'));
    }

    public function testLoadReturnsRowForKnownSession(): void
    {
        $store = $this->makeStore();
        $store->create('sess_d', $this->sampleRoute(['env' => 'alice']));

        $row = $store->load('sess_d');
        $this->assertNotNull($row);
        $this->assertEquals('sess_d', $row['id']);
        $this->assertEquals('alice', $row['env']);
    }

    public function testLoadReturnsNullForUnknownSession(): void
    {
        $store = $this->makeStore();
        $this->assertNull($store->load('sess_never_existed'));
    }

    public function testLoadReturnsNullForSoftDeletedSession(): void
    {
        $store = $this->makeStore();
        $store->create('sess_e', $this->sampleRoute());
        $store->delete('sess_e');
        $this->assertNull($store->load('sess_e'));
    }

    public function testTouchUpdatesTimestamp(): void
    {
        $this->pdo->exec("INSERT INTO `{$this->table}` (id, token_hash, env, scopes, last_activity_at)
                          VALUES ('sess_f', 'hash', 'shaban', 'mcp:r', '2020-01-01 00:00:00')");

        $store = $this->makeStore();
        $store->touch('sess_f');

        $row = $this->pdo->query("SELECT last_activity_at FROM `{$this->table}` WHERE id = 'sess_f'")->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotEquals('2020-01-01 00:00:00', $row['last_activity_at']);
    }

    public function testTouchIsThrottled(): void
    {
        $this->pdo->exec("INSERT INTO `{$this->table}` (id, token_hash, env, scopes, last_activity_at)
                          VALUES ('sess_g', 'hash', 'shaban', 'mcp:r', '2020-01-01 00:00:00')");

        $store = $this->makeStore();
        $store->touch('sess_g');

        $this->pdo->exec("UPDATE `{$this->table}` SET last_activity_at = '2020-01-01 00:00:00' WHERE id = 'sess_g'");
        $store->touch('sess_g');

        $row = $this->pdo->query("SELECT last_activity_at FROM `{$this->table}` WHERE id = 'sess_g'")->fetch(\PDO::FETCH_ASSOC);
        $this->assertEquals('2020-01-01 00:00:00', $row['last_activity_at']);
    }

    public function testDeleteSetsSoftDeleteMarker(): void
    {
        $store = $this->makeStore();
        $store->create('sess_h', $this->sampleRoute());
        $store->delete('sess_h');

        $row = $this->pdo->query("SELECT deleted_at FROM `{$this->table}` WHERE id = 'sess_h'")->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotNull($row['deleted_at']);
    }

    public function testDeleteDoesNotRemoveRow(): void
    {
        $store = $this->makeStore();
        $store->create('sess_i', $this->sampleRoute());
        $store->delete('sess_i');

        $count = $this->pdo->query("SELECT COUNT(*) FROM `{$this->table}` WHERE id = 'sess_i'")->fetchColumn();
        $this->assertEquals(1, $count);
    }

    public function testPurgeOldRemovesOldSoftDeletes(): void
    {
        $store = $this->makeStore();
        $store->create('sess_j', $this->sampleRoute());
        $store->delete('sess_j');
        $this->pdo->exec("UPDATE `{$this->table}` SET deleted_at = DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 40 DAY) WHERE id = 'sess_j'");

        $removed = $store->purgeOld(30);
        $this->assertGreaterThanOrEqual(1, $removed);

        $count = $this->pdo->query("SELECT COUNT(*) FROM `{$this->table}` WHERE id = 'sess_j'")->fetchColumn();
        $this->assertEquals(0, $count);
    }

    public function testPurgeOldKeepsRecentSoftDeletes(): void
    {
        $store = $this->makeStore();
        $store->create('sess_k', $this->sampleRoute());
        $store->delete('sess_k');

        $store->purgeOld(30);
        $count = $this->pdo->query("SELECT COUNT(*) FROM `{$this->table}` WHERE id = 'sess_k'")->fetchColumn();
        $this->assertEquals(1, $count);
    }

    public function testPurgeOldKeepsActiveSessions(): void
    {
        $store = $this->makeStore();
        $store->create('sess_l', $this->sampleRoute());

        $store->purgeOld(0);
        $count = $this->pdo->query("SELECT COUNT(*) FROM `{$this->table}` WHERE id = 'sess_l'")->fetchColumn();
        $this->assertEquals(1, $count);
    }

    public function testMissingTableDoesNotCrash(): void
    {
        $this->pdo->exec("DROP TABLE IF EXISTS `{$this->table}`");
        $store = $this->makeStore();

        $store->create('sess_m', $this->sampleRoute());
        $this->assertNull($store->load('sess_m'));
        $store->touch('sess_m');
        $store->delete('sess_m');
        $this->assertEquals(0, $store->purgeOld(30));

        $this->assertTrue(true);
    }
}
