<?php
declare(strict_types=1);

namespace SandraCore\Mcp;

/**
 * HTTP Streamable transport for MCP.
 *
 * Runs a long-lived HTTP server on a socket. Claude Code connects/reconnects
 * as needed. The server and its state survive client disconnections.
 *
 * Protocol: MCP Streamable HTTP (2025-03-26+)
 * - POST /mcp → JSON-RPC request/notification → JSON response
 * - GET  /mcp → SSE stream for server-initiated messages
 * - DELETE /mcp → terminate session
 *
 * Uses non-blocking I/O with stream_select to handle concurrent SSE + POST.
 */
/**
 * Per-client MCP session state.
 * Each connected client (Claude Code, claude.ai, mobile) gets its own session
 * with isolated SSE streams, route info, and McpServer reference.
 */
class McpSession
{
    public string $id;
    public ?array $routeInfo;
    public McpServer $mcpServer;
    public int $eventCounter = 0;
    public float $lastActivity;

    /** @var resource[] SSE connections keyed by peer address */
    public array $sseClients = [];
    /** @var float[] Last keepalive time per SSE client */
    public array $sseLastKeepalive = [];

    public function __construct(string $id, McpServer $mcpServer, ?array $routeInfo = null)
    {
        $this->id = $id;
        $this->mcpServer = $mcpServer;
        $this->routeInfo = $routeInfo;
        $this->lastActivity = microtime(true);
    }

    public function closeSseClients(): void
    {
        foreach ($this->sseClients as $conn) {
            @fclose($conn);
        }
        $this->sseClients = [];
        $this->sseLastKeepalive = [];
    }
}

class HttpTransport
{
    private McpServer $server;
    private ?string $logFile;
    private ?string $authToken = null;
    private ?OAuthProvider $oauth = null;
    private ?TokenAuthService $authService = null;
    private ?SystemRegistry $systemRegistry = null;
    private ?McpServerRegistry $mcpRegistry = null;

    /** @var array<string, McpSession> Multi-session support */
    private array $sessions = [];

    private const SSE_KEEPALIVE_INTERVAL = 30; // seconds
    private const SSE_MAX_LIFETIME = 600; // 10 minutes
    private const SELECT_TIMEOUT_SEC = 5; // wake up every 5s to send keepalives
    private const MAX_SESSIONS = 100;

    public function __construct(
        McpServer $server,
        ?string $logFile = null,
        ?string $authToken = null,
        ?TokenAuthService $authService = null,
        ?SystemRegistry $systemRegistry = null,
        ?McpServerRegistry $mcpRegistry = null
    ) {
        $this->server = $server;
        $this->logFile = $logFile;
        $this->authToken = $authToken;
        $this->authService = $authService;
        $this->systemRegistry = $systemRegistry;
        $this->mcpRegistry = $mcpRegistry;
        if ($authToken !== null || $authService !== null) {
            $this->oauth = new OAuthProvider($authToken ?? '', $logFile, $authService);
        }
    }

