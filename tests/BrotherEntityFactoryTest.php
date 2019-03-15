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

       $stageOneName = 'S-IC';
       $hasStageShortname = 'hasStage';
       $stageOneManufacturerName = 'Boeing';

       $saturneVEntity = $rocketFactory->createNew(array('name'=>'Saturn V')
       ,array($hasStageShortname=>array($stageOneName=>array('manufacturer'=>$stageOneManufacturerName,'weight[t]'=>131))));


        $rocketFactory->populateLocal();
        $rocketFactory->getTriplets();
        $rocketFactory->populateBotherEntiies('hasStage','S-IC');

       //created concept shortname
       $conceptId = $system->systemConcept->get($stageOneName);

        //is the brother entity correctly added
        $hasStageUnid = $system->systemConcept->get($hasStageShortname);
        $stageOneName = $system->systemConcept->get($stageOneName);

        $this->assertInstanceOf(\SandraCore\Entity::class,$rocketFactory->brotherEntitiesArray
        [$saturneVEntity->subjectConcept->idConcept]
        [$hasStageUnid][$stageOneName]);

        //Reference manufacturer is correctely added
       $this->assertEquals($stageOneManufacturerName,$rocketFactory->brotherEntitiesArray
       [$saturneVEntity->subjectConcept->idConcept]
       [$hasStageUnid][$stageOneName]->get('manufacturer'));

        $manufacturer = $saturneVEntity->getBrotherReference($hasStageShortname,$stageOneName,'manufacturer');
        $this->assertEquals($stageOneManufacturerName,$manufacturer);

       $rocketFactory->return2dArray();





    }




}