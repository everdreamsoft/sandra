<?php
declare(strict_types=1);

namespace SandraCore\Mcp;

use PDO;
use PDOException;

/**
 * Persists MCP sessions to a shared database table so they survive process
 * restarts. Clients coming back with an old `mcp-session-id` + valid token
 * are restored transparently from the DB without going through the OAuth
 * flow again.
 *
 * SSE streams are NOT persisted (they are live TCP sockets). The client
 * reconnects via GET /mcp and the session's sseClients array is re-built
 * from scratch.
 *
 * Schema:
 *   id                 varchar(64) PK   — session UUID
 *   token_hash         varchar(64)      — SHA-256 of the token that created the session
 *   env                varchar(64)      — target SANDRA_ENV
 *   scopes             varchar(255)     — CSV of granted scopes
 *   db_host / db_name  varchar()        — optional DB overrides
 *   datagraph_version  tinyint          — 7 = legacy, 8 = current
 *   created_at         timestamp
 *   last_activity_at   timestamp
 *   deleted_at         timestamp NULL   — soft-delete marker
 */
class SessionStore
{
    private PDO $pdo;
    private string $table;
    private ?string $logFile;

    /** @var array<string, float> In-memory throttle for touch() */
    private array $lastTouch = [];

    private const TOUCH_THROTTLE_SECONDS = 60;

    public function __construct(PDO $pdo, string $table, ?string $logFile = null)
    {
        $this->pdo = $pdo;
        $this->table = $table;
        $this->logFile = $logFile;
    }

    /**
     * Persist a new session. Called on `initialize`.
     */
    public function create(string $sessionId, array $routeInfo): void
    {
        try {
            $sql = "INSERT INTO `{$this->table}`
                    (`id`, `token_hash`, `env`, `scopes`, `db_host`, `db_name`, `datagraph_version`)
                    VALUES (:id, :token_hash, :env, :scopes, :db_host, :db_name, :version)
                    ON DUPLICATE KEY UPDATE
                        `token_hash` = VALUES(`token_hash`),
                        `env` = VALUES(`env`),
                        `scopes` = VALUES(`scopes`),
                        `db_host` = VALUES(`db_host`),
                        `db_name` = VALUES(`db_name`),
                        `datagraph_version` = VALUES(`datagraph_version`),
                        `last_activity_at` = CURRENT_TIMESTAMP,
                        `deleted_at` = NULL";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':id' => $sessionId,
                ':token_hash' => $routeInfo['token_hash'] ?? '',
                ':env' => $routeInfo['env'] ?? '',
                ':scopes' => implode(',', $routeInfo['scopes'] ?? []),
                ':db_host' => $routeInfo['db_host'] ?? null,
                ':db_name' => $routeInfo['db_name'] ?? null,
                ':version' => (int)($routeInfo['datagraph_version'] ?? 8),
            ]);
            $this->lastTouch[$sessionId] = microtime(true);
        } catch (PDOException $e) {
            $this->log("create failed: " . $e->getMessage());
        }
    }

    /**
     * Load a session from DB. Returns null if not found or soft-deleted.
     */
    public function load(string $sessionId): ?array
    {
        try {
            $sql = "SELECT `id`, `token_hash`, `env`, `scopes`, `db_host`, `db_name`,
                           `datagraph_version`, `last_activity_at`
                    FROM `{$this->table}`
                    WHERE `id` = :id
                      AND `deleted_at` IS NULL
                    LIMIT 1";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([':id' => $sessionId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row !== false ? $row : null;
        } catch (PDOException $e) {
            $this->log("load failed: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Update last_activity_at. Throttled to once per TOUCH_THROTTLE_SECONDS
     * per session to avoid DB hammering on high-throughput clients.
     */
    public function touch(string $sessionId): void
    {
        $now = microtime(true);
        $last = $this->lastTouch[$sessionId] ?? 0.0;
        if (($now - $last) < self::TOUCH_THROTTLE_SECONDS) {
            return;
        }
        $this->lastTouch[$sessionId] = $now;

        try {
            $sql = "UPDATE `{$this->table}`
                    SET `last_activity_at` = CURRENT_TIMESTAMP
                    WHERE `id` = :id AND `deleted_at` IS NULL";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([':id' => $sessionId]);
        } catch (PDOException $e) {
            $this->log("touch failed: " . $e->getMessage());
        }
    }

    /**
     * Soft-delete a session (set deleted_at). Keeps history for audit.
     */
    public function delete(string $sessionId): void
    {
        try {
            $sql = "UPDATE `{$this->table}`
                    SET `deleted_at` = CURRENT_TIMESTAMP
                    WHERE `id` = :id";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([':id' => $sessionId]);
            unset($this->lastTouch[$sessionId]);
        } catch (PDOException $e) {
            $this->log("delete failed: " . $e->getMessage());
        }
    }

    /**
     * Physically remove soft-deleted rows older than $daysOld.
     * Safe housekeeping. Returns the number of rows removed.
     */
    public function purgeOld(int $daysOld = 30): int
    {
        try {
            $sql = "DELETE FROM `{$this->table}`
                    WHERE `deleted_at` IS NOT NULL
                      AND `deleted_at` < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL :days DAY)";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([':days' => $daysOld]);
            return $stmt->rowCount();
        } catch (PDOException $e) {
            $this->log("purgeOld failed: " . $e->getMessage());
            return 0;
        }
    }

    private function log(string $message): void
    {
        $line = "[sandra-sessions] $message\n";
        if ($this->logFile !== null) {
            @file_put_contents($this->logFile, date('Y-m-d H:i:s') . ' ' . $line, FILE_APPEND);
        }
    }
}
