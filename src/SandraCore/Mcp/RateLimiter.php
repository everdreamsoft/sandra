<?php

namespace SandraCore\Mcp;

/**
 * Optional per-token rate limit hook for HttpTransport.
 *
 * Implementations decide both the budget (typically derived from scopes) and
 * the storage (DB, APCu, Redis, in-memory). Sandra core ships {@see SqlRateLimiter}
 * as a default; consumers can swap it via {@see HttpTransport::setRateLimiter}.
 *
 * Failure-tolerant: if the limiter throws or its storage is unreachable, the
 * caller (HttpTransport) lets the request through rather than 429-ing
 * everything. Rate limiting is best-effort — losing a few buckets is far
 * less bad than a global outage from a misconfigured backend.
 */
interface RateLimiter
{
    /**
     * @param  string  $tokenHash       SHA-256 of the bearer token (already hashed in routeInfo)
     * @param  array<string>  $scopes   ['mcp:r', 'mcp:w', 'api:r', 'api:w'] subset
     * @return bool  true = allow request; false = throttle (caller returns 429)
     */
    public function allow(string $tokenHash, array $scopes): bool;
}
