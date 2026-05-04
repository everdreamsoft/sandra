#!/usr/bin/env php
<?php
/**
 * Migrate Sandra concept-ID columns to INT UNSIGNED.
 *
 * Idempotent: skips columns already UNSIGNED. Refuses to convert a column
 * that contains negative values (would silently truncate to 0 — almost
 * always indicates an upstream bug).
 *
 * Tables touched (only those that exist for the given env):
 *   {env}_SandraConcept       id
 *   {env}_SandraTriplets      id, idConceptStart, idConceptLink, idConceptTarget
 *   {env}_SandraReferences    id, idConcept, linkReferenced
 *   {env}_SandraDatastorage   linkReferenced
 *   {env}_SandraEmbeddings    conceptId
 *   {env}_SandraConfig        id
 *
 * Usage:
 *   php bin/sandra-migrate-unsigned-ids.php --env=mcp_              # dry-run by default
 *   php bin/sandra-migrate-unsigned-ids.php --env=mcp_ --apply      # actually run ALTERs
 *   php bin/sandra-migrate-unsigned-ids.php --env=mcp_ --apply --table=SandraConcept
 *
 * Big tables (SandraTriplets in particular) trigger a full table rebuild
 * under InnoDB. For very large prod datasets, prefer pt-online-schema-change.
 */
declare(strict_types=1);

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

// ── Parse args ──────────────────────────────────────────────────────
$argv = $_SERVER['argv'];
array_shift($argv);
$opts = [];
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--')) {
        $eq = strpos($arg, '=');
        if ($eq !== false) {
            $opts[substr($arg, 2, $eq - 2)] = substr($arg, $eq + 1);
        } else {
            $opts[substr($arg, 2)] = true;
        }
    }
}

if (isset($opts['help']) || isset($opts['h'])) {
    fwrite(STDOUT, file_get_contents(__FILE__, false, null, 0, 1500));
    exit(0);
}

$apply = !empty($opts['apply']);
$onlyTable = $opts['table'] ?? null;
$skipNegativeCheck = !empty($opts['skip-negative-check']);

// ── Load .env (same convention as sandra-token.php) ─────────────────
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
            if (getenv($key) === false) putenv($line);
        }
    }
}

$env    = $opts['env']     ?? (getenv('SANDRA_ENV') ?: 'mcp_');
$dbHost = $opts['db-host'] ?? (getenv('SANDRA_DB_HOST') ?: '127.0.0.1');
$db     = $opts['db-name'] ?? (getenv('SANDRA_DB') ?: getenv('SANDRA_DB_DATABASE') ?: 'sandra');
$dbUser = $opts['db-user'] ?? (getenv('SANDRA_DB_USER') ?: getenv('SANDRA_DB_USERNAME') ?: 'root');
$dbPass = $opts['db-pass'] ?? (getenv('SANDRA_DB_PASS') !== false ? getenv('SANDRA_DB_PASS') : (getenv('SANDRA_DB_PASSWORD') !== false ? getenv('SANDRA_DB_PASSWORD') : ''));

// ── Plan ────────────────────────────────────────────────────────────
// Each entry: suffix => [columnName => [definition WITHOUT signedness, hasAutoIncrement]]
$plan = [
    'SandraConcept' => [
        'id' => ['int(10) UNSIGNED NOT NULL AUTO_INCREMENT', true],
    ],
    'SandraTriplets' => [
        'id'              => ['int(10) UNSIGNED NOT NULL AUTO_INCREMENT', true],
        'idConceptStart'  => ['int(10) UNSIGNED NOT NULL', false],
        'idConceptLink'   => ['int(10) UNSIGNED NOT NULL', false],
        'idConceptTarget' => ['int(10) UNSIGNED NOT NULL', false],
    ],
    'SandraReferences' => [
        'id'             => ['int(10) UNSIGNED NOT NULL AUTO_INCREMENT', true],
        'idConcept'      => ['int(10) UNSIGNED NOT NULL', false],
        'linkReferenced' => ['int(10) UNSIGNED NOT NULL', false],
    ],
    'SandraDatastorage' => [
        'linkReferenced' => ['int(10) UNSIGNED NOT NULL', false],
    ],
    'SandraEmbeddings' => [
        'conceptId' => ['int(10) UNSIGNED NOT NULL', false],
    ],
    'SandraConfig' => [
        'id' => ['int(10) UNSIGNED NOT NULL AUTO_INCREMENT', true],
    ],
];

