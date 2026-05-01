<?php

namespace SandraCore\Mcp;

use PDO;

/**
 * Per-token rate limiter using a small SQL table as the bucket store.
 *
 * Buckets are 1-minute wide, keyed by (token_hash, minute_bucket). Each
 * incoming MCP request increments the relevant bucket atomically. If the
 * count exceeds the limit derived from the token's scopes, the request is
 * throttled (HttpTransport returns 429).
 *
 * Default scope→limit policy (overridable via constructor):
 *   read-only            (scope 'mcp:r')                 →  60 req/min
 *   read + REST read     (adds 'api:r')                  → 300 req/min
 *   write capable        (any of 'mcp:w', 'api:w')       → 600 req/min
 *
 * Why SQL and not APCu/Redis? Zero new dependency, works in shared hosting
 * and Docker setups out of the box. Hot-path cost: 2 cheap queries per call
 * (INSERT ON DUPLICATE then SELECT). At 1k RPM site-wide, that's ~33 q/s
 * — negligible against the rest of the MCP request handling.
 *
 * Stale bucket cleanup: rely on a periodic external cron
 * (`DELETE FROM sandra_rate_limit_buckets WHERE minute_bucket < UNIX_TIMESTAMP()/60 - 60`)
 * — not done inline to keep the hot path lean.
 */
class SqlRateLimiter implements RateLimiter
{
    /**
     * @param  array{read:int,api_read:int,write:int}  $limits
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $table = 'sandra_rate_limit_buckets',
        private readonly array $limits = [
            'read' => 60,
            'api_read' => 300,
            'write' => 600,
        ],
    ) {}

    public function allow(string $tokenHash, array $scopes): bool
    {
        if ($tokenHash === '') {
            return true; // no per-token rate limiting for un-tokened (static auth) requests
        }

        $limit = $this->limitForScopes($scopes);
        $bucket = (int) (time() / 60);

        try {
            // Atomic upsert. ON DUPLICATE KEY UPDATE keeps the count accurate
            // even under concurrent requests on the same (token, minute) pair.
            $this->pdo->prepare(
                "INSERT INTO `{$this->table}` (token_hash, minute_bucket, hits)
                 VALUES (:h, :b, 1)
                 ON DUPLICATE KEY UPDATE hits = hits + 1"
            )->execute([':h' => $tokenHash, ':b' => $bucket]);

            $stmt = $this->pdo->prepare(
                "SELECT hits FROM `{$this->table}` WHERE token_hash = :h AND minute_bucket = :b"
            );
            $stmt->execute([':h' => $tokenHash, ':b' => $bucket]);
            $hits = (int) $stmt->fetchColumn();

            return $hits <= $limit;
        } catch (\Throwable $e) {
            // Storage unreachable / table missing — fail-open. Better to serve a
            // few requests beyond budget than to 429 everyone if MySQL hiccups.
            return true;
        }
    }

    /**
     * Highest-privilege scope wins: write > api_read > read.
     */
    private function limitForScopes(array $scopes): int
    {
        if (in_array('mcp:w', $scopes, true) || in_array('api:w', $scopes, true)) {
            return $this->limits['write'];
        }
        if (in_array('api:r', $scopes, true)) {
            return $this->limits['api_read'];
        }

        return $this->limits['read'];
    }
}