    /** Start listening for HTTP connections (blocks forever) */
    public function listen(string $host = '127.0.0.1', int $port = 8090): void
    {
        // Wrap IPv6 addresses in brackets for stream_socket_server
        if (str_contains($host, ':') && !str_starts_with($host, '[')) {
            $host = "[$host]";
        }
        $address = "tcp://$host:$port";
        $socket = @stream_socket_server($address, $errno, $errstr);
        if (!$socket) {
            $this->log("FATAL: Cannot bind to $address: $errstr ($errno)");
            throw new \RuntimeException("Cannot bind to $address: $errstr ($errno)");
        }

        stream_set_blocking($socket, false);

        $this->log("HTTP MCP server listening on http://$host:$port/mcp");
        $this->log("Configure .mcp.json with: {\"type\": \"http\", \"url\": \"http://$host:$port/mcp\"}");

        while (true) {
            // Build read array: main socket + SSE clients from ALL sessions
            $read = [$socket];
            foreach ($this->sessions as $session) {
                foreach ($session->sseClients as $peer => $sseConn) {
                    if (is_resource($sseConn)) {
                        $read[] = $sseConn;
                    } else {
                        unset($session->sseClients[$peer], $session->sseLastKeepalive[$peer]);
                    }
                }
            }
            $write = null;
            $except = null;

            $changed = @stream_select($read, $write, $except, self::SELECT_TIMEOUT_SEC);

            // Even if no sockets changed, do keepalives
            $this->tickSseKeepalives();

            if ($changed === false) {
                continue; // signal interrupt
            }

            foreach ($read as $readSock) {
                if ($readSock === $socket) {
                    // New incoming connection
                    $conn = @stream_socket_accept($socket, 0, $peer);
                    if (!$conn) {
                        continue;
                    }
                    try {
                        $this->handleConnection($conn, $peer);
                    } catch (\Throwable $e) {
                        $this->log("!! Connection error from $peer: " . $e->getMessage());
                    }
                } else {
                    // SSE client became readable — means it disconnected. Find which session.
                    $this->handleSseDisconnect($readSock);
                }
            }
        }
    }

    /** Send keepalive comments to SSE clients across all sessions */
    private function tickSseKeepalives(): void
    {
        $now = microtime(true);
        foreach ($this->sessions as $session) {
            foreach ($session->sseClients as $peer => $conn) {
                if (!is_resource($conn) || feof($conn)) {
                    unset($session->sseClients[$peer], $session->sseLastKeepalive[$peer]);
                    continue;
                }

                $elapsed = $now - ($session->sseLastKeepalive[$peer] ?? $now);

                if ($elapsed >= self::SSE_KEEPALIVE_INTERVAL) {
                    $written = @fwrite($conn, ": keepalive\n\n");
                    if ($written === false || $written === 0) {
                        $this->log("   SSE keepalive failed for $peer, closing");
                        @fclose($conn);
                        unset($session->sseClients[$peer], $session->sseLastKeepalive[$peer]);
                        continue;
                    }
                    @fflush($conn);
                    $session->sseLastKeepalive[$peer] = $now;
                }
            }
        }
    }

    /** Find which session owns a disconnected SSE socket and clean it up */
    private function handleSseDisconnect($readSock): void
    {
        foreach ($this->sessions as $session) {
            $peer = array_search($readSock, $session->sseClients, true);
            if ($peer !== false) {
                $data = @fread($readSock, 1);
                if ($data === false || $data === '' || feof($readSock)) {
                    $this->log("   SSE client disconnected: $peer (session=" . substr($session->id, 0, 8) . ")");
                    @fclose($readSock);
                    unset($session->sseClients[$peer], $session->sseLastKeepalive[$peer]);
                }
                return;
            }
        }
    }

    /** Remove oldest sessions when cap is exceeded */
    private function cleanupSessions(): void
    {
        if (count($this->sessions) <= self::MAX_SESSIONS) {
            return;
        }
        // Sort by lastActivity, remove oldest
        uasort($this->sessions, fn($a, $b) => $a->lastActivity <=> $b->lastActivity);
        while (count($this->sessions) > self::MAX_SESSIONS) {
            $oldest = array_key_first($this->sessions);
            $this->sessions[$oldest]->closeSseClients();
            $this->log("   Session $oldest evicted (max sessions reached)");
            unset($this->sessions[$oldest]);
        }
    }