// ── Connect ─────────────────────────────────────────────────────────
try {
    $system = new System($env, false, $dbHost, $db, $dbUser, $dbPass);
    $pdo = $system->getConnection();
    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
} catch (\Throwable $e) {
    fwrite(STDERR, "Error connecting: " . $e->getMessage() . "\n");
    exit(1);
}

fwrite(STDOUT, sprintf(
    "Sandra unsigned-id migration\n  env=%s db=%s host=%s\n  mode=%s\n\n",
    $env, $db, $dbHost, $apply ? 'APPLY' : 'DRY-RUN (use --apply to execute)'
));

// ── Inspect current schema ──────────────────────────────────────────
$alters = []; // tableFullName => [ALTER fragments]

foreach ($plan as $suffix => $cols) {
    if ($onlyTable !== null && $onlyTable !== $suffix) continue;

    $table = $env . $suffix;

    $exists = $pdo->prepare(
        "SELECT 1 FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? LIMIT 1"
    );
    $exists->execute([$db, $table]);
    if ($exists->fetchColumn() === false) {
        fwrite(STDOUT, "  [skip] $table (table does not exist)\n");
        continue;
    }

    $size = $pdo->prepare(
        "SELECT DATA_LENGTH + INDEX_LENGTH AS bytes, TABLE_ROWS
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?"
    );
    $size->execute([$db, $table]);
    $sz = $size->fetch(\PDO::FETCH_ASSOC) ?: ['bytes' => 0, 'TABLE_ROWS' => 0];
    fwrite(STDOUT, sprintf(
        "  %s (%s rows, ~%s)\n",
        $table,
        number_format((int)$sz['TABLE_ROWS']),
        formatBytes((int)$sz['bytes'])
    ));

    foreach ($cols as $colName => [$targetDef, $isAutoInc]) {
        $colInfo = $pdo->prepare(
            "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?"
        );
        $colInfo->execute([$db, $table, $colName]);
        $current = $colInfo->fetchColumn();

        if ($current === false) {
            fwrite(STDOUT, "    [skip] column $colName not found\n");
            continue;
        }

        if (stripos((string)$current, 'unsigned') !== false) {
            fwrite(STDOUT, "    [ok]   $colName already $current\n");
            continue;
        }

        // Safety: a negative value would silently become 0 after MODIFY.
        if (!$skipNegativeCheck) {
            $neg = $pdo->query("SELECT 1 FROM `$table` WHERE `$colName` < 0 LIMIT 1");
            if ($neg !== false && $neg->fetchColumn() !== false) {
                fwrite(STDERR, sprintf(
                    "    [ABORT] %s.%s contains negative values. Inspect and clean up before re-running, or pass --skip-negative-check to override.\n",
                    $table, $colName
                ));
                exit(2);
            }
        }

        $alters[$table][] = "MODIFY COLUMN `$colName` $targetDef";
        fwrite(STDOUT, sprintf("    [todo] %s : %s -> %s\n", $colName, $current, strtolower(strtok($targetDef, ' ')) . ' unsigned'));
    }
}

if ($alters === []) {
    fwrite(STDOUT, "\nNothing to do. All concept-ID columns are already UNSIGNED.\n");
    exit(0);
}

if (!$apply) {
    fwrite(STDOUT, "\nDry-run only. Re-run with --apply to execute the ALTERs above.\n");
    exit(0);
}

// ── Execute ─────────────────────────────────────────────────────────
fwrite(STDOUT, "\nApplying changes...\n");
foreach ($alters as $table => $fragments) {
    $sql = "ALTER TABLE `$table`\n  " . implode(",\n  ", $fragments);
    fwrite(STDOUT, "\n-- $table\n$sql;\n");
    $start = microtime(true);
    try {
        $pdo->exec($sql);
        fwrite(STDOUT, sprintf("  done in %.2fs\n", microtime(true) - $start));
    } catch (\Throwable $e) {
        fwrite(STDERR, "  FAILED: " . $e->getMessage() . "\n");
        exit(3);
    }
}

fwrite(STDOUT, "\nMigration complete.\n");
exit(0);

// ── helpers ─────────────────────────────────────────────────────────
function formatBytes(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    $b = (float)$bytes;
    while ($b >= 1024 && $i < count($units) - 1) { $b /= 1024; $i++; }
    return sprintf('%.1f %s', $b, $units[$i]);
}
