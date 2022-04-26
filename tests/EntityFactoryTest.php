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
use SandraCore\TestService;





final class EntityFactoryTest extends TestCase
{

    public function testEntityFactory()
    {
        TestService::getFlushTestDatagraph();




       $this->assertEquals('should implement basic tests','should implement basic tests');





    }


    public function testCreateNew()
    {

        $system = TestService::getDatagraph();

        //Test unpopulated read write
        $solarSystemFactory =  new \SandraCore\EntityFactory('planet','solarSystemFile',$system);

        $solarSystemFactory->createNew(array('name'=>'Earth'));
        $solarSystemFactory->createNew(array('name'=>'Mars'));

        $this->assertCount(2,$solarSystemFactory->entityArray);



    }

    public function testSetFilter()
{

    TestService::getFlushTestDatagraph();
    $system = TestService::getDatagraph();
    $alphabetFactory = new \SandraCore\EntityFactory('algebra','algebraFile',$system);

    $alphabetFactoryEmpty = clone $alphabetFactory ;
    $allImpliesB =  new \SandraCore\EntityFactory('algebra','algebraFile',$system);

    $a = $alphabetFactory->createNew(array('name'=>'a'));
    $b = $alphabetFactory->createNew(array('name'=>'b'));
    $c = $alphabetFactory->createNew(array('name'=>'c'));
    $d = $alphabetFactory->createNew(array('name'=>'d'));
    $e = $alphabetFactory->createNew(array('name'=>'e'));
    $f = $alphabetFactory->createNew(array('name' => 'f'));

    $a->setBrotherEntity('implies',$b,null);
    $a->setBrotherEntity('implies',$c,null);

    $e->setBrotherEntity('implies',$b,null);
    $d->setBrotherEntity('implies',$b,null);

    $f->setBrotherEntity('implies', $d, null);

    $c->setBrotherEntity('something',$b,null);




    // $alphabetFactory->populateLocal();
    $alphabetFactory->getTriplets();


    $this->assertCount(6,$alphabetFactory->entityArray);


    $factoryWithOtherIsa = new \SandraCore\EntityFactory('somethingElse','algebraFile',$system);

    $factoryWithOtherIsa->populateLocal();
    $allImpliesB->setFilter('implies',$b);
    $allImpliesB->populateLocal();
    $this->assertCount(0,$factoryWithOtherIsa->entityArray);
    $this->assertCount(3,$allImpliesB->entityArray);


    //advanced filters

    //anything B
    $anythingB = new \SandraCore\EntityFactory('algebra', 'algebraFile', $system);
    $anythingB->setFilter(0,$b);
    $anythingB->populateLocal();

    $this->assertCount(4,$anythingB->entityArray);

    $impliesBorC = new \SandraCore\EntityFactory('algebra', 'algebraFile', $system);




    $impliesBorC->setFilter('implies', array($b, $d));
    $impliesBorC->populateLocal();

    //we should have a,d,e,f
    $this->assertCount(4,$impliesBorC->entityArray);




    //all with an imply
    $allwithImply = new \SandraCore\EntityFactory('algebra', 'algebraFile', $system);
    //$allHaveImply->setFilter('implies');
    $allwithImply->setFilter('implies',0);

    $allwithImply->populateLocal();
    $this->assertCount(4,$allwithImply->entityArray);


    //all don't have imply
    $allNoImply = new \SandraCore\EntityFactory('algebra', 'algebraFile', $system);
    $allNoImply->setFilter('implies',0,true);

    $allNoImply->populateLocal();
   $this->assertCount(2,$allNoImply->entityArray);










}

    public function testStorage()
    {

        $system = TestService::getDatagraph();
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
        $controlJungleBook2 = $controlBook->getBrotherEntityOnVerbAndTarget('hasStorage','content');

        $this->assertEquals($controlJungleBook->getStorage(),$jungleBookText->getStorage());
        $this->assertEquals($controlJungleBook2->getStorage(),$controlJungleBook2->getStorage());



    }

    public function testClassLoading()
    {

        $system = TestService::getFlushTestDatagraph();
        $bookFactory = new \SandraCore\EntityFactory('book', 'library', $system);
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

        $system = TestService::getFlushTestDatagraph();

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

        $system = TestService::getDatagraph();


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

        //setjoined and setBrother are the same functions
        $e->setJoinedEntity('implies',$b,array());
        $d->setJoinedEntity('implies',$b,array());

        $count = $alphabetFactory->countEntitiesOnRequest();
        $this->assertEquals(8, $count, 'error while counting SQL request');

        $allImpliesB = new \SandraCore\EntityFactory('algebra', 'algebraFile', $system);
        $allImpliesB->setFilter('implies', $b);
        $this->assertEquals(3, $allImpliesB->countEntitiesOnRequest(), 'error while counting SQL request');


    }