    private function handleConnection($conn, string $peer): void
    {
        stream_set_timeout($conn, 30);

        $request = $this->readHttpRequest($conn);
        if (!$request) {
            @fclose($conn);
            return;
        }

        $method = $request['method'];
        $path = $request['path'];
        $headers = $request['headers'];
        $body = $request['body'];

        $pathWithoutQuery = parse_url($path, PHP_URL_PATH) ?? $path;
        $clientSessionId = $headers['mcp-session-id'] ?? null;
        $sessionTag = $clientSessionId ? " session=" . substr($clientSessionId, 0, 8) . "..." : '';
        $this->log(">> $method $pathWithoutQuery from $peer$sessionTag");

        // CORS preflight (for any endpoint)
        if ($method === 'OPTIONS') {
            $this->sendResponse($conn, 204, $this->corsHeaders());
            @fclose($conn);
            return;
        }

        // OAuth endpoints (when auth is enabled)
        if ($this->oauth !== null) {
            $sendResponse = [$this, 'sendResponse'];
            $handled = match (true) {
                str_starts_with($pathWithoutQuery, '/.well-known/oauth-protected-resource')
                    => $this->oauth->handleProtectedResourceMetadata($conn, $headers, $sendResponse) ?? true,
                $pathWithoutQuery === '/.well-known/oauth-authorization-server'
                    => $this->oauth->handleAuthServerMetadata($conn, $headers, $sendResponse) ?? true,
                $pathWithoutQuery === '/register' && $method === 'POST'
                    => $this->oauth->handleRegister($conn, $body, $sendResponse) ?? true,
                $pathWithoutQuery === '/authorize' && $method === 'GET'
                    => $this->oauth->handleAuthorize($conn, $path, $headers, $sendResponse) ?? true,
                $pathWithoutQuery === '/authorize' && $method === 'POST'
                    => $this->oauth->handleAuthorizeSubmit($conn, $body, $headers, $sendResponse) ?? true,
                $pathWithoutQuery === '/token' && $method === 'POST'
                    => $this->oauth->handleToken($conn, $body, $sendResponse) ?? true,
                default => false,
            };
            if ($handled !== false) {
                return;
            }
        }

        // Is this an API request or MCP request?
        $isApiRequest = str_starts_with($pathWithoutQuery, '/api/') || $pathWithoutQuery === '/api';
        $isMcpRequest = $pathWithoutQuery === '/mcp' || $pathWithoutQuery === '/mcp/';

        if (!$isApiRequest && !$isMcpRequest) {
            $this->sendResponse($conn, 404, [], '{"error": "Not found. Use /mcp or /api/* endpoint."}');
            @fclose($conn);
            return;
        }

        // Authentication check (when any auth layer is configured)
        $routeInfo = null;
        $existingSession = $this->sessions[$clientSessionId] ?? null;

        if ($this->authToken !== null || $this->authService !== null) {
            $providedToken = '';

            $authHeader = $headers['authorization'] ?? '';
            if (str_starts_with($authHeader, 'Bearer ')) {
                $providedToken = substr($authHeader, 7);
            }

            // Workaround: if no token but known session, use cached auth (claude.ai bug)
            if ($providedToken === '' && $existingSession !== null && $existingSession->routeInfo !== null) {
                $routeInfo = $existingSession->routeInfo;
                $this->log("   AUTH via cached session (no token, session=" . substr($existingSession->id, 0, 8) . ")");
            } elseif ($providedToken === '') {
                $this->log("   AUTH REJECTED from $peer — no token provided");
                if ($this->oauth) {
                    $wwwAuth = $this->oauth->getWwwAuthenticateHeader($headers);
                    $this->sendResponse($conn, 401, array_merge($this->corsHeaders(), [
                        'WWW-Authenticate' => $wwwAuth,
                    ]), '{"error": "Unauthorized"}');
                } else {
                    $this->sendResponse($conn, 401, $this->corsHeaders(), '{"error": "Unauthorized"}');
                }
                @fclose($conn);
                return;
            } else {
                // Route via TokenAuthService (if available) or fallback to legacy validation
                if ($this->authService !== null) {
                    $routeInfo = $this->authService->validateAndRoute($providedToken);
                } elseif ($this->oauth !== null && $this->oauth->validateToken($providedToken)) {
                    $routeInfo = ['env' => null, 'scopes' => TokenAuthService::ALL_SCOPES, 'is_static' => true, 'token_hash' => null];
                }

                if ($routeInfo === null) {
                    $tokenPreview = substr($providedToken, 0, 8) . '...' . substr($providedToken, -4);
                    $this->log("   AUTH REJECTED from $peer — invalid token ($tokenPreview)");
                    if ($this->oauth) {
                        $wwwAuth = $this->oauth->getWwwAuthenticateHeader($headers);
                        $this->sendResponse($conn, 401, array_merge($this->corsHeaders(), [
                            'WWW-Authenticate' => $wwwAuth,
                        ]), '{"error": "Unauthorized"}');
                    } else {
                        $this->sendResponse($conn, 401, $this->corsHeaders(), '{"error": "Unauthorized"}');
                    }
                    @fclose($conn);
                    return;
                }

                // Check scope for this endpoint
                $requiredScope = TokenAuthService::requiredScope($pathWithoutQuery, $method);
                if ($this->authService !== null && !$this->authService->hasScope($routeInfo['scopes'], $requiredScope)) {
                    $tokenPreview = substr($providedToken, 0, 8) . '...';
                    $this->log("   AUTH SCOPE REJECTED from $peer (token=$tokenPreview, env={$routeInfo['env']}, needs=$requiredScope, has=" . implode(',', $routeInfo['scopes']) . ")");
                    $this->sendResponse($conn, 403, $this->corsHeaders(),
                        json_encode(['error' => 'insufficient_scope', 'required' => $requiredScope]));
                    @fclose($conn);
                    return;
                }

                $tokenPreview = substr($providedToken, 0, 8) . '...';
                $env = $routeInfo['env'] ?? 'default';
                $this->log("   AUTH OK from $peer (token=$tokenPreview, env=$env, scope=$requiredScope)");

                // Async: update last_used_at (best-effort, failures ignored)
                if ($this->authService !== null && !empty($routeInfo['token_hash'])) {
                    $this->authService->touchLastUsed($routeInfo['token_hash']);
                }
            }
        }

        // Route API requests to ApiHandler
        if ($isApiRequest) {
            $this->handleApiRequest($conn, $method, $path, $pathWithoutQuery, $headers, $body, $routeInfo, $peer);
            return;
        }

        match ($method) {
            'POST' => $this->handlePost($conn, $headers, $body, $peer, $routeInfo, $existingSession),
            'GET' => $this->handleGet($conn, $headers, $peer, $existingSession),
            'DELETE' => $this->handleDelete($conn, $headers, $peer),
            default => $this->handleUnsupported($conn),
        };
    }

