<?php
/**
 * Created by PhpStorm.
 * User: Admin
 * Date: 10.02.2019
 * Time: 17:02
 */

namespace sandraCore;


class SandraDatabaseDefinition
{

    public static function createEnvTables($tableConcept,$tableTriplet,$tableReference,$tablestorage,$tableConf){



        $sql = "create table IF NOT EXISTS $tableConcept
        (
          id        int auto_increment
            primary key,
          code      varchar(32)  not null,
          shortname varchar(255) null,
          constraint shortname
          unique (shortname)
        )
          engine = InnoDB
          collate = latin1_german1_ci;";

        System::$pdo->get()->query($sql);

        $sql="CREATE TABLE  IF NOT EXISTS `$tableTriplet` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `idConceptStart` int(11) NOT NULL,
  `idConceptLink` int(11) NOT NULL,
  `idConceptTarget` int(11) NOT NULL,
  `flag` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_name` (`idConceptStart`,`idConceptLink`,`idConceptTarget`),
  KEY `idConceptStart` (`idConceptStart`,`idConceptLink`,`idConceptTarget`),
  KEY `idConceptStart_4` (`idConceptStart`),
  KEY `idConceptTarget` (`idConceptTarget`),
  KEY `idConceptLink` (`idConceptLink`,`idConceptTarget`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_german1_ci;";


        System::$pdo->get()->query($sql);





        $sql="CREATE TABLE  IF NOT EXISTS `$tableReference` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `idConcept` int(11) NOT NULL,
  `linkReferenced` int(11) NOT NULL,
  `value` varchar(128) CHARACTER SET utf8 NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_ref` (`idConcept`,`linkReferenced`),
  KEY `linkReferenced` (`linkReferenced`)
) ENGINE=InnoDB  DEFAULT CHARSET=latin1 COLLATE=latin1_german1_ci;";


        System::$pdo->get()->query($sql);

        $sql="CREATE TABLE IF NOT EXISTS `$tablestorage` (
  `linkReferenced` int(11) NOT NULL,
  `value` mediumtext CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL,
  PRIMARY KEY (`linkReferenced`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_german1_ci;";


        System::$pdo->get()->query($sql);

        $sql="CREATE TABLE  IF NOT EXISTS `$tableConf` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `value` varchar(255) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8;";


        System::$pdo->get()->query($sql);














    }

}