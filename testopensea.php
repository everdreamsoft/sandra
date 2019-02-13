<?php

require_once __DIR__ . '/../vendor/autoload.php'; // Autoload files using Composer autoload

use SandraCore\System ;

$sandra = new System(null,true);

return $sandra->factoryManager->create('ethCollection','ethCollection','ethCollectionFile');

