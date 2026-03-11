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
 * - POST /mcp → JSON-RPC request/notification → JSON or SSE response
 * - GET  /mcp → SSE stream for server-initiated messages
 * - DELETE /mcp → terminate session
 */
class HttpTransport
{
    private McpServer $server;
    private ?string $sessionId = null;
    private ?string $logFile;
    private int $eventCounter = 0;

    public function __construct(McpServer $server, ?string $logFile = null)
    {
        $this->server = $server;
        $this->logFile = $logFile;
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

        $this->log("HTTP MCP server listening on http://$host:$port/mcp");
        $this->log("Configure .mcp.json with: {\"type\": \"streamable-http\", \"url\": \"http://$host:$port/mcp\"}");

        while (true) {
            $conn = @stream_socket_accept($socket, -1, $peer);
            if (!$conn) {
                continue;
            }

            try {
                $this->handleConnection($conn, $peer);
            } catch (\Throwable $e) {
                $this->log("!! Connection error from $peer: " . $e->getMessage());
            } finally {
                if (is_resource($conn)) {
                    @fclose($conn);
                }
            }
        }
    }

    private function handleConnection($conn, string $peer): void
    {
        stream_set_timeout($conn, 30);

        $request = $this->readHttpRequest($conn);
        if (!$request) {
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
            return;
        }

        // CORS preflight
        if ($method === 'OPTIONS') {
            $this->sendResponse($conn, 204, $this->corsHeaders());
            return;
        }

        match ($method) {
            'POST' => $this->handlePost($conn, $headers, $body, $peer),
            'GET' => $this->handleGet($conn, $headers, $peer),
            'DELETE' => $this->handleDelete($conn, $headers, $peer),
            default => $this->sendResponse($conn, 405, $this->corsHeaders(), '{"error": "Method not allowed"}'),
        };
    }

    private function handlePost($conn, array $headers, string $body, string $peer): void
    {
        $msg = json_decode($body, true);
        if (!is_array($msg)) {
            $this->log("   PARSE ERROR: " . substr($body, 0, 200));
            $this->sendResponse($conn, 400, $this->corsHeaders(), '{"error": "Invalid JSON"}');
            return;
        }

        $rpcMethod = $msg['method'] ?? '?';
        $rpcId = $msg['id'] ?? null;
        $toolName = ($rpcMethod === 'tools/call') ? ($msg['params']['name'] ?? '?') : '';
        $this->log("   JSON-RPC method=$rpcMethod id=$rpcId" . ($toolName ? " tool=$toolName" : ''));

        // Session management
        $clientSessionId = $headers['mcp-session-id'] ?? null;

        if ($rpcMethod === 'initialize') {
            // New session
            $this->sessionId = bin2hex(random_bytes(16));
            $this->eventCounter = 0;
            $this->log("   New session: " . $this->sessionId);
        } elseif ($this->sessionId !== null && $clientSessionId !== null && $clientSessionId !== $this->sessionId) {
            $this->log("   Session mismatch: got=$clientSessionId expected=$this->sessionId");
            $this->sendResponse($conn, 404, $this->corsHeaders(), '{"error": "Session not found"}');
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
            return;
        }

        // Send JSON response
        $responseHeaders['Content-Type'] = 'application/json';
        $json = json_encode($response, JSON_UNESCAPED_UNICODE);
        $this->log("   << 200 OK ($rpcMethod, {$elapsed}ms, " . strlen($json) . " bytes)");
        $this->sendResponse($conn, 200, $responseHeaders, $json);
    }

    private function handleGet($conn, array $headers, string $peer): void
    {
        // SSE stream for server-initiated messages.
        // We don't currently send server-initiated messages, so just hold the connection
        // and send a keepalive comment periodically.
        $clientSessionId = $headers['mcp-session-id'] ?? null;
        if ($this->sessionId !== null && $clientSessionId !== $this->sessionId) {
            $this->sendResponse($conn, 404, $this->corsHeaders(), '{"error": "Session not found"}');
            return;
        }

        $responseHeaders = $this->corsHeaders();
        $responseHeaders['Content-Type'] = 'text/event-stream';
        $responseHeaders['Cache-Control'] = 'no-cache';
        $responseHeaders['Connection'] = 'keep-alive';
        if ($this->sessionId) {
            $responseHeaders['Mcp-Session-Id'] = $this->sessionId;
        }

        $this->sendResponseHeaders($conn, 200, $responseHeaders);
        $this->log("   SSE stream opened for $peer");

        // Send keepalive comments to prevent timeout (every 30s, max 10 min)
        for ($i = 0; $i < 20; $i++) {
            $written = @fwrite($conn, ": keepalive\n\n");
            if ($written === false || $written === 0) {
                break;
            }
            @fflush($conn);
            sleep(30);
        }

        $this->log("   SSE stream closed for $peer");
    }

    private function handleDelete($conn, array $headers, string $peer): void
    {
        $clientSessionId = $headers['mcp-session-id'] ?? null;
        if ($this->sessionId !== null && $clientSessionId === $this->sessionId) {
            $this->log("   Session terminated by client: $this->sessionId");
            $this->sessionId = null;
            $this->sendResponse($conn, 200, $this->corsHeaders());
        } else {
            $this->sendResponse($conn, 404, $this->corsHeaders(), '{"error": "Session not found"}');
        }
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
                return null;
            }
            $headerData .= $chunk;
            if (str_contains($headerData, "\r\n\r\n")) {
                break;
            }
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
                break;
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
