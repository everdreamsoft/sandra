#!/usr/bin/env php
<?php
/**
 * One-pass hardening of an existing datagraph.
 *
 * Declares every file already in use, files them under the architect file so
 * their names leave the concept catalogue, and puts the ACL vocabulary out of
 * reach of `sandra_list_concepts` / `sandra_find_concept`.
 *
 * Idempotent: safe to re-run. It changes VISIBILITY, never grants — nobody
 * gains or loses access to anything.
 *
 * Usage:
 *   php bin/sandra-acl-harden.php --env=myenv [--db=sandra] [--host=127.0.0.1]
 *                                 [--user=root] [--pass=secret]
 *                                 [--public]   keep files in the catalogue,
 *                                              only declare them
 *   php bin/sandra-acl-harden.php --env=myenv --dry-run
 */

require __DIR__ . '/../vendor/autoload.php';

use SandraCore\Acl\FileManager;
use SandraCore\System;

$opts = getopt('', ['env:', 'db:', 'host:', 'user:', 'pass:', 'public', 'dry-run', 'help']);

if (isset($opts['help']) || !isset($opts['env'])) {
    fwrite(STDERR, "Usage: php bin/sandra-acl-harden.php --env=PREFIX [--db=] [--host=] [--user=] [--pass=] [--public] [--dry-run]\n");
    exit(isset($opts['help']) ? 0 : 1);
}

$system = new System(
    (string) $opts['env'],
    false,
    (string) ($opts['host'] ?? getenv('SANDRA_DB_HOST') ?: '127.0.0.1'),
    (string) ($opts['db'] ?? getenv('SANDRA_DB') ?: 'sandra'),
    (string) ($opts['user'] ?? getenv('SANDRA_DB_USER') ?: 'root'),
    (string) ($opts['pass'] ?? (getenv('SANDRA_DB_PASS') !== false ? getenv('SANDRA_DB_PASS') : ''))
);

$files = new FileManager($system);
$architect = !isset($opts['public']);

if (isset($opts['dry-run'])) {
    // Read-only: report what a real run would touch.
    $pdo = $system->getConnection();
    $cif = (int) $system->systemConcept->get('contained_in_file', null, false);
    $stmt = $pdo->prepare(
        "SELECT DISTINCT idConceptTarget FROM `{$system->linkTable}` WHERE idConceptLink = ? AND flag != ?"
    );
    $stmt->execute([$cif, (int) $system->deletedUNID]);
    $targets = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);

    echo "Dry run on env '{$opts['env']}':\n";
    echo '  files in use  : ' . count($targets) . "\n";
    echo '  would declare : ' . count($targets) . ($architect ? ' and hide from the catalogue' : ' (kept public)') . "\n";
    echo '  vocabulary    : ' . count(FileManager::RESERVED_CONCEPTS) . " reserved names\n";
    exit(0);
}

$report = $files->hardenExistingGraph($architect);

echo "Hardened env '{$opts['env']}':\n";
echo "  files declared    : {$report['files']}" . ($architect ? " (hidden from the catalogue)\n" : " (kept public)\n");
echo "  vocabulary filed  : {$report['vocabulary']}\n";
echo "\nGrants are unchanged — this only affects what can be ENUMERATED.\n";
