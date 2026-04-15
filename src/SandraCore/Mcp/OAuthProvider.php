<?php
declare(strict_types=1);

namespace SandraCore\Mcp;

/**
 * Minimal OAuth 2.1 provider for MCP authentication.
 *
 * Implements the MCP authorization spec so that claude.ai and mobile
 * clients can authenticate via the standard OAuth flow with PKCE.
 *
 * Sandra acts as both the resource server and authorization server.
 * All state is in-memory (tokens expire on restart — clients re-auth automatically).
 */
class OAuthProvider
{
    private string $authToken;
    private ?string $logFile;
    private ?TokenAuthService $authService = null;

    /** @var array<string, array> client_id → metadata */
    private array $clients = [];

    /** @var array<string, array> code → {challenge, challenge_method, redirect_uri, client_id, scope, expires} */
    private array $authCodes = [];

    /** @var array<string, array> token → {client_id, scope, expires} */
    private array $accessTokens = [];

    private const CODE_LIFETIME = 300;      // 5 minutes
    private const TOKEN_LIFETIME = 86400;   // 24 hours

    public function __construct(string $authToken, ?string $logFile = null, ?TokenAuthService $authService = null)
    {
        $this->authToken = $authToken;
        $this->logFile = $logFile;
        $this->authService = $authService;
    }

    public function setAuthService(TokenAuthService $authService): void
    {
        $this->authService = $authService;
    }

    private function log(string $message): void
    {
        $line = "[sandra-oauth] $message\n";
        if ($this->logFile !== null) {
            @file_put_contents($this->logFile, date('Y-m-d H:i:s') . ' ' . $line, FILE_APPEND);
        }
    }

    /**
     * Derive the public base URL from request headers (reverse proxy aware).
     */
    private function getBaseUrl(array $headers): string
    {
        $proto = $headers['x-forwarded-proto'] ?? 'http';
        $host = $headers['x-forwarded-host'] ?? $headers['host'] ?? 'localhost';
        // Remove port from host if present and matches default
        $host = preg_replace('/:(?:80|443)$/', '', $host);
        return rtrim("$proto://$host", '/');
    }

    /**
     * GET /.well-known/oauth-protected-resource
     */
    public function handleProtectedResourceMetadata($conn, array $headers, callable $sendResponse): void
    {
        $baseUrl = $this->getBaseUrl($headers);
        $this->log("protected-resource metadata requested, baseUrl=$baseUrl");
        $body = json_encode([
            'resource' => "$baseUrl/mcp",
            'authorization_servers' => [$baseUrl],
            'scopes_supported' => ['mcp:read', 'mcp:write'],
        ]);
        $sendResponse($conn, 200, ['Content-Type' => 'application/json'], $body);
        @fclose($conn);
    }

