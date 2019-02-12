<?php
/**
 * Created by PhpStorm.
 * User: Admin
 * Date: 10.02.2019
 * Time: 12:22
 */


require_once __DIR__ . '/../vendor/autoload.php'; // Autoload files using Composer autoload

use SandraCore\SayHello ;
use SandraCore\System ;








$sandra = new System(null,true);
$sandra->factoryManager->create('hello');
echo"hello";






//echo SayHello::world();