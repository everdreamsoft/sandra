<?php

/**
 * Created by EverdreamSoft.
 * User: Shaban Shaame
 * Date: 22.02.19
 * Time: 12:44
 */
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php'; // Autoload files using Composer autoload

use PHPUnit\Framework\TestCase;





final class SubEntityFactoryTest extends TestCase
{

    public function testEntity()
    {
        $sandraToFlush = new SandraCore\System('phpUnit_', true);
        \SandraCore\Setup::flushDatagraph($sandraToFlush);
        $system = new \SandraCore\System('phpUnit_',true);

        //the goal of the test is to create concepts with linked concept and beeing able to read off the data in subconcepts

        $planetFactory = new \SandraCore\EntityFactory('planet', 'atlasFile', $system);
        $constellationsFactory = new \SandraCore\EntityFactory('constellation', 'constellationFile', $system);
        $starFactory = new \SandraCore\EntityFactory('star', 'atlasFile', $system);

        //setup
        //https://en.wikipedia.org/wiki/List_of_multiplanetary_systems

        //create 3 constellations

$createdConstellation = 'Cetus';

        $starFactory->createNew(array('name'=>'YZ Ceti','type'=>'red dwarf'),
            array('belongToConstellation'=>
                $constellationsFactory->createNew(array('name'=>$createdConstellation,'distance[ly]'=>11.74)
                ),
                'illuminePlanet'=>array(
                    $planetFactory->createNew(array('name'=>'YZ Ceti b','Mass[Em]'=>'0.75')),
                    $planetFactory->createNew(array('name'=>'YZ Ceti c','Mass[Em]'=>'0.04')),
                )
            )
        );

        $starFactory->joinFactory('illuminePlanet',$planetFactory);
        $starFactory->joinFactory('belongToConstellation',$constellationsFactory);
        $starFactory->populateLocal();
        $starFactory->joinPopulate();

//get Constellation name from factory
        $constellationName = $starFactory->first('name','YZ Ceti')->getJoined('belongToConstellation','name');
        $this->assertEquals($createdConstellation,$constellationName,'Error while joining factories');



        $constellationsFactory->createNew(array('name'=>'Cetus','distance[ly]'=>11.74));
        $constellationsFactory->createNew(array('name'=>'Aquarius','distance[ly]'=>15));
        $constellationsFactory->createNew(array('name'=>'Eridanus','distance[ly]'=>20));



        $dataArray = array('name'=>'Aquarius','distance[ly]'=>11.74);
        //$constellation = $entityFactory->createNew($dataArray);





    }




}