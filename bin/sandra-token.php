#!/usr/bin/env php
<?php
/**
 * Sandra API Token Management CLI
 *
 * Creates, lists, revokes, and deletes API tokens stored in the shared
 * `sandra_api_tokens` table. These tokens are used to authenticate MCP
 * and REST API requests with per-env routing and scope-based permissions.
 *
 * Usage:
 *   php bin/sandra-token.php create --name="Alice" --env=alice --scopes=mcp:r,mcp:w
 *   php bin/sandra-token.php list
 *   php bin/sandra-token.php revoke <id>
 *   php bin/sandra-token.php unrevoke <id>
 *   php bin/sandra-token.php delete <id>
 *   php bin/sandra-token.php install   # create the tokens table
 *
 * Options:
 *   --env-file=/path/to/.env    Load DB config from this .env file
 */
declare(strict_types=1);

// ── Autoloader ──────────────────────────────────────────────────────
$autoloadPaths = [
    __DIR__ . '/../../../autoload.php',
    __DIR__ . '/../vendor/autoload.php',
];
foreach ($autoloadPaths as $autoload) {
    if (file_exists($autoload)) {
        require_once $autoload;
        break;
    }
}

use SandraCore\System;
use SandraCore\SandraDatabaseDefinition;

// ── Parse args ──────────────────────────────────────────────────────
$argv = $_SERVER['argv'];
array_shift($argv); // drop script name
$command = array_shift($argv) ?? 'help';

$opts = [];
$positional = [];
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--')) {
        $eq = strpos($arg, '=');
        if ($eq !== false) {
            $opts[substr($arg, 2, $eq - 2)] = substr($arg, $eq + 1);
        } else {
            $opts[substr($arg, 2)] = true;
        }
    } else {
        $positional[] = $arg;
    }
}

// ── Load .env ───────────────────────────────────────────────────────
$envFile = $opts['env-file'] ?? __DIR__ . '/.env';
if (!file_exists($envFile)) {
    $envFile = getcwd() . '/.env';
}
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (str_contains($line, '=')) {
            [$key] = explode('=', $line, 2);
            if (getenv($key) === false) {
                putenv($line);
            }
        }
    }
}

// ── DB config ───────────────────────────────────────────────────────
$env = getenv('SANDRA_ENV') ?: 'mcp_';
$dbHost = getenv('SANDRA_DB_HOST') ?: '127.0.0.1';
$db = getenv('SANDRA_DB') ?: getenv('SANDRA_DB_DATABASE') ?: 'sandra';
$dbUser = getenv('SANDRA_DB_USER') ?: getenv('SANDRA_DB_USERNAME') ?: 'root';
$dbPass = getenv('SANDRA_DB_PASS') !== false ? getenv('SANDRA_DB_PASS') : (getenv('SANDRA_DB_PASSWORD') !== false ? getenv('SANDRA_DB_PASSWORD') : '');

// ── Connect ─────────────────────────────────────────────────────────
try {
    $system = new System($env, false, $dbHost, $db, $dbUser, $dbPass);
    $pdo = $system->getConnection();
    $tokenTable = $system->sharedTokenTable;
} catch (\Throwable $e) {
    fwrite(STDERR, "Error connecting to database: " . $e->getMessage() . "\n");
    exit(1);
}

// ── Dispatch ────────────────────────────────────────────────────────
match ($command) {
    'create' => cmdCreate($pdo, $tokenTable, $opts),
    'list' => cmdList($pdo, $tokenTable),
    'revoke' => cmdRevoke($pdo, $tokenTable, $positional[0] ?? null),
    'unrevoke' => cmdUnrevoke($pdo, $tokenTable, $positional[0] ?? null),
    'delete' => cmdDelete($pdo, $tokenTable, $positional[0] ?? null),
    'install' => cmdInstall($system),
    'help', '--help', '-h' => cmdHelp(),
    default => cmdHelp($command),
};

// ── Commands ────────────────────────────────────────────────────────