    /**
     * Select the McpServer to use based on the token's routing info.
     * When the token maps to a non-default env, the registry returns a
     * dedicated McpServer for that env. Falls back to the default server
     * when no registry is configured or the token is the static fallback.
     */
    private function selectMcpServer(?array $routeInfo): McpServer
    {
        if ($this->mcpRegistry === null || $routeInfo === null) {
            return $this->server;
        }

        $env = $routeInfo['env'] ?? null;
        if ($env === null) {
            return $this->server;
        }

        // Static token routes to default env → use default server
        if (!empty($routeInfo['is_static']) && $this->systemRegistry !== null && $env === $this->systemRegistry->getDefaultEnv()) {
            return $this->server;
        }

        return $this->mcpRegistry->get(
            $env,
            $routeInfo['db_host'] ?? null,
            $routeInfo['db_name'] ?? null,
            $routeInfo['datagraph_version'] ?? null
        );
    }

    private function handleUnsupported($conn): void
    {
        $this->sendResponse($conn, 405, $this->corsHeaders(), '{"error": "Method not allowed"}');
        @fclose($conn);
    }

    private function handlePost($conn, array $headers, string $body, string $peer, ?array $routeInfo, ?McpSession $existingSession): void
    {
        $msg = json_decode($body, true);
        if (!is_array($msg)) {
            $this->log("   PARSE ERROR: " . substr($body, 0, 200));
            $this->sendResponse($conn, 400, $this->corsHeaders(), '{"error": "Invalid JSON"}');
            @fclose($conn);
            return;
        }

        $rpcMethod = $msg['method'] ?? '?';
        $rpcId = $msg['id'] ?? null;
        $toolName = ($rpcMethod === 'tools/call') ? ($msg['params']['name'] ?? '?') : '';
        $this->log("   JSON-RPC method=$rpcMethod id=$rpcId" . ($toolName ? " tool=$toolName" : ''));

        // Session management (multi-session)
        $session = $existingSession;

        if ($rpcMethod === 'initialize') {
            // Create a NEW session without touching existing ones
            $sessionId = bin2hex(random_bytes(16));
            $mcpServer = $this->selectMcpServer($routeInfo);
            $session = new McpSession($sessionId, $mcpServer, $routeInfo);
            $this->sessions[$sessionId] = $session;
            $this->cleanupSessions();
            $this->log("   New session: $sessionId (total: " . count($this->sessions) . ")");
        } elseif ($session === null && $rpcMethod !== 'initialize') {
            $clientSessionId = $headers['mcp-session-id'] ?? 'none';
            $this->log("   Unknown session $clientSessionId, rejecting $rpcMethod");
            $this->sendResponse($conn, 404, $this->corsHeaders(), '{"error": "Session not found — send initialize first"}');
            @fclose($conn);
            return;
        }

        $session->lastActivity = microtime(true);

        // Dispatch to THIS session's McpServer
        $t0 = microtime(true);
        $response = $session->mcpServer->dispatchMessage($msg);
        $elapsed = round((microtime(true) - $t0) * 1000, 1);

        $responseHeaders = $this->corsHeaders();
        $responseHeaders['Mcp-Session-Id'] = $session->id;

        if ($response === null) {
            $this->log("   << 202 Accepted ($rpcMethod, {$elapsed}ms)");
            $this->sendResponse($conn, 202, $responseHeaders);
            @fclose($conn);
            return;
        }

        $responseHeaders['Content-Type'] = 'application/json';
        $json = json_encode($response, JSON_UNESCAPED_UNICODE);
        $this->log("   << 200 OK ($rpcMethod, {$elapsed}ms, " . strlen($json) . " bytes)");
        $this->sendResponse($conn, 200, $responseHeaders, $json);
        @fclose($conn);
    }

