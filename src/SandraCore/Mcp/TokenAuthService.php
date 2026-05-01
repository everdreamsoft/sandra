<?php
declare(strict_types=1);

namespace SandraCore\Mcp;

use PDO;
use PDOException;

/**
 * Central token authentication service.
 *
 * Validates Bearer tokens against a shared `sandra_api_tokens` table and returns
 * routing info (env + scopes) for the request. Falls back to a static token
 * (SANDRA_AUTH_TOKEN) for backward compatibility when the tokens table is empty
 * or a matching row is not found.
 *
 * Schema:
 *   token_hash    SHA-256 of the plain token
 *   name          human-readable label
 *   env           target SANDRA_ENV (table prefix)
 *   scopes        CSV: mcp:r,mcp:w,api:r,api:w
 *   db_host       optional override, NULL = use server default
 *   db_name       optional override, NULL = use server default
 *   expires_at    optional expiration
 *   disabled_at   optional revocation timestamp
 *   last_used_at  touched on each valid request
 */
class TokenAuthService
{
    public const SCOPE_MCP_READ = 'mcp:r';
    public const SCOPE_MCP_WRITE = 'mcp:w';
    public const SCOPE_API_READ = 'api:r';
    public const SCOPE_API_WRITE = 'api:w';
    public const ALL_SCOPES = [
        self::SCOPE_MCP_READ,
        self::SCOPE_MCP_WRITE,
        self::SCOPE_API_READ,
        self::SCOPE_API_WRITE,
    ];

    private PDO $pdo;
    private string $tokenTable;
    private ?string $staticToken;
    private string $defaultEnv;
    private ?string $logFile;

    /** Cache of validated tokens within one request lifecycle. */
    private array $tokenCache = [];

    public function __construct(
        PDO $pdo,
        string $tokenTable,
        ?string $staticToken,
        string $defaultEnv,
        ?string $logFile = null
    ) {
        $this->pdo = $pdo;
        $this->tokenTable = $tokenTable;
        $this->staticToken = $staticToken;
        $this->defaultEnv = $defaultEnv;
        $this->logFile = $logFile;
    }

    /**
     * Validate a Bearer token and return routing info.
     *
     * @return array{env: string, scopes: string[], db_host: ?string, db_name: ?string, datagraph_version: int, is_static: bool, token_hash: ?string}|null
     *         null if token is invalid, expired, or disabled
     */
    public function validateAndRoute(string $token): ?array
    {
        if ($token === '') {
            return null;
        }

        if (isset($this->tokenCache[$token])) {
            return $this->tokenCache[$token];
        }

        $hash = hash('sha256', $token);

        // Try the shared tokens table first
        $row = $this->lookupToken($hash);
        if ($row !== null) {
            $scopes = array_values(array_filter(array_map('trim', explode(',', (string)$row['scopes']))));
            $result = [
                'env' => (string)$row['env'],
                'scopes' => $scopes,
                'db_host' => $row['db_host'] !== null ? (string)$row['db_host'] : null,
                'db_name' => $row['db_name'] !== null ? (string)$row['db_name'] : null,
                'datagraph_version' => isset($row['datagraph_version']) ? (int)$row['datagraph_version'] : 8,
                'is_static' => false,
                'token_hash' => $hash,
            ];
            $this->tokenCache[$token] = $result;
            return $result;
        }

        // Fall back to static token (backward compat: Claude Code / Desktop / legacy setups)
        if ($this->staticToken !== null && $this->staticToken !== '' && hash_equals($this->staticToken, $token)) {
            $result = [
                'env' => $this->defaultEnv,
                'scopes' => self::ALL_SCOPES,  // static token has full access
                'db_host' => null,
                'db_name' => null,
                'datagraph_version' => 8,
                'is_static' => true,
                'token_hash' => null,
            ];
            $this->tokenCache[$token] = $result;
            return $result;
        }

        return null;
    }

    /**
     * Check if the token has the required scope.
     */
    public function hasScope(array $scopes, string $required): bool
    {
        return in_array($required, $scopes, true);
    }

