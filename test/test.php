<?php
/**
 * Created by PhpStorm.
 * User: Admin
 * Date: 10.02.2019
 * Time: 12:22
 */


require_once __DIR__ . '/../vendor/autoload.php'; // Autoload files using Composer autoload

use SandraDG\SayHello ;
//use SandraDG\System ;




$config = new TomWright\PHPConfig\Config();
$config->put('init', 'true');







echo SayHello::world();