    private function handleGet($conn, array $headers, string $peer, ?McpSession $session): void
    {
        // SSE stream — keep alive without blocking the main loop
        if ($session === null) {
            $this->log("   SSE rejected: no active session");
            $this->sendResponse($conn, 404, $this->corsHeaders(), '{"error": "Session not found — send initialize first"}');
            @fclose($conn);
            return;
        }

        $responseHeaders = $this->corsHeaders();
        $responseHeaders['Content-Type'] = 'text/event-stream';
        $responseHeaders['Cache-Control'] = 'no-cache';
        $responseHeaders['Connection'] = 'keep-alive';
        $responseHeaders['Mcp-Session-Id'] = $session->id;

        $this->sendResponseHeaders($conn, 200, $responseHeaders);

        // Send initial keepalive
        @fwrite($conn, ": keepalive\n\n");
        @fflush($conn);

        // Register in THIS session's SSE pool
        stream_set_blocking($conn, false);
        $session->sseClients[$peer] = $conn;
        $session->sseLastKeepalive[$peer] = microtime(true);
        $session->lastActivity = microtime(true);

        $this->log("   SSE stream opened for $peer (session=" . substr($session->id, 0, 8) . ")");
    }

    private function handleDelete($conn, array $headers, string $peer): void
    {
        $clientSessionId = $headers['mcp-session-id'] ?? null;
        $session = $this->sessions[$clientSessionId] ?? null;

        if ($session !== null) {
            $this->log("   Session terminated by client: $clientSessionId (total remaining: " . (count($this->sessions) - 1) . ")");
            $session->closeSseClients();
            unset($this->sessions[$clientSessionId]);
            $this->sendResponse($conn, 200, $this->corsHeaders());
        } else {
            $this->sendResponse($conn, 404, $this->corsHeaders(), '{"error": "Session not found"}');
        }
        @fclose($conn);
    }

    // ── HTTP parsing ────────────────────────────────────────────────────