function cmdCreate(PDO $pdo, string $table, array $opts): void
{
    $name = $opts['name'] ?? null;
    $env = $opts['env'] ?? null;
    $scopes = $opts['scopes'] ?? 'mcp:r,mcp:w,api:r,api:w';
    $expiresDays = isset($opts['expires-days']) ? (int)$opts['expires-days'] : null;
    $dbHost = $opts['db-host'] ?? null;
    $dbName = $opts['db-name'] ?? null;

    if ($name === null || $env === null) {
        fwrite(STDERR, "Error: --name and --env are required\n\n");
        cmdHelp('create');
        exit(1);
    }

    // Validate scopes
    $validScopes = ['mcp:r', 'mcp:w', 'api:r', 'api:w'];
    $requested = array_map('trim', explode(',', $scopes));
    $invalid = array_diff($requested, $validScopes);
    if (!empty($invalid)) {
        fwrite(STDERR, "Error: invalid scopes: " . implode(', ', $invalid) . "\n");
        fwrite(STDERR, "Valid scopes: " . implode(', ', $validScopes) . "\n");
        exit(1);
    }
    $scopes = implode(',', $requested);

    // Generate token
    $token = 'tok_' . bin2hex(random_bytes(32));
    $hash = hash('sha256', $token);

    $expiresAt = null;
    if ($expiresDays !== null) {
        $expiresAt = date('Y-m-d H:i:s', time() + ($expiresDays * 86400));
    }

    try {
        $sql = "INSERT INTO `{$table}` (token_hash, name, env, scopes, db_host, db_name, expires_at)
                VALUES (:hash, :name, :env, :scopes, :db_host, :db_name, :expires_at)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':hash' => $hash,
            ':name' => $name,
            ':env' => $env,
            ':scopes' => $scopes,
            ':db_host' => $dbHost,
            ':db_name' => $dbName,
            ':expires_at' => $expiresAt,
        ]);
        $id = (int)$pdo->lastInsertId();
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), "doesn't exist") || str_contains($e->getMessage(), "42S02")) {
            fwrite(STDERR, "Error: tokens table '$table' does not exist. Run: php bin/sandra-token.php install\n");
            exit(1);
        }
        fwrite(STDERR, "DB error: " . $e->getMessage() . "\n");
        exit(1);
    }

    echo "Token created successfully.\n\n";
    echo "  ID:     $id\n";
    echo "  Name:   $name\n";
    echo "  Env:    $env\n";
    echo "  Scopes: $scopes\n";
    if ($expiresAt) echo "  Expires: $expiresAt\n";
    echo "\n";
    echo "  ╔════════════════════════════════════════════════════════════════════════╗\n";
    echo "  ║  TOKEN (save now — will not be shown again):                           ║\n";
    echo "  ╚════════════════════════════════════════════════════════════════════════╝\n\n";
    echo "  $token\n\n";
}

function cmdList(PDO $pdo, string $table): void
{
    try {
        $sql = "SELECT id, name, env, scopes, created_at, last_used_at, expires_at, disabled_at
                FROM `{$table}` ORDER BY created_at DESC";
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), "doesn't exist")) {
            echo "No tokens table yet. Run: php bin/sandra-token.php install\n";
            return;
        }
        fwrite(STDERR, "DB error: " . $e->getMessage() . "\n");
        exit(1);
    }

    if (empty($rows)) {
        echo "No tokens yet. Create one with:\n";
        echo "  php bin/sandra-token.php create --name=mine --env=myenv --scopes=mcp:r,mcp:w,api:r,api:w\n";
        return;
    }

    printf("%-4s %-20s %-15s %-25s %-20s %-20s %-8s\n", 'ID', 'Name', 'Env', 'Scopes', 'Last used', 'Created', 'Status');
    echo str_repeat('─', 120) . "\n";
    foreach ($rows as $row) {
        $status = $row['disabled_at'] !== null ? 'DISABLED' : ($row['expires_at'] !== null && strtotime($row['expires_at']) < time() ? 'EXPIRED' : 'active');
        printf(
            "%-4s %-20s %-15s %-25s %-20s %-20s %-8s\n",
            $row['id'],
            mb_substr($row['name'], 0, 20),
            mb_substr($row['env'], 0, 15),
            mb_substr($row['scopes'], 0, 25),
            $row['last_used_at'] ?? '—',
            $row['created_at'],
            $status
        );
    }
}

