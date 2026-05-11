<?php

namespace SandraCore\Mcp;

/**
 * Optional audit hook for MCP transport-level events.
 *
 * Implementations are invoked by HttpTransport for every terminal HTTP
 * response on the /mcp endpoint — successful tool calls, error replies,
 * auth rejections, session lookups, etc. Sandra core ships no default
 * implementation; consumers register one via {@see HttpTransport::setAuditLogger}.
 *
 * Failures inside an audit logger are caught by HttpTransport and never
 * crash the user-facing response. Implementations should be best-effort.
 *
 * Reason vocabulary (informal but stable):
 *   - 2xx success: $reason is null, except when JSON-RPC payload itself
 *     reports an error — then $reason is "rpc_error_<code>".
 *   - 4xx: endpoint_not_found, missing_token, invalid_token,
 *     insufficient_scope, rate_limit_exceeded, method_not_allowed,
 *     invalid_json, session_not_found.
 *   - 5xx: exception:<class> (set when an unexpected throw escapes dispatch).
 */
interface McpAuditLogger
{
    /**
     * @param  int  $httpStatus      Final HTTP status code sent to the client (2xx, 4xx, 5xx).
     * @param  string|null  $rpcMethod  JSON-RPC method ("tools/call", "initialize", "tools/list", ...)
     *                                  or null when the request never reached JSON-RPC parsing
     *                                  (auth rejection, bad endpoint, etc.).
     * @param  string|null  $sessionId  MCP session ID (32-char hex from initialize) or null when
     *                                  no session was resolved.
     * @param  array<string,mixed>|null  $routeInfo  Result of TokenAuthService::validateAndRoute
     *                                               (env, scopes, datagraph_version, db_host,
     *                                               db_name, token_hash, ...) or null when no
     *                                               token-backed auth occurred.
     * @param  string|null  $toolName  Tool name when $rpcMethod === "tools/call", else null.
     * @param  array<string,mixed>  $arguments  Tool arguments when $rpcMethod === "tools/call",
     *                                          else an empty array.
     * @param  float  $elapsedMs  Latency in milliseconds. 0.0 for early-fail paths where
     *                            timing the rejection has no useful meaning.
     * @param  string|null  $reason  Stable lower_snake_case reason string for non-2xx outcomes,
     *                               or "rpc_error_<code>" for JSON-RPC error responses, or null
     *                               on full success.
     */
    public function logRequest(
        int $httpStatus,
        ?string $rpcMethod,
        ?string $sessionId,
        ?array $routeInfo,
        ?string $toolName,
        array $arguments,
        float $elapsedMs,
        ?string $reason,
    ): void;
}