    /**
     * Tools that modify the graph. Anything not in this set is read-only at
     * the auth layer (the tool's own logic may further restrict).
     *
     * MCP transport is JSON-RPC over POST regardless of read/write intent, so
     * we can't infer scope from HTTP method alone — we must look at the tool
     * name. A tools/call to `sandra_search` is a read; to `sandra_create_entity`
     * is a write. Both are HTTP POSTs.
     */
    private const MCP_WRITE_TOOLS = [
        'sandra_create_concept',
        'sandra_create_entity',
        'sandra_create_factory',
        'sandra_create_triplet',
        'sandra_update_entity',
        'sandra_link_entities',
        'sandra_delete_triplet',
        'sandra_batch',
        'sandra_embed_all',
    ];

    /**
     * Derive the required scope for an endpoint + method, optionally
     * inspecting the JSON-RPC body to distinguish read vs write tool calls
     * on the MCP transport.
     */
    public static function requiredScope(string $endpoint, string $method, ?string $body = null): string
    {
        if (str_starts_with($endpoint, '/mcp')) {
            return self::scopeForMcpBody($body);
        }
        if (str_starts_with($endpoint, '/api/')) {
            $isWrite = !in_array(strtoupper($method), ['GET', 'HEAD', 'OPTIONS'], true);
            return $isWrite ? self::SCOPE_API_WRITE : self::SCOPE_API_READ;
        }

        return self::SCOPE_MCP_READ;
    }

    /**
     * Inspect a JSON-RPC body to decide if the MCP request needs read or write.
     *
     *   initialize / notifications/* / tools/list   → read
     *   tools/call → look at params.name; if it's in MCP_WRITE_TOOLS → write
     *
     * Falls back to read on any parse failure or unknown tool — the per-tool
     * code can still enforce stricter checks later. We err toward "read" at
     * the gate so a malformed body doesn't accidentally need write scope.
     */
    private static function scopeForMcpBody(?string $body): string
    {
        if ($body === null || $body === '') {
            return self::SCOPE_MCP_READ;
        }
        $msg = json_decode($body, true);
        if (! is_array($msg)) {
            return self::SCOPE_MCP_READ;
        }

        $rpcMethod = (string) ($msg['method'] ?? '');
        if ($rpcMethod !== 'tools/call') {
            return self::SCOPE_MCP_READ;
        }

        $toolName = (string) ($msg['params']['name'] ?? '');

        return in_array($toolName, self::MCP_WRITE_TOOLS, true)
            ? self::SCOPE_MCP_WRITE
            : self::SCOPE_MCP_READ;
    }

    /**
     * Update last_used_at timestamp. Best-effort, silently ignores failures.
     */
    public function touchLastUsed(string $tokenHash): void
    {
        try {
            $sql = "UPDATE `{$this->tokenTable}` SET `last_used_at` = CURRENT_TIMESTAMP WHERE `token_hash` = :hash";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([':hash' => $tokenHash]);
        } catch (PDOException $e) {
            $this->log("touchLastUsed failed: " . $e->getMessage());
        }
    }

    private function lookupToken(string $hash): ?array
    {
        try {
            $sql = "SELECT `env`, `scopes`, `db_host`, `db_name`, `datagraph_version`, `expires_at`, `disabled_at`
                    FROM `{$this->tokenTable}`
                    WHERE `token_hash` = :hash
                      AND `disabled_at` IS NULL
                      AND (`expires_at` IS NULL OR `expires_at` > CURRENT_TIMESTAMP)
                    LIMIT 1";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([':hash' => $hash]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row !== false ? $row : null;
        } catch (PDOException $e) {
            // Table may not exist yet — that's fine, fall back to static token
            $this->log("lookupToken: " . $e->getMessage());
            return null;
        }
    }

    private function log(string $message): void
    {
        $line = "[sandra-auth] $message\n";
        if ($this->logFile !== null) {
            @file_put_contents($this->logFile, date('Y-m-d H:i:s') . ' ' . $line, FILE_APPEND);
        }
    }
}