function cmdRevoke(PDO $pdo, string $table, ?string $id): void
{
    if (!$id || !ctype_digit($id)) {
        fwrite(STDERR, "Error: provide a numeric token ID\n");
        exit(1);
    }

    $sql = "UPDATE `{$table}` SET disabled_at = CURRENT_TIMESTAMP WHERE id = :id AND disabled_at IS NULL";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id' => (int)$id]);

    if ($stmt->rowCount() === 0) {
        echo "Token $id not found or already revoked.\n";
        exit(1);
    }
    echo "Token $id revoked.\n";
}

function cmdUnrevoke(PDO $pdo, string $table, ?string $id): void
{
    if (!$id || !ctype_digit($id)) {
        fwrite(STDERR, "Error: provide a numeric token ID\n");
        exit(1);
    }

    $sql = "UPDATE `{$table}` SET disabled_at = NULL WHERE id = :id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id' => (int)$id]);

    if ($stmt->rowCount() === 0) {
        echo "Token $id not found.\n";
        exit(1);
    }
    echo "Token $id re-enabled.\n";
}

function cmdDelete(PDO $pdo, string $table, ?string $id): void
{
    if (!$id || !ctype_digit($id)) {
        fwrite(STDERR, "Error: provide a numeric token ID\n");
        exit(1);
    }

    $sql = "DELETE FROM `{$table}` WHERE id = :id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id' => (int)$id]);

    if ($stmt->rowCount() === 0) {
        echo "Token $id not found.\n";
        exit(1);
    }
    echo "Token $id deleted permanently.\n";
}

function cmdInstall(System $system): void
{
    try {
        SandraDatabaseDefinition::createEnvTables(
            $system->conceptTable,
            $system->linkTable,
            $system->tableReference,
            $system->tableStorage,
            'dummy_conf',  // config table already created elsewhere if needed
            null,
            null,
            $system->sharedTokenTable
        );
        echo "Table '{$system->sharedTokenTable}' created (or already exists).\n";
    } catch (\Throwable $e) {
        fwrite(STDERR, "Install failed: " . $e->getMessage() . "\n");
        exit(1);
    }
}

function cmdHelp(?string $command = null): void
{
    if ($command === 'create') {
        echo "Usage:\n";
        echo "  php bin/sandra-token.php create --name=NAME --env=ENV [--scopes=SCOPES] [--expires-days=N]\n\n";
        echo "Options:\n";
        echo "  --name=NAME            Human-readable label (required)\n";
        echo "  --env=ENV              SANDRA_ENV / table prefix (required)\n";
        echo "  --scopes=SCOPES        CSV: mcp:r,mcp:w,api:r,api:w (default: all)\n";
        echo "  --expires-days=N       Expire after N days (default: never)\n";
        echo "  --db-host=HOST         Override DB host for this token (advanced)\n";
        echo "  --db-name=NAME         Override DB name for this token (advanced)\n";
        return;
    }

    echo "Sandra API Token Management\n\n";
    echo "Commands:\n";
    echo "  create     Create a new token\n";
    echo "  list       List all tokens\n";
    echo "  revoke ID  Disable a token (soft, reversible)\n";
    echo "  unrevoke ID  Re-enable a revoked token\n";
    echo "  delete ID  Permanently delete a token\n";
    echo "  install    Create the tokens table\n\n";
    echo "Examples:\n";
    echo "  php bin/sandra-token.php install\n";
    echo "  php bin/sandra-token.php create --name=\"Alice\" --env=alice --scopes=mcp:r,api:r\n";
    echo "  php bin/sandra-token.php list\n";
    echo "  php bin/sandra-token.php revoke 3\n\n";
    echo "Scopes:\n";
    echo "  mcp:r  Read access to MCP tools (sandra_search, sandra_get_entity, etc.)\n";
    echo "  mcp:w  Write access to MCP tools (sandra_create_entity, etc.)\n";
    echo "  api:r  Read access to REST API (GET /api/*)\n";
    echo "  api:w  Write access to REST API (POST/PUT/DELETE /api/*)\n";
}
