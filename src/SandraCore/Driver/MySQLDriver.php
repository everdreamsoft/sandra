<?php
declare(strict_types=1);

namespace SandraCore\Driver;

class MySQLDriver implements DatabaseDriverInterface
{
    public function getDsn(string $host, string $database): string
    {
        return "mysql:host=$host;dbname=$database";
    }

    public function getCreateTableSQL(string $tableName, string $tableType): string
    {
        return match ($tableType) {
            'concept' => "CREATE TABLE IF NOT EXISTS `$tableName` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `code` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
                `shortname` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `shortname` (`shortname`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            'triplet' => "CREATE TABLE IF NOT EXISTS `$tableName` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `idConceptStart` int(11) NOT NULL,
                `idConceptLink` int(11) NOT NULL,
                `idConceptTarget` int(11) NOT NULL,
                `flag` int(11) NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `idx_name` (`idConceptStart`,`idConceptLink`,`idConceptTarget`),
                KEY `idConceptTarget` (`idConceptTarget`,`idConceptLink`,`idConceptStart`),
                KEY `idConceptLink` (`idConceptLink`,`idConceptTarget`,`idConceptStart`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            'reference' => "CREATE TABLE IF NOT EXISTS `$tableName` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `idConcept` int(11) NOT NULL,
                `linkReferenced` int(11) NOT NULL,
                `value` varchar(255) CHARACTER SET utf8 NOT NULL DEFAULT '',
                PRIMARY KEY (`id`),
                UNIQUE KEY `unique_ref` (`idConcept`,`linkReferenced`),
                KEY `linkReferenced` (`linkReferenced`),
                KEY `ValueReference` (`value`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            'storage' => "CREATE TABLE IF NOT EXISTS `$tableName` (
                `linkReferenced` int(11) NOT NULL,
                `value` mediumtext CHARACTER SET utf8mb4 NOT NULL,
                PRIMARY KEY (`linkReferenced`)
            ) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            'config' => "CREATE TABLE IF NOT EXISTS `$tableName` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `name` varchar(255) NOT NULL,
                `value` varchar(255) NOT NULL,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8",
            'embedding' => "CREATE TABLE IF NOT EXISTS `$tableName` (
                `conceptId` int(11) NOT NULL,
                `embedding` JSON NOT NULL,
                `textHash` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
                `updatedAt` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`conceptId`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            default => throw new \InvalidArgumentException("Unknown table type: $tableType"),
        };
    }

    public function getUpsertEmbeddingSQL(string $table): string
    {
        return "INSERT INTO `$table` (conceptId, embedding, textHash) VALUES (:conceptId, :embedding, :textHash)
                ON DUPLICATE KEY UPDATE embedding = :embedding2, textHash = :textHash2";
    }

    public function getUpsertReferenceSQL(string $table): string
    {
        return "INSERT INTO `$table` (idConcept, linkReferenced, value) VALUES (:conceptId, :tripletId, :value)
                ON DUPLICATE KEY UPDATE value = :value2, id=LAST_INSERT_ID(id)";
    }

    public function getUpsertTripletSQL(string $table): string
    {
        return "INSERT INTO `$table` (idConceptStart, idConceptLink, idConceptTarget, flag) VALUES (:subject, :verb, :target, 0)
                ON DUPLICATE KEY UPDATE flag = 0, id=LAST_INSERT_ID(id)";
    }

    public function getUpsertStorageSQL(string $table): string
    {
        return "INSERT INTO `$table` (linkReferenced, `value`) VALUES (:linkId, :storeValue)
                ON DUPLICATE KEY UPDATE value = :storeValue2";
    }

    public function getRandomOrderSQL(): string
    {
        return 'RAND()';
    }

    public function getCastNumericSQL(string $column): string
    {
        return "CAST($column AS DECIMAL)";
    }

    public function getName(): string
    {
        return 'mysql';
    }
}
