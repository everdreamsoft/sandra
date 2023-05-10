<?php
/**
 * Created by PhpStorm.
 * User: Admin
 * Date: 10.02.2019
 * Time: 17:02
 */

namespace SandraCore;


class SandraDatabaseDefinition
{

    public static function createEnvTables($tableConcept, $tableTriplet, $tableReference, $tablestorage, $tableConf)
    {

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

    }

}
