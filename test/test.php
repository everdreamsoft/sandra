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


$sandraToFlush = new SandraCore\System('_phpUnit', true);
\SandraCore\Setup::flushDatagraph($sandraToFlush);

$sandra = new SandraCore\System('_phpUnit',true);
$factory = $sandra->factoryManager->create('personnelFactoryTestLocal','person','peopleFile');
$factory->populateLocal();



$foreignAdapter = new \SandraCore\ForeignEntityAdapter("https://script.google.com/macros/s/AKfycbzJWIW1e0rsVx611g4EcYmZ9SJonpnzwmskDsg_A_9j3qGlht0/exec",'user',$sandra);


// testing limit should return only one entity
$foreignAdapter->populate(1);




$vocabulary = array(
    'Visa' => 'visa',
    'Title'=> 'Title'
);


$foreignAdapter->adaptToLocalVocabulary($vocabulary); // If after populate then fail
$controlFactory = clone $factory ;

$factory->foreignPopulate($foreignAdapter,2);



$factory->setFuseForeignOnRef('Visa','visa',$vocabulary);
$factory->fuseRemoteEntity();
$factory->saveEntitiesNotInLocal();


die();



$sandra = new System(null,true);

$sandraToFlush = new SandraCore\System('phpUnit_', true);
\SandraCore\Setup::flushDatagraph($sandraToFlush);
$system = new \SandraCore\System('phpUnit_',true);



$system = new \SandraCore\System('phpUnit_',true);
$alphabetFactory = new \SandraCore\EntityFactory('algebra','algebraFile',$system);

$alphabetFactoryEmpty = clone $alphabetFactory ;
$allImpliesB =  new \SandraCore\EntityFactory('algebra','algebraFile',$system);

$a = $alphabetFactory->createNew(array('name'=>'a'));
$b = $alphabetFactory->createNew(array('name'=>'b'));
$c = $alphabetFactory->createNew(array('name'=>'c'));
$d = $alphabetFactory->createNew(array('name'=>'d'));
$e = $alphabetFactory->createNew(array('name'=>'e'));

$a->setBrotherEntity('implies',$b,null);
$a->setBrotherEntity('implies',$c,null);

$e->setBrotherEntity('implies',$b,null);
$d->setBrotherEntity('implies',$b,null);




//$alphabetFactory->populateLocal();
$alphabetFactory->getTriplets();
$alphabetFactory->dumpMeta();

print_r($alphabetFactory->dumpMeta());


die();

//Test unpopulated read write
$solarSystemFactory =  new \SandraCore\EntityFactory('planet','solarSystemFile',$system);

$solarSystemFactory->createNew(array('name'=>'Earth'));
$solarSystemFactory->createNew(array('name'=>'Mars'));


echo"hello";






//echo SayHello::world();