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





final class EntityTest extends TestCase
{

    public function testEntity()
    {
        $sandraToFlush = new SandraCore\System('phpUnit_',true);
        \SandraCore\Setup::flushDatagraph($sandraToFlush);

        $system = new \SandraCore\System('phpUnit_',true);
        $entityFactory = new \SandraCore\EntityFactory('planet', 'atlasFile', $system);
        $entityFactory->populateLocal();



        //should create a new planet
        $jupiterEntity = $entityFactory->getOrCreateFromRef('name', 'jupiter');

        $this->assertInstanceOf(
            \SandraCore\Entity::class,
            $jupiterEntity
        );

        //we add a paremeter
        $radiusRef = $jupiterEntity->getOrInitReference('radius[km]',69911);

        $this->assertInstanceOf(
            \SandraCore\Reference::class,
            $radiusRef
        );



    }

    public function testCreate()
    {

        $system = new \SandraCore\System('phpUnit_',true);
        $entityFactory = new \SandraCore\EntityFactory('planet', 'atlasFile', $system);

        $dataArray = array('name'=>'Pluto','radius[km]'=>1188.3);


        $plutoEntity = $entityFactory->createNew($dataArray);


        $this->assertInstanceOf(
            \SandraCore\Entity::class,
            $plutoEntity
        );

       $plutoSearch =  $entityFactory->first('name','Pluto');

       //since the search will return two reference we need to remove the radius in order to perform next assert
        $plutoEntity->entityRefs = array_slice($plutoSearch->entityRefs,0,1,true);

        $this->assertEquals(
            $plutoSearch,
            $plutoEntity

        );



    }

    public function testGetOrCreateFromRef()
    {

        $system = new \SandraCore\System('phpUnit_',true);
        $entityFactory = new \SandraCore\EntityFactory('planet', 'atlasFile', $system);
        $entityFactory->populateLocal();



        //should create a new planet
        $saturn = $entityFactory->getOrCreateFromRef('name', 'Saturn');

        $this->assertInstanceOf(
            \SandraCore\Entity::class,
            $saturn
        );

        //we add a paremeter
        $radiusRef = $saturn->getOrInitReference('radius[km]',58232);

        $this->assertInstanceOf(
            \SandraCore\Reference::class,
            $radiusRef
        );



    }

    public function testUnpopulatedSearch()
    {

        $system = new \SandraCore\System('phpUnit_',true);
        $entityFactory = new \SandraCore\EntityFactory('planet', 'atlasFile', $system);

        $plutoSearch1 =  $entityFactory->first('name','Pluto');
        $plutoSearch2 =  $entityFactory->first('name','Pluto');
        $plutoSearch3 =  $entityFactory->first('name','Pluto');

       //Since we made the same search the factory should have only 1 entity not 3
        $this->assertCount(1,$entityFactory->entityArray,'Unpopulated search did not return 1 result');

        //is refmap correctely rebuilt ?
        $saturnSearch =  $entityFactory->first('name','Saturn');
        //$this->assertCount(2,$entityFactory->entityArray,'Unpopulated search did not return 2 result');
        $dump = $entityFactory->dumpMeta();








    }


}