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





final class EntityFactoryTest extends TestCase
{

    public function testEntityFactory()
    {
        $sandraToFlush = new SandraCore\System('phpUnit_', true);
        \SandraCore\Setup::flushDatagraph($sandraToFlush);
        $system = new \SandraCore\System('phpUnit_',true);




       $this->assertEquals('should implement basic tests','should implement basic tests');





    }


    public function testCreateNew()
    {

        $system = new \SandraCore\System('phpUnit_',true);

        //Test unpopulated read write
        $solarSystemFactory =  new \SandraCore\EntityFactory('planet','solarSystemFile',$system);

        $solarSystemFactory->createNew(array('name'=>'Earth'));
        $solarSystemFactory->createNew(array('name'=>'Mars'));

        $this->assertCount(2,$solarSystemFactory->entityArray);



    }

    public function testSetFilter()
{

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




    // $alphabetFactory->populateLocal();
    $alphabetFactory->getTriplets();


    $this->assertCount(5,$alphabetFactory->entityArray);


    $factoryWithOtherIsa = new \SandraCore\EntityFactory('somethingElse','algebraFile',$system);

    $factoryWithOtherIsa->populateLocal();
    $allImpliesB->setFilter('implies',$b);
    $allImpliesB->populateLocal();
    $this->assertCount(0,$factoryWithOtherIsa->entityArray);
    $this->assertCount(3,$allImpliesB->entityArray);


    //advanced filters
    $impliesBorC = new \SandraCore\EntityFactory('algebra', 'algebraFile', $system);

    $f = $alphabetFactory->createNew(array('name' => 'f'));
    $f->setBrotherEntity('implies', $d, null);

    $impliesBorC->setFilter('implies', array($b, $d));
    $impliesBorC->populateLocal();

    //we should have a,d,e,f
    $impliesBorC->dumpMeta();






}

    public function testStorage()
    {

        $system = new \SandraCore\System('phpUnit_',true);
        $bookFactory = new \SandraCore\EntityFactory('book','library',$system);
        $bookFactoryControl = clone $bookFactory ;

        $jungleBook = $bookFactory->createNew(['title'=>'The Jungle Book']);

        $jungleBookText = $jungleBook->setBrotherEntity('hasStorage','content',null);

        $bookText = "Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut 
        labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip
         ex ea commodo consequat. Duis aute irure dolor in reprehenderit 
        in voluptate velit esse cillum dolore eu fugiat nulla pariatur. 
        Excepteur sint occaecat cupidatat non proident, sunt in culpa qui officia deserunt mollit anim id est laborum.";

        $jungleBookText->setStorage($bookText);

        //basic storage variable test
        $this->assertEquals($bookText,$jungleBookText->getStorage());
        $bookFactoryControl->populateLocal();
        $bookFactoryControl->populateBrotherEntities('hasStorage');

        $controlBook = $bookFactoryControl->first('title','The Jungle Book');

        $controlJungleBook = $controlBook->getBrotherEntity('hasStorage','content');

        $this->assertEquals($controlJungleBook->getStorage(),$jungleBookText->getStorage());



    }

    public function testClassLoading()
    {

        $sandraToFlush = new SandraCore\System('phpUnit_', true);
        \SandraCore\Setup::flushDatagraph($sandraToFlush);
        $system = new \SandraCore\System('phpUnit_',true);        $bookFactory = new \SandraCore\EntityFactory('book','library',$system);
        $bookFactoryControl = clone $bookFactory ;

       $customClassFactory = new \SandraCore\EntityFactory('book','library',$system);
        $customClassFactory->createNew(['title'=>'Treasure Island',
            $system->systemConcept->get('class_name')=>\SandraCore\Test\BookEntityForTest::class]);

            $bookFactoryControl->populateLocal();
            $books = $bookFactoryControl->getEntities();

            $this->assertInstanceOf(\SandraCore\Test\BookEntityForTest::class,reset($books));



    }

    public function testUpdate()
    {

        $sandraToFlush = new SandraCore\System('phpUnit_', true);
        \SandraCore\Setup::flushDatagraph($sandraToFlush);
        $system = new \SandraCore\System('phpUnit_', true);

        $rocketFactory = new \SandraCore\EntityFactory('rocket', 'rocketFile', $system);
        $coldRocketFactory = clone $rocketFactory;
        $rocketFactory->populateLocal();

        $stageIName = 'S-IC';
        $hasStageShortname = 'hasStage';
        $stageOneManufacturerName = 'Boeing';

        $saturneVEntityFirst = $rocketFactory->createOrUpdateOnReference('instance', 'myRocket', array('name' => 'Saturn V')
            , array($hasStageShortname =>
                array($stageIName =>
                    array('manufacturer' => $stageOneManufacturerName, 'weight[t]' => 131)),
                "managedBy" => "john"
            ));

        $saturneVEntitySecond = $rocketFactory->createOrUpdateOnReference('instance', 'myRocket', array('name' => 'Saturn V 2', 'aNewUnexistingRef' => 'hello')
            , array($hasStageShortname =>
                array($stageIName =>
                    array('manufacturer' => $stageOneManufacturerName, 'weight[t]' => 2)),
                "managedBy" => "Jack"
            ));


        $firstRocket = $rocketFactory->first('instance', 'myRocket');
        $lastRocket = $rocketFactory->last('instance', 'myRocket');

        //We should have only one rocket
        $this->assertInstanceOf(\SandraCore\Entity::class, $lastRocket);
        $this->assertEquals($firstRocket, $lastRocket);

        //With only the updated values
        $updatedName = $firstRocket->get('name');
        $this->assertEquals('Saturn V 2', $updatedName);

        //we should have two manager
        $manager = $firstRocket->getBrotherEntity("managedBy");


        $coldRocketFactory->populateLocal();

        $firstRocket = $coldRocketFactory->first('instance', 'myRocket');
        $lastRocket = $coldRocketFactory->last('instance', 'myRocket');

        //We should have only one rocket
        $this->assertInstanceOf(\SandraCore\Entity::class, $lastRocket);
        $this->assertEquals($firstRocket, $lastRocket);

        //With only the updated values
        $updatedName = $firstRocket->get('name');
        $this->assertEquals('Saturn V 2', $updatedName);


    }

    public function testCountRequest(){

        $system = new SandraCore\System('phpUnit_');

        $alphabetFactory = new \SandraCore\EntityFactory('algebra','algebraFile',$system);

         $alphabetFactory->createNew(array('name'=>'x'));
         $alphabetFactory->createNew(array('name'=>'y'));
         $alphabetFactory->createNew(array('name'=>'z'));
        $a = $alphabetFactory->createNew(array('name'=>'a'));
        $b = $alphabetFactory->createNew(array('name'=>'b'));
        $c = $alphabetFactory->createNew(array('name'=>'c'));
        $d = $alphabetFactory->createNew(array('name'=>'d'));
        $e = $alphabetFactory->createNew(array('name'=>'e'));

        $a->setBrotherEntity('implies',$b,null);
        $a->setBrotherEntity('implies',$c,null);

        $e->setBrotherEntity('implies',$b,null);
        $d->setBrotherEntity('implies',$b,null);

       $count =  $alphabetFactory->countEntitiesOnRequest();
       $this->assertEquals(8,$count,'error while counting SQL request');

        $allImpliesB =  new \SandraCore\EntityFactory('algebra','algebraFile',$system);
        $allImpliesB->setFilter('implies',$b);
        $this->assertEquals(3,$allImpliesB->countEntitiesOnRequest(),'error while counting SQL request');




    }






}