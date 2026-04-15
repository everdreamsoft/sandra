<?php
/**
 * Created by PhpStorm.
 * User: Admin
 * Date: 10.02.2019
 * Time: 17:02
 */

namespace SandraCore;

use SandraCore\Driver\DatabaseDriverInterface;

class SandraDatabaseDefinition
{

    public static function createEnvTables($tableConcept, $tableTriplet, $tableReference, $tablestorage, $tableConf, ?DatabaseDriverInterface $driver = null, ?string $tableEmbedding = null, ?string $sharedTokenTable = null)
    {

        if ($driver !== null) {
            System::$pdo->get()->exec($driver->getCreateTableSQL($tableConcept, 'concept'));
            System::$pdo->get()->exec($driver->getCreateTableSQL($tableTriplet, 'triplet'));
            System::$pdo->get()->exec($driver->getCreateTableSQL($tableReference, 'reference'));
            System::$pdo->get()->exec($driver->getCreateTableSQL($tablestorage, 'storage'));
            System::$pdo->get()->exec($driver->getCreateTableSQL($tableConf, 'config'));
            return;
        }

        $sql = "create table IF NOT EXISTS $tableConcept
                (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `code` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
                    `shortname` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `shortname` (`shortname`)
                )
                engine = InnoDB
                DEFAULT CHARSET=utf8mb4
                COLLATE=utf8mb4_unicode_ci;";

        System::$pdo->get()->query($sql);

        $sql = "CREATE TABLE  IF NOT EXISTS `$tableTriplet`
                (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `idConceptStart` int(11) NOT NULL,
                    `idConceptLink` int(11) NOT NULL,
                    `idConceptTarget` int(11) NOT NULL,
                    `flag` int(11) NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `idx_name` (`idConceptStart`,`idConceptLink`,`idConceptTarget`),
                KEY `idConceptTarget` (`idConceptTarget`,`idConceptLink`,`idConceptStart`),
                KEY `idConceptLink` (`idConceptLink`,`idConceptTarget`,`idConceptStart`)
               )
                ENGINE=InnoDB
                DEFAULT CHARSET=utf8mb4
                COLLATE=utf8mb4_unicode_ci;";

        System::$pdo->get()->query($sql);

        $sql = "CREATE TABLE  IF NOT EXISTS `$tableReference`
                (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `idConcept` int(11) NOT NULL,
                  `linkReferenced` int(11) NOT NULL,
                  `value` varchar(255) CHARACTER SET utf8 NOT NULL DEFAULT '',
                PRIMARY KEY (`id`),
                UNIQUE KEY `unique_ref` (`idConcept`,`linkReferenced`),
                KEY `linkReferenced` (`linkReferenced`),
                KEY `ValueReference` (`value`)
                )
                ENGINE=InnoDB
                DEFAULT CHARSET=utf8mb4
                COLLATE=utf8mb4_unicode_ci;";


        System::$pdo->get()->query($sql);

        $sql = "CREATE TABLE IF NOT EXISTS `$tablestorage`
                (
                    `linkReferenced` int(11) NOT NULL,
                    `value` mediumtext CHARACTER SET utf8mb4 NOT NULL,
                PRIMARY KEY (`linkReferenced`)
                )
                ENGINE=MyISAM
                DEFAULT CHARSET=utf8mb4
                COLLATE=utf8mb4_unicode_ci;";


        System::$pdo->get()->query($sql);

        $sql = "CREATE TABLE  IF NOT EXISTS `$tableConf`
                (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `name` varchar(255) NOT NULL,
                    `value` varchar(255) NOT NULL,
                PRIMARY KEY (`id`)
                )
                ENGINE=InnoDB
                DEFAULT CHARSET=utf8;";

        System::$pdo->get()->query($sql);

        if ($tableEmbedding !== null) {
            $sql = "CREATE TABLE IF NOT EXISTS `$tableEmbedding`
                    (
                        `conceptId` int(11) NOT NULL,
                        `embedding` JSON NOT NULL,
                        `textHash` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
                        `updatedAt` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`conceptId`)
                    )
                    ENGINE=InnoDB
                    DEFAULT CHARSET=utf8mb4
                    COLLATE=utf8mb4_unicode_ci;";

            System::$pdo->get()->query($sql);
        }

        if ($sharedTokenTable !== null) {
            $sql = "CREATE TABLE IF NOT EXISTS `$sharedTokenTable`
                    (
                        `id` int(11) NOT NULL AUTO_INCREMENT,
                        `token_hash` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
                        `name` varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL,
                        `env` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
                        `scopes` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
                        `db_host` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
                        `db_name` varchar(128) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
                        `datagraph_version` tinyint(3) unsigned NOT NULL DEFAULT 8,
                        `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        `expires_at` timestamp NULL DEFAULT NULL,
                        `disabled_at` timestamp NULL DEFAULT NULL,
                        `last_used_at` timestamp NULL DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `token_hash` (`token_hash`),
                    KEY `env_idx` (`env`)
                    )
                    ENGINE=InnoDB
                    DEFAULT CHARSET=utf8mb4
                    COLLATE=utf8mb4_unicode_ci;";

            System::$pdo->get()->query($sql);

            // Migration: add datagraph_version to existing deployments that pre-date the column.
            // Idempotent on MySQL 8+/MariaDB 10.0.2+.
            try {
                System::$pdo->get()->exec(
                    "ALTER TABLE `$sharedTokenTable`
                     ADD COLUMN IF NOT EXISTS `datagraph_version` tinyint(3) unsigned NOT NULL DEFAULT 8"
                );
            } catch (\Throwable $e) {
                // Older MySQL (<8) doesn't support IF NOT EXISTS in ALTER; check first.
                $check = System::$pdo->get()->query(
                    "SHOW COLUMNS FROM `$sharedTokenTable` LIKE 'datagraph_version'"
                );
                if ($check !== false && $check->fetch() === false) {
                    System::$pdo->get()->exec(
                        "ALTER TABLE `$sharedTokenTable`
                         ADD COLUMN `datagraph_version` tinyint(3) unsigned NOT NULL DEFAULT 8"
                    );
                }
            }
        }

    }

}
