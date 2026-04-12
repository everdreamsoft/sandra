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
class HttpTransport
{
    private McpServer $server;
    private ?string $sessionId = null;
    private ?string $logFile;
    private int $eventCounter = 0;
    private ?string $authToken = null;

    /** @var resource[] Active SSE connections keyed by peer address */
    private array $sseClients = [];
    /** @var float[] Last keepalive time per SSE client */
    private array $sseLastKeepalive = [];

    private const SSE_KEEPALIVE_INTERVAL = 30; // seconds
    private const SSE_MAX_LIFETIME = 600; // 10 minutes
    private const SELECT_TIMEOUT_SEC = 5; // wake up every 5s to send keepalives

    public function __construct(McpServer $server, ?string $logFile = null, ?string $authToken = null)
    {
        $this->server = $server;
        $this->logFile = $logFile;
        $this->authToken = $authToken;
    }

    /** Start listening for HTTP connections (blocks forever) */
    public function listen(string $host = '127.0.0.1', int $port = 8090): void
    {
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
            // Build read array: main socket + all SSE clients
            $read = [$socket];
            foreach ($this->sseClients as $peer => $sseConn) {
                if (is_resource($sseConn)) {
                    $read[] = $sseConn;
                } else {
                    unset($this->sseClients[$peer], $this->sseLastKeepalive[$peer]);
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
                    // Note: POST/DELETE connections are closed after handling.
                    // GET (SSE) connections are kept open in $this->sseClients.
                } else {
                    // SSE client became readable — means it disconnected
                    $peer = array_search($readSock, $this->sseClients, true);
                    if ($peer !== false) {
                        $data = @fread($readSock, 1);
                        if ($data === false || $data === '' || feof($readSock)) {
                            $this->log("   SSE client disconnected: $peer");
                            @fclose($readSock);
                            unset($this->sseClients[$peer], $this->sseLastKeepalive[$peer]);
                        }
                    }
                }
            }
        }
    }

    /** Send keepalive comments to SSE clients and close expired ones */
    private function tickSseKeepalives(): void
    {
        $now = microtime(true);
        foreach ($this->sseClients as $peer => $conn) {
            if (!is_resource($conn) || feof($conn)) {
                unset($this->sseClients[$peer], $this->sseLastKeepalive[$peer]);
                continue;
            }

            $startTime = $this->sseLastKeepalive[$peer] - self::SSE_KEEPALIVE_INTERVAL; // approx start
            $elapsed = $now - ($this->sseLastKeepalive[$peer] ?? $now);

            if ($elapsed >= self::SSE_KEEPALIVE_INTERVAL) {
                $written = @fwrite($conn, ": keepalive\n\n");
                if ($written === false || $written === 0) {
                    $this->log("   SSE keepalive failed for $peer, closing");
                    @fclose($conn);
                    unset($this->sseClients[$peer], $this->sseLastKeepalive[$peer]);
                    continue;
                }
                @fflush($conn);
                $this->sseLastKeepalive[$peer] = $now;
            }
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

        $this->log(">> $method $path from $peer" . ($this->sessionId ? " session=" . substr($this->sessionId, 0, 8) . "..." : ''));

        // Only accept /mcp endpoint
        if ($path !== '/mcp' && $path !== '/mcp/') {
            $this->sendResponse($conn, 404, [], '{"error": "Not found. Use /mcp endpoint."}');
            @fclose($conn);
            return;
        }

        // CORS preflight
        if ($method === 'OPTIONS') {
            $this->sendResponse($conn, 204, $this->corsHeaders());
            @fclose($conn);
            return;
        }

        // Authentication check
        if ($this->authToken !== null) {
            $authHeader = $headers['authorization'] ?? '';
            $providedToken = '';
            if (str_starts_with($authHeader, 'Bearer ')) {
                $providedToken = substr($authHeader, 7);
            }
            if (!hash_equals($this->authToken, $providedToken)) {
                $this->log("   AUTH REJECTED from $peer");
                $this->sendResponse($conn, 401, $this->corsHeaders(), '{"error": "Unauthorized. Provide a valid Bearer token."}');
                @fclose($conn);
                return;
            }
        }

        match ($method) {
            'POST' => $this->handlePost($conn, $headers, $body, $peer),
            'GET' => $this->handleGet($conn, $headers, $peer),
            'DELETE' => $this->handleDelete($conn, $headers, $peer),
            default => $this->handleUnsupported($conn),
        };
    }

    private function handleUnsupported($conn): void
    {
        $this->sendResponse($conn, 405, $this->corsHeaders(), '{"error": "Method not allowed"}');
        @fclose($conn);
    }

    private function handlePost($conn, array $headers, string $body, string $peer): void
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

        // Session management
        $clientSessionId = $headers['mcp-session-id'] ?? null;

        if ($rpcMethod === 'initialize') {
            // New session — close old SSE clients
            foreach ($this->sseClients as $ssePeer => $sseConn) {
                @fclose($sseConn);
            }
            $this->sseClients = [];
            $this->sseLastKeepalive = [];
            $this->sessionId = bin2hex(random_bytes(16));
            $this->eventCounter = 0;
            $this->log("   New session: " . $this->sessionId);
        } elseif ($this->sessionId !== null && $clientSessionId !== null && $clientSessionId !== $this->sessionId) {
            // Tolerate session mismatch — single-server, reassign to current session
            $this->log("   Session mismatch: got=$clientSessionId expected=$this->sessionId — reassigning");
        } elseif ($this->sessionId === null && $rpcMethod !== 'initialize') {
            // No active session and not initializing — require initialize first
            $this->log("   No active session, rejecting $rpcMethod");
            $this->sendResponse($conn, 404, $this->corsHeaders(), '{"error": "Session not found — send initialize first"}');
            @fclose($conn);
            return;
        }

        // Dispatch to McpServer (transport-agnostic)
        $t0 = microtime(true);
        $response = $this->server->dispatchMessage($msg);
        $elapsed = round((microtime(true) - $t0) * 1000, 1);

        $responseHeaders = $this->corsHeaders();
        if ($this->sessionId) {
            $responseHeaders['Mcp-Session-Id'] = $this->sessionId;
        }

        if ($response === null) {
            // Notification — no response body
            $this->log("   << 202 Accepted ($rpcMethod, {$elapsed}ms)");
            $this->sendResponse($conn, 202, $responseHeaders);
            @fclose($conn);
            return;
        }

        // Send JSON response
        $responseHeaders['Content-Type'] = 'application/json';
        $json = json_encode($response, JSON_UNESCAPED_UNICODE);
        $this->log("   << 200 OK ($rpcMethod, {$elapsed}ms, " . strlen($json) . " bytes)");
        $this->sendResponse($conn, 200, $responseHeaders, $json);
        @fclose($conn);
    }

    private function handleGet($conn, array $headers, string $peer): void
    {
        // SSE stream — keep alive without blocking the main loop
        $clientSessionId = $headers['mcp-session-id'] ?? null;
        if ($this->sessionId === null) {
            $this->log("   SSE rejected: no active session");
            $this->sendResponse($conn, 404, $this->corsHeaders(), '{"error": "Session not found — send initialize first"}');
            @fclose($conn);
            return;
        }
        if ($clientSessionId !== null && $clientSessionId !== $this->sessionId) {
            $this->log("   SSE session mismatch: reassigning");
        }

        $responseHeaders = $this->corsHeaders();
        $responseHeaders['Content-Type'] = 'text/event-stream';
        $responseHeaders['Cache-Control'] = 'no-cache';
        $responseHeaders['Connection'] = 'keep-alive';
        if ($this->sessionId) {
            $responseHeaders['Mcp-Session-Id'] = $this->sessionId;
        }

        $this->sendResponseHeaders($conn, 200, $responseHeaders);

        // Send initial keepalive
        @fwrite($conn, ": keepalive\n\n");
        @fflush($conn);

        // Register in SSE pool — main loop will handle keepalives
        stream_set_blocking($conn, false);
        $this->sseClients[$peer] = $conn;
        $this->sseLastKeepalive[$peer] = microtime(true);

        $this->log("   SSE stream opened for $peer (non-blocking)");
        // DO NOT close $conn — it stays open for SSE
    }

    private function handleDelete($conn, array $headers, string $peer): void
    {
        $clientSessionId = $headers['mcp-session-id'] ?? null;
        if ($this->sessionId !== null && $clientSessionId === $this->sessionId) {
            $this->log("   Session terminated by client: $this->sessionId");
            $this->sessionId = null;
            // Close all SSE clients for this session
            foreach ($this->sseClients as $ssePeer => $sseConn) {
                @fclose($sseConn);
            }
            $this->sseClients = [];
            $this->sseLastKeepalive = [];
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
        $path = parse_url($rawPath, PHP_URL_PATH) ?: '/';

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

    private function sendResponse($conn, int $status, array $headers = [], string $body = ''): void
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
            200 => 'OK', 202 => 'Accepted', 204 => 'No Content',
            400 => 'Bad Request', 404 => 'Not Found', 405 => 'Method Not Allowed',
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
}
