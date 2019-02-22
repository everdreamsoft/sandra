<?php
/**
 * Created by EverdreamSoft.
 * User: Shaban Shaame
 * Date: 22.02.19
 * Time: 16:25
 */

namespace SandraCore;


class Setup
{

    public static function flushDatagraph(System $system){



        $sql = "DROP TABLE $system->conceptTable";

        System::$pdo->get()->query($sql);

        $sql="DROP TABLE $system->linkTable";


        System::$pdo->get()->query($sql);


        $sql="DROP TABLE  $system->tableReference";
        System::$pdo->get()->query($sql);


        $sql="DROP TABLE  $system->tableConf";
        System::$pdo->get()->query($sql);

        $sql="DROP TABLE  $system->tableStorage";
        System::$pdo->get()->query($sql);






    }


}