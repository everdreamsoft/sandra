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







    }

}