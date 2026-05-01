<?php

namespace SandraCore\Mcp;

/**
 * Optional audit hook for MCP tool dispatch.
 *
 * Implementations are invoked by HttpTransport after each tool/call request,
 * with the resolved session, route info, tool name, and timing. Sandra core
 * ships no default implementation — by default no audit happens. Consumers
 * who want per-tool audit (e.g. analytics, rate limiting, abuse detection)
 * can register one via {@see HttpTransport::setAuditLogger}.
 *
 * Failures inside an audit logger are caught by HttpTransport; they never
 * crash the user-facing response. Implementations should be best-effort.
 */
interface McpAuditLogger
{
    /**
     * @param  string  $sessionId  MCP session ID (32-char hex from initialize)
     * @param  array<string,mixed>|null  $routeInfo  Result of TokenAuthService::validateAndRoute
     *                                               (env, scopes, datagraph_version, db_host, db_name, token_hash, ...)
     *                                               or null when no auth was required.
     * @param  string  $toolName  e.g. "sandra_search", "sandra_create_entity"
     * @param  array<string,mixed>  $arguments  Tool arguments as sent by the client
     * @param  bool  $success  True if the tool returned without error
     * @param  float  $elapsedMs  Latency in milliseconds (after dispatch, before response send)
     */
    public function logToolCall(
        string $sessionId,
        ?array $routeInfo,
        string $toolName,
        array $arguments,
        bool $success,
        float $elapsedMs,
    ): void;
}