    public function testEntityQueries()
    {

        $system = TestService::getDatagraph();

        $alphabetFactory = new \SandraCore\EntityFactory('algebra', 'algebraFile', $system);
        $a = $alphabetFactory->first("name", 'a');
        $b = $alphabetFactory->first("name", 'b');

        $this->assertTrue($a->hasTargetConcept($b));
        $this->assertTrue($a->hasVerbAndTarget('implies', $b));
        $this->assertFalse($a->hasVerbAndTarget('impliesX', $b));
        $this->assertFalse($a->hasVerbAndTarget('implies', "falseTing"));


    }


    public function testPopulateSearch()
    {

        $system = TestService::getDatagraph();


        $peopleFactory = new \SandraCore\EntityFactory('person', 'peopleFile', $system);


        //We should have now twice the x and y z
        $peopleFactory->createNew(array('firstname' => 'shaban', 'lastname' => 'shaame'));
        $peopleFactory->createNew(array('firstname' => 'John', 'lastname' => 'Doe'));
        $peopleFactory->createNew(array('firstname' => 'John', 'lastname' => 'AppleSeed'));
        $peopleFactory->createNew(array('firstname' => 'Jack', 'lastname' => 'Johnson'));
        $peopleFactory->createNew(array('firstname' => 'Jack', 'lastname' => 'Roger'));
        $peopleFactory->createNew(array('firstname' => 'Roger', 'lastname' => 'Dalton'));


        $peopleFactory = new \SandraCore\EntityFactory('person', 'peopleFile', $system);
        $entities = $peopleFactory->populateFromSearchResults('John');

        $this->assertCount(2, $entities);

        $peopleFactory = new \SandraCore\EntityFactory('person', 'peopleFile', $system);
        $entities = $peopleFactory->populateFromSearchResults('Roger', 'lastname');

        $this->assertCount(1, $entities);

        $peopleFactory = new \SandraCore\EntityFactory('person', 'peopleFile', $system);
        $entities = $peopleFactory->populateFromSearchResults('Roger'); //whithout specifying first or lastname

        $this->assertCount(2, $entities);

        $peopleFactory = new \SandraCore\EntityFactory('person', 'peopleFile', $system);
        $entities = $peopleFactory->populateFromSearchResults(array('Roger', 'John'));

        $this->assertCount(4, $entities);

        $peopleFactory = new \SandraCore\EntityFactory('person', 'peopleFile', $system);
        $entities = $peopleFactory->populateFromSearchResults(array('Jack', 'Roger'), 'firstname');

        $this->assertCount(3, $entities);

        $peopleFactory = new \SandraCore\EntityFactory('person', 'peopleFile', $system);
        $entities = $peopleFactory->populateFromSearchResults(array('Anonymous', 'Roger'), 'firstname');

        $this->assertCount(1, $entities);

        $peopleFactory = new \SandraCore\EntityFactory('person', 'peopleFile', $system);
        $entities = $peopleFactory->populateFromSearchResults(array('Anonymous', 'Roger', 'Jack', 'john', 'shaban'));

        $this->assertCount(6, $entities);


    }

    public function testRefSorting()
    {

        $system = TestService::getDatagraph();


        $peopleFactory = new \SandraCore\EntityFactory('person', 'peopleFile', $system);

        $peopleFactory->createNew(array('firstname' => 'shaban', 'lastname' => 'shaame', 'age' => '40'));
        $peopleFactory->createNew(array('firstname' => 'John', 'lastname' => 'Doe', 'age' => '20')); //min by number
        $peopleFactory->createNew(array('firstname' => 'John', 'lastname' => 'AppleSeed', 'age' => '100')); // max on number min alphabetically
        $peopleFactory->createNew(array('firstname' => 'Jack', 'lastname' => 'Johnson'));
        $peopleFactory->createNew(array('firstname' => 'Jack', 'lastname' => 'Roger'));
        $peopleFactory->createNew(array('firstname' => 'Roger', 'lastname' => 'Dalton', 'age' => '90'));

        $peopleFactory = new \SandraCore\EntityFactory('person', 'peopleFile', $system);

        //we sort alphabetically so 100 in the min
        $peopleFactory->populateLocal(null, null, null, 'age');

        $entities = $peopleFactory->getEntities();

        //We have only 4 because 2 people don't have age
        $this->assertCount(4, $entities);

        $firstEntity = reset($entities);

        $this->assertEquals(100, $firstEntity->get('age'));

        $peopleFactory = new \SandraCore\EntityFactory('person', 'peopleFile', $system);
        //we number sort
        $peopleFactory->populateLocal(null, null, null, 'age', true);

        //first element
        foreach (array_slice($peopleFactory->getEntities(), 0, 1) as $firstEntity) ;

        $this->assertEquals(20, $firstEntity->get('age'));

        $this->assertEquals('John', $firstEntity->get('firstname'));


    }

    public function exportTest()
    {

        $system = TestService::getDatagraph();
        $peopleFactory = new \SandraCore\EntityFactory('person', 'peopleFile', $system);

        echo $peopleFactory->export();



    }






}