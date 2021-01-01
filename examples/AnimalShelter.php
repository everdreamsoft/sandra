<?php
/**
 * Created by PhpStorm.
 * User: Admin
 * Date: 27.07.2019
 * Time: 19:02
 */

namespace SandraCore ;

require_once __DIR__ . '/../vendor/autoload.php'; // Autoload files using Composer autoload



use SandraCore\System ;

$sandra = new System('AnimalShelter',true);


$catFactory = new EntityFactory('cat','AnimalFile',$sandra);

//We create 3 cats (is_a => cat) in catFile (contained_in_file => catFile)

$felixEntity = $catFactory->createNew(['name' => 'Felix',
    'birthYear' => 2012]);

$smokeyEntity = $catFactory->createNew(['name' => 'Smokey',
    'birthYear' => 2015]);

//each cat has name reference and birthYear the last cat  Missy has additional "handicap" reference

$missyEntity = $catFactory->createNew(['name' => 'Missy',
    'birthYear' => 2015,
    'handicap' => 'blind'
    ]);


//read data

$catFactoryForRead = new EntityFactory('cat','AnimalFile',$sandra);

//The factory is empty we need to load the 3 cats into memory
$catFactoryForRead->populateLocal(1000); //we read a limit of 1000 cats

$catEntityArray = $catFactoryForRead->getEntities();

foreach ($catEntityArray as $cat){

    echo $cat->get('name')."\n";
    /* returns
    Felix
    Smokey
    Missy
    */
}




