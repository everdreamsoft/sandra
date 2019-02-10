<?php
/**
 * Created by PhpStorm.
 * User: Admin
 * Date: 10.02.2019
 * Time: 15:14
 */

namespace SandraDG;
use PDO;


class System
{

    public $env = 'main' ;
    public static $pdo ;

    public static function init(){

        System::$pdo = new PDO('localhost', 'root', '');
       $localPdo = System::$pdo ;





    }

    public static function install(){






    }


}