    private function readHttpRequest($conn): ?array
    {
        $headerData = '';
        $maxHeaderSize = 16384;

        // Read until we find \r\n\r\n (end of headers)
        while (strlen($headerData) < $maxHeaderSize) {
            $chunk = @fread($conn, 4096);
            if ($chunk === false || $chunk === '') {
                if (feof($conn)) {
                    return null;
                }
                // Non-blocking: wait a tiny bit
                usleep(1000);
                continue;
            }
            $headerData .= $chunk;
            if (str_contains($headerData, "\r\n\r\n")) {
                break;
            }
        }

        if (!str_contains($headerData, "\r\n\r\n")) {
            return null;
        }

        $parts = explode("\r\n\r\n", $headerData, 2);
        $headerSection = $parts[0];
        $bodyStart = $parts[1] ?? '';

        $lines = explode("\r\n", $headerSection);
        $requestLine = array_shift($lines);
        if (!$requestLine) {
            return null;
        }

        $requestParts = explode(' ', $requestLine, 3);
        if (count($requestParts) < 2) {
            return null;
        }

        $method = strtoupper($requestParts[0]);
        $rawPath = $requestParts[1];
        // Keep the full path WITH query string — OAuth endpoints need it
        $path = $rawPath;

        // Parse headers (lowercase keys)
        $headers = [];
        foreach ($lines as $line) {
            $colonPos = strpos($line, ':');
            if ($colonPos !== false) {
                $key = strtolower(trim(substr($line, 0, $colonPos)));
                $value = trim(substr($line, $colonPos + 1));
                $headers[$key] = $value;
            }
        }

        // Read body if Content-Length is set
        $contentLength = (int)($headers['content-length'] ?? 0);
        $body = $bodyStart;
        while (strlen($body) < $contentLength) {
            $remaining = $contentLength - strlen($body);
            $chunk = @fread($conn, min($remaining, 8192));
            if ($chunk === false || $chunk === '') {
                if (feof($conn)) {
                    break;
                }
                usleep(1000);
                continue;
            }
            $body .= $chunk;
        }

        return [
            'method' => $method,
            'path' => $path,
            'headers' => $headers,
            'body' => $body,
        ];
    }

    public function sendResponse($conn, int $status, array $headers = [], string $body = ''): void
    {
        $this->sendResponseHeaders($conn, $status, $headers, strlen($body));
        if ($body !== '') {
            @fwrite($conn, $body);
        }
        @fflush($conn);
    }

    private function sendResponseHeaders($conn, int $status, array $headers = [], ?int $contentLength = null): void
    {
        $statusTexts = [
            200 => 'OK', 201 => 'Created', 202 => 'Accepted', 204 => 'No Content',
            301 => 'Moved Permanently', 302 => 'Found',
            400 => 'Bad Request', 401 => 'Unauthorized', 403 => 'Forbidden', 404 => 'Not Found', 405 => 'Method Not Allowed',
            500 => 'Internal Server Error',
        ];
        $statusText = $statusTexts[$status] ?? 'Unknown';

        $response = "HTTP/1.1 $status $statusText\r\n";
        foreach ($headers as $key => $value) {
            $response .= "$key: $value\r\n";
        }
        if ($contentLength !== null && !isset($headers['Content-Length'])) {
            $response .= "Content-Length: $contentLength\r\n";
        }
        $response .= "\r\n";

        @fwrite($conn, $response);
    }

    private function corsHeaders(): array
    {
        return [
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'GET, POST, DELETE, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Mcp-Session-Id, Last-Event-ID',
            'Access-Control-Expose-Headers' => 'Mcp-Session-Id',
        ];
    }

    private function log(string $message): void
    {
        $line = "[sandra-mcp-http] $message\n";
        if ($this->logFile !== null) {
            file_put_contents($this->logFile, date('Y-m-d H:i:s') . ' ' . $line, FILE_APPEND);
        } else {
            fwrite(STDERR, $line);
        }
    }

    /**
     * Handle /api/* REST request by routing to ApiHandler for the token's env.
     */
    private function handleApiRequest($conn, string $method, string $path, string $pathWithoutQuery, array $headers, string $body, ?array $routeInfo, string $peer): void
    {
        if ($this->systemRegistry === null) {
            $this->sendResponse($conn, 503, ['Content-Type' => 'application/json'],
                json_encode(['error' => 'API not enabled (SystemRegistry not configured)']));
            @fclose($conn);
            return;
        }

        // Get System for the token's env
        $env = $routeInfo['env'] ?? $this->systemRegistry->getDefaultEnv();
        $dbHost = $routeInfo['db_host'] ?? null;
        $dbName = $routeInfo['db_name'] ?? null;
        $version = $routeInfo['datagraph_version'] ?? null;

        try {
            $system = $this->systemRegistry->get($env, $dbHost, $dbName, $version);
        } catch (\Throwable $e) {
            $this->log("   API: failed to load System for env=$env: " . $e->getMessage());
            $this->sendResponse($conn, 500, ['Content-Type' => 'application/json'],
                json_encode(['error' => 'Failed to load env', 'env' => $env]));
            @fclose($conn);
            return;
        }

        // Strip /api prefix: /api/person/5 → /person/5
        $apiPath = substr($pathWithoutQuery, 4);  // strip /api
        if ($apiPath === '') $apiPath = '/';

        // Parse query string
        $queryString = parse_url($path, PHP_URL_QUERY) ?? '';
        $queryParams = [];
        if ($queryString !== '') {
            parse_str($queryString, $queryParams);
        }

        // Parse body (JSON or form)
        $bodyData = [];
        if ($body !== '') {
            $decoded = json_decode($body, true);
            if (is_array($decoded)) {
                $bodyData = $decoded;
            } else {
                parse_str($body, $bodyData);
            }
        }

        // Build ApiHandler with auto-discovered factories
        $apiHandler = $this->buildApiHandler($system);

        // Dispatch
        $apiRequest = new \SandraCore\Api\ApiRequest($method, $apiPath, $queryParams, $bodyData);
        $apiResponse = $apiHandler->handle($apiRequest);

        $this->log("   API $method $apiPath (env=$env) → {$apiResponse->getStatus()}");

        $this->sendResponse(
            $conn,
            $apiResponse->getStatus(),
            array_merge($this->corsHeaders(), ['Content-Type' => 'application/json']),
            $apiResponse->toJson()
        );
        @fclose($conn);
    }

    /**
     * Build an ApiHandler with all discovered factories auto-registered.
     */
    private function buildApiHandler(\SandraCore\System $system): \SandraCore\Api\ApiHandler
    {
        $api = new \SandraCore\Api\ApiHandler($system);

        // Reuse FactoryDiscovery logic: scan triplets for (is_a, contained_in_file) pairs
        $pdo = $system->getConnection();
        $linkTable = $system->linkTable;
        $conceptTable = $system->conceptTable;
        $isaId = $system->systemConcept->get('is_a');
        $cifId = $system->systemConcept->get('contained_in_file');

        $sql = "SELECT DISTINCT
                    ct.shortname AS isa_name,
                    cf.shortname AS cif_name
                FROM `{$linkTable}` l1
                JOIN `{$conceptTable}` ct ON ct.id = l1.idConceptTarget
                JOIN `{$linkTable}` l2 ON l2.idConceptStart = l1.idConceptStart AND l2.idConceptLink = :cifId
                JOIN `{$conceptTable}` cf ON cf.id = l2.idConceptTarget
                WHERE l1.idConceptLink = :isaId
                  AND ct.shortname IS NOT NULL
                  AND cf.shortname IS NOT NULL";

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':isaId' => $isaId, ':cifId' => $cifId]);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            $this->log("   API discovery failed: " . $e->getMessage());
            $rows = [];
        }

        foreach ($rows as $row) {
            $name = $row['isa_name'];
            $factory = new \SandraCore\EntityFactory($row['isa_name'], $row['cif_name'], $system);
            $api->register($name, $factory, [
                'read' => true,
                'create' => true,
                'update' => true,
                'delete' => true,
                'searchable' => [],
            ]);
        }

        return $api;
    }
}
