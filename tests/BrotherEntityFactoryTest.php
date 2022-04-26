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





final class BrotherEntityFactoryTest extends TestCase
{

    public function testEntity()
    {

        $sandraToFlush = new SandraCore\System('phpUnit_', true);
        \SandraCore\Setup::flushDatagraph($sandraToFlush);
        $system = new \SandraCore\System('phpUnit_',true);

       $rocketFactory = new \SandraCore\EntityFactory('rocket','rocketFile',$system);

       $stageIName = 'S-IC';
       $hasStageShortname = 'hasStage';
       $stageOneManufacturerName = 'Boeing';

       $saturneVEntity = $rocketFactory->createNew(array('name'=>'Saturn V')
       ,array($hasStageShortname=>
               array($stageIName=>
                   array('manufacturer'=>$stageOneManufacturerName,'weight[t]'=>131))));


        $rocketFactory->populateLocal();
        $rocketFactory->getTriplets();
        $rocketFactory->populateBrotherEntities('hasStage','S-IC');

       //created concept shortname
       $conceptId = $system->systemConcept->get($stageIName);

        //is the brother entity correctly added
        $hasStageUnid = $system->systemConcept->get($hasStageShortname);
        $stageIName = $system->systemConcept->get($stageIName);

        $this->assertInstanceOf(\SandraCore\Entity::class,$rocketFactory->brotherEntitiesArray
        [$saturneVEntity->subjectConcept->idConcept]
        [$hasStageUnid][$stageIName]);

        //Reference manufacturer is correctely added
       $this->assertEquals($stageOneManufacturerName,$rocketFactory->brotherEntitiesArray
       [$saturneVEntity->subjectConcept->idConcept]
       [$hasStageUnid][$stageIName]->get('manufacturer'));

       //get on brother works correctly
        $manufacturer = $saturneVEntity->getBrotherReference($hasStageShortname,$stageIName,'manufacturer');
        $this->assertEquals($stageOneManufacturerName,$manufacturer);

        $stageIIManufacturer = 'North American Aviation';
        $stageIIName = 'S-II';

        $saturneVEntity->setBrotherEntity($hasStageShortname,$system->systemConcept->get($stageIIName),
            array('manufacturer'=>$stageIIManufacturer,'weight[t]'=>480));

        $stageIIManVerif = $saturneVEntity->getBrotherReference($hasStageShortname,$stageIIName,'manufacturer');

        $this->assertEquals($stageIIManufacturer,$stageIIManVerif);



    }

    public function testPartialTriplet()
    {


        $system = new \SandraCore\System('phpUnit_',true);

        $stageIIManufacturer = 'North American Aviation';
        $stageIIName = 'S-II';
        $stage2Unid = $system->systemConcept->get($stageIIName);
        $rocketFactory = new \SandraCore\EntityFactory('rocket','rocketFile',$system);

        $rocketFactory->populateLocal();
        $rocketFactory->getTriplets();
        $rocketFactory->populateBrotherEntities('hasStage');

        $saturnV = $rocketFactory->first('name','Saturn V');
        $manufacturerList = $saturnV->getBrotherReference('hasStage',null,'manufacturer');

        //did we received an array
        $this->assertGreaterThan(1,count($manufacturerList));

        $this->assertEquals($manufacturerList[$stage2Unid],$stageIIManufacturer);

    }

