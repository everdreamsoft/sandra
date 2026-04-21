<?php
declare(strict_types=1);

namespace SandraCore\Driver;

class SQLiteDriver implements DatabaseDriverInterface
{
    public function getDsn(string $host, string $database): string
    {
        return "sqlite:$database";
    }

    public function getCreateTableSQL(string $tableName, string $tableType): string
    {
        return match ($tableType) {
            'concept' => "CREATE TABLE IF NOT EXISTS `$tableName` (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                code VARCHAR(64) NOT NULL,
                shortname VARCHAR(64) DEFAULT NULL,
                UNIQUE (shortname)
            )",
            'triplet' => "CREATE TABLE IF NOT EXISTS `$tableName` (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                idConceptStart INTEGER NOT NULL,
                idConceptLink INTEGER NOT NULL,
                idConceptTarget INTEGER NOT NULL,
                flag INTEGER NOT NULL DEFAULT 0,
                UNIQUE (idConceptStart, idConceptLink, idConceptTarget)
            )",
            'reference' => "CREATE TABLE IF NOT EXISTS `$tableName` (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                idConcept INTEGER NOT NULL,
                linkReferenced INTEGER NOT NULL,
                value VARCHAR(255) NOT NULL DEFAULT '',
                UNIQUE (idConcept, linkReferenced)
            )",
            'storage' => "CREATE TABLE IF NOT EXISTS `$tableName` (
                linkReferenced INTEGER NOT NULL PRIMARY KEY,
                value TEXT NOT NULL
            )",
            'config' => "CREATE TABLE IF NOT EXISTS `$tableName` (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name VARCHAR(255) NOT NULL,
                value VARCHAR(255) NOT NULL
            )",
            'embedding' => "CREATE TABLE IF NOT EXISTS `$tableName` (
                conceptId INTEGER NOT NULL PRIMARY KEY,
                embedding TEXT NOT NULL,
                textHash VARCHAR(64) NOT NULL DEFAULT '',
                updatedAt TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )",
            default => throw new \InvalidArgumentException("Unknown table type: $tableType"),
        };
    }

    public function getUpsertEmbeddingSQL(string $table): string
    {
        return "INSERT INTO `$table` (conceptId, embedding, textHash) VALUES (:conceptId, :embedding, :textHash)
                ON CONFLICT(conceptId) DO UPDATE SET embedding = excluded.embedding, textHash = excluded.textHash";
    }

    public function getUpsertReferenceSQL(string $table): string
    {
        return "INSERT INTO `$table` (idConcept, linkReferenced, value) VALUES (:conceptId, :tripletId, :value)
                ON CONFLICT(idConcept, linkReferenced) DO UPDATE SET value = excluded.value";
    }

    public function getUpsertTripletSQL(string $table): string
    {
        return "INSERT INTO `$table` (idConceptStart, idConceptLink, idConceptTarget, flag) VALUES (:subject, :verb, :target, 0)
                ON CONFLICT(idConceptStart, idConceptLink, idConceptTarget) DO UPDATE SET flag = 0";
    }

    public function getUpsertStorageSQL(string $table): string
    {
        return "INSERT OR REPLACE INTO `$table` (linkReferenced, value) VALUES (:linkId, :storeValue)";
    }

    public function getRandomOrderSQL(): string
    {
        return 'RANDOM()';
    }

    public function getCastNumericSQL(string $column): string
    {
        return "CAST($column AS REAL)";
    }

    public function getName(): string
    {
        return 'sqlite';
    }
}