    /**
     * GET /.well-known/oauth-authorization-server
     */
    public function handleAuthServerMetadata($conn, array $headers, callable $sendResponse): void
    {
        $baseUrl = $this->getBaseUrl($headers);
        $this->log("auth-server metadata requested, baseUrl=$baseUrl");
        $body = json_encode([
            'issuer' => $baseUrl,
            'authorization_endpoint' => "$baseUrl/authorize",
            'token_endpoint' => "$baseUrl/token",
            'registration_endpoint' => "$baseUrl/register",
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code'],
            'code_challenge_methods_supported' => ['S256'],
            'scopes_supported' => ['mcp:read', 'mcp:write'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'client_id_metadata_document_supported' => true,
        ]);
        $sendResponse($conn, 200, ['Content-Type' => 'application/json'], $body);
        @fclose($conn);
    }

    /**
     * POST /register — Dynamic Client Registration
     */
    public function handleRegister($conn, string $body, callable $sendResponse): void
    {
        $data = json_decode($body, true) ?: [];
        $clientId = bin2hex(random_bytes(16));
        $clientName = $data['client_name'] ?? 'unknown';
        $this->log("register client: name=$clientName id=$clientId");

        $this->clients[$clientId] = [
            'client_id' => $clientId,
            'client_name' => $data['client_name'] ?? 'MCP Client',
            'redirect_uris' => $data['redirect_uris'] ?? [],
            'grant_types' => ['authorization_code'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
        ];

        $response = $this->clients[$clientId];
        $sendResponse($conn, 201, ['Content-Type' => 'application/json'], json_encode($response));
        @fclose($conn);
    }

    /**
     * GET /authorize — Show authorization page
     */
    public function handleAuthorize($conn, string $fullPath, array $headers, callable $sendResponse): void
    {
        $queryString = parse_url($fullPath, PHP_URL_QUERY) ?? '';
        parse_str($queryString, $params);

        $clientId = $params['client_id'] ?? '';
        $redirectUri = $params['redirect_uri'] ?? '';
        $codeChallenge = $params['code_challenge'] ?? '';
        $codeChallengeMethod = $params['code_challenge_method'] ?? 'S256';
        $state = $params['state'] ?? '';
        $scope = $params['scope'] ?? 'mcp:read mcp:write';
        $responseType = $params['response_type'] ?? 'code';

        $this->log("authorize GET: client_id=$clientId redirect=$redirectUri response_type=$responseType");

        if ($responseType !== 'code' || $codeChallenge === '') {
            $this->log("authorize GET rejected: invalid params");
            $sendResponse($conn, 400, ['Content-Type' => 'application/json'],
                '{"error": "invalid_request", "error_description": "code_challenge required"}');
            @fclose($conn);
            return;
        }

        $html = $this->renderAuthorizePage($clientId, $redirectUri, $codeChallenge, $codeChallengeMethod, $state, $scope);
        $sendResponse($conn, 200, ['Content-Type' => 'text/html; charset=utf-8'], $html);
        @fclose($conn);
    }

    /**
     * POST /authorize — Process authorization form submission
     */
    public function handleAuthorizeSubmit($conn, string $body, array $headers, callable $sendResponse): void
    {
        parse_str($body, $params);

        $password = $params['password'] ?? '';
        $clientId = $params['client_id'] ?? '';
        $redirectUri = $params['redirect_uri'] ?? '';
        $codeChallenge = $params['code_challenge'] ?? '';
        $codeChallengeMethod = $params['code_challenge_method'] ?? 'S256';
        $state = $params['state'] ?? '';
        $scope = $params['scope'] ?? 'mcp:read mcp:write';

        // Validate password against:
        //   1. Any valid token in the shared tokens table (preferred)
        //   2. The static SANDRA_AUTH_TOKEN (backward compatibility)
        $validToken = null;

        if ($this->authService !== null) {
            $routeInfo = $this->authService->validateAndRoute($password);
            if ($routeInfo !== null) {
                $validToken = $password;
            }
        }

        if ($validToken === null && $this->authToken !== '' && hash_equals($this->authToken, $password)) {
            $validToken = $password;
        }

        if ($validToken === null) {
            $this->log("authorize POST rejected: invalid password");
            $html = $this->renderAuthorizePage($clientId, $redirectUri, $codeChallenge, $codeChallengeMethod, $state, $scope, 'Invalid token. Please try again.');
            $sendResponse($conn, 200, ['Content-Type' => 'text/html; charset=utf-8'], $html);
            @fclose($conn);
            return;
        }

        // Generate authorization code
        $code = bin2hex(random_bytes(32));
        $this->log("authorize POST success: issued code=" . substr($code, 0, 8) . "... for client_id=$clientId");
        $this->authCodes[$code] = [
            'challenge' => $codeChallenge,
            'challenge_method' => $codeChallengeMethod,
            'redirect_uri' => $redirectUri,
            'client_id' => $clientId,
            'scope' => $scope,
            'expires' => time() + self::CODE_LIFETIME,
            'user_token' => $validToken,
        ];

        // Redirect back to client
        $separator = str_contains($redirectUri, '?') ? '&' : '?';
        $location = $redirectUri . $separator . http_build_query([
            'code' => $code,
            'state' => $state,
        ]);

        $sendResponse($conn, 302, ['Location' => $location], '');
        @fclose($conn);
    }

    /**
     * POST /token — Exchange authorization code for access token
     */
    public function handleToken($conn, string $body, callable $sendResponse): void
    {
        // Support both JSON and form-encoded
        $contentType = '';
        $params = json_decode($body, true);
        if ($params === null) {
            parse_str($body, $params);
        }

        $grantType = $params['grant_type'] ?? '';
        $code = $params['code'] ?? '';
        $codeVerifier = $params['code_verifier'] ?? '';
        $clientId = $params['client_id'] ?? '';
        $redirectUri = $params['redirect_uri'] ?? '';

        $this->log("token request: grant_type=$grantType client_id=$clientId code=" . substr($code, 0, 8) . "...");

        if ($grantType !== 'authorization_code') {
            $this->log("token rejected: unsupported_grant_type=$grantType");
            $sendResponse($conn, 400, ['Content-Type' => 'application/json'],
                json_encode(['error' => 'unsupported_grant_type']));
            @fclose($conn);
            return;
        }

        // Validate authorization code
        if (!isset($this->authCodes[$code])) {
            $this->log("token rejected: code not found (likely lost on restart or already consumed)");
            $sendResponse($conn, 400, ['Content-Type' => 'application/json'],
                json_encode(['error' => 'invalid_grant', 'error_description' => 'Invalid or expired authorization code']));
            @fclose($conn);
            return;
        }

        $codeData = $this->authCodes[$code];

        // Check expiration
        if (time() > $codeData['expires']) {
            unset($this->authCodes[$code]);
            $sendResponse($conn, 400, ['Content-Type' => 'application/json'],
                json_encode(['error' => 'invalid_grant', 'error_description' => 'Authorization code expired']));
            @fclose($conn);
            return;
        }

        // Validate PKCE
        if ($codeData['challenge_method'] === 'S256') {
            $expectedChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
            if (!hash_equals($codeData['challenge'], $expectedChallenge)) {
                $sendResponse($conn, 400, ['Content-Type' => 'application/json'],
                    json_encode(['error' => 'invalid_grant', 'error_description' => 'PKCE verification failed']));
                @fclose($conn);
                return;
            }
        }

        // Consume the code (single use)
        unset($this->authCodes[$code]);

        // Return the user's own token as the access token.
        // This way:
        //  - The token survives process restarts (stored in DB or static env)
        //  - Validation goes through TokenAuthService (shared table lookup)
        //  - Each user gets their own env + scopes preserved
        $accessToken = $codeData['user_token'] ?? $this->authToken;
        $this->log("token issued: access_token=" . substr($accessToken, 0, 8) . "... (user-scoped)");

        $response = [
            'access_token' => $accessToken,
            'token_type' => 'Bearer',
            'expires_in' => self::TOKEN_LIFETIME,
            'scope' => $codeData['scope'],
        ];

        $sendResponse($conn, 200, ['Content-Type' => 'application/json'], json_encode($response));
        @fclose($conn);
    }

    /**
     * Validate a Bearer token from a request.
     * Returns true if valid, false otherwise.
     */
    public function validateToken(string $token): bool
    {
        // Accept the static auth token (for Claude Code / Claude Desktop / OAuth-issued)
        if (hash_equals($this->authToken, $token)) {
            return true;
        }

        // Accept legacy OAuth-issued random tokens (from old deployments)
        if (isset($this->accessTokens[$token])) {
            if (time() > $this->accessTokens[$token]['expires']) {
                $this->log("validateToken: legacy OAuth token expired, removing");
                unset($this->accessTokens[$token]);
                return false;
            }
            return true;
        }

        $this->log("validateToken: unknown token (not static, not in RAM cache of " . count($this->accessTokens) . " tokens)");
        return false;
    }

    /**
     * Build the WWW-Authenticate header value for 401 responses.
     */
    public function getWwwAuthenticateHeader(array $headers): string
    {
        $baseUrl = $this->getBaseUrl($headers);
        return 'Bearer resource_metadata="' . $baseUrl . '/.well-known/oauth-protected-resource"';
    }

    /**
     * Render the authorization HTML page.
     */
    private function renderAuthorizePage(string $clientId, string $redirectUri, string $codeChallenge, string $codeChallengeMethod, string $state, string $scope, string $error = ''): string
    {
        $errorHtml = $error !== '' ? '<p style="color:#e74c3c;font-weight:bold">' . htmlspecialchars($error) . '</p>' : '';

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sandra — Authorize</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f5f5; display: flex; justify-content: center; align-items: center; min-height: 100vh; }
        .card { background: white; border-radius: 12px; padding: 40px; max-width: 400px; width: 90%; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h2 { font-size: 24px; margin-bottom: 8px; color: #1a1a1a; }
        .subtitle { color: #666; margin-bottom: 24px; font-size: 14px; }
        .scope { background: #f0f7ff; border-radius: 8px; padding: 12px 16px; margin-bottom: 24px; font-size: 13px; color: #1a5276; }
        input[type="password"] { width: 100%; padding: 12px 16px; border: 1px solid #ddd; border-radius: 8px; font-size: 16px; margin-bottom: 16px; }
        input[type="password"]:focus { outline: none; border-color: #3498db; box-shadow: 0 0 0 3px rgba(52,152,219,0.1); }
        button { width: 100%; padding: 12px; background: #2c3e50; color: white; border: none; border-radius: 8px; font-size: 16px; cursor: pointer; }
        button:hover { background: #34495e; }
    </style>
</head>
<body>
    <div class="card">
        <h2>Sandra Memory</h2>
        <p class="subtitle">An application is requesting access to your memory.</p>
        <div class="scope">Permissions: {$scope}</div>
        {$errorHtml}
        <form method="POST" action="/authorize">
            <input type="password" name="password" placeholder="Enter your access token" autofocus required />
            <input type="hidden" name="client_id" value="{$clientId}" />
            <input type="hidden" name="redirect_uri" value="{$redirectUri}" />
            <input type="hidden" name="code_challenge" value="{$codeChallenge}" />
            <input type="hidden" name="code_challenge_method" value="{$codeChallengeMethod}" />
            <input type="hidden" name="state" value="{$state}" />
            <input type="hidden" name="scope" value="{$scope}" />
            <button type="submit">Authorize</button>
        </form>
    </div>
</body>
</html>
HTML;
    }
}