    public function testFindWithBrother()
    {

        $system = new \SandraCore\System('phpUnit_', true);
        $alphabetFactory = new \SandraCore\EntityFactory('algebra', 'algebraFile', $system);

        $alphabetFactoryEmpty = clone $alphabetFactory;
        $allImpliesB = new \SandraCore\EntityFactory('algebra', 'algebraFile', $system);

        $a = $alphabetFactory->createNew(array('name' => 'a'));
        $b = $alphabetFactory->createNew(array('name' => 'b'));
        $c = $alphabetFactory->createNew(array('name' => 'c'));
        $d = $alphabetFactory->createNew(array('name' => 'd'));
        $e = $alphabetFactory->createNew(array('name' => 'e'));

        $a->setBrotherEntity('implies', $b, null);
        $a->setBrotherEntity('implies', $c, null);

        $e->setBrotherEntity('implies', $b, null);
        $d->setBrotherEntity('implies', $b, null);

        $c->setBrotherEntity('something', $b, null);


        $alphabetFactory->populateLocal();
        $alphabetFactory->populateBrotherEntities();

        //we are looking for all alebra implying b
        $allImpliesB = $alphabetFactory->getEntitiesWithBrother('implies', $b);
        $allImplies = $alphabetFactory->getEntitiesWithBrother('implies');
        $anythingToB = $alphabetFactory->getEntitiesWithBrother(0, $b);

        $this->assertCount(3, $allImpliesB);
        $this->assertCount(3, $allImplies);
        $this->assertCount(4, $anythingToB);


    }

    public function testOnExistVerbUpdate()
    {

        $system = new \SandraCore\System('phpUnit_', true);
        $switchesFactory = new \SandraCore\EntityFactory('switch', 'switchFile', $system);

        $switchesFactoryTest = clone $switchesFactory;

        $lightSwitch = $switchesFactory->createNew(['name' => 'lightSwitch']);
        //our switch has two status installed and plugged
        $lightSwitch->setBrotherEntity('status', 'installed', null);
        $lightSwitch->setBrotherEntity('status', 'plugged', null);

        //but our switch can only have one "switch" on or off
        $lightSwitch->setBrotherEntity('switch', 'on', null, true, true);
        $lightSwitch->setBrotherEntity('switch', 'off', null, true, true);
        $lightSwitch->setBrotherEntity('switch', 'on', null, true, true);

        $switchesFactoryTest->populateLocal();
        $switchesFactoryTest->populateBrotherEntities('switch');
        $coldPlugSwitch = $switchesFactoryTest->first('name', 'lightSwitch');

        $statuses = $lightSwitch->getBrotherEntity('status', null);

        $this->assertCount(2, $statuses);


        //the switch should have only one position

        //hotplug
        $position = $statuses = $lightSwitch->getBrotherEntity('switch', null);
        $this->assertCount(1, $position, 'hotplug brother on update failing');

        //coldplug
        $positionCold = $statuses = $coldPlugSwitch->getBrotherEntity('switch', null);
        $this->assertCount(1, $positionCold, 'coldplug brother on update failing');

        $positionCold = $statuses = $coldPlugSwitch->getBrotherEntity('switch', null);
        $positionCold2 = $statuses = $coldPlugSwitch->getBrotherEntitiesOnVerb('switch');
        $this->assertCount(1, $positionCold, 'coldplug brother on update failing');
        $this->assertCount(1, $positionCold2, 'coldplug brother on update failing');





    }

//    public function testHotplugBrotherEntity()
//    {
//
//        $sandraToFlush = new SandraCore\System('phpUnit_', true);
//        \SandraCore\Setup::flushDatagraph($sandraToFlush);
//
//        $system = new \SandraCore\System('phpUnit_', true);
//        $alphabetFactory = new \SandraCore\EntityFactory('algebra', 'algebraFile', $system);
//
//
//        $a = $alphabetFactory->createNew(array('name' => 'a'));
//        $b = $alphabetFactory->createNew(array('name' => 'b'));
//
//
//        $alphabetFactory = new \SandraCore\EntityFactory('algebra', 'algebraFile', $system);
//        $alphabetFactory->populateLocal();
//        $alphabetFactory->populateBrotherEntities('equals');
//
//        $a = $alphabetFactory->first('name', 'a');
//        $b = $alphabetFactory->first('name', 'b');
//        $a->setBrotherEntity('equals', $b, []);
//958668
//
//        $aEquals = $a->getBrotherEntity('equals');
//
//
//    }


}