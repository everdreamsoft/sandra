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





final class MetaDatagraphTest extends TestCase
{

    public function testSimplePortable()
    {
        $sandraToFlush = new SandraCore\System('phpUnit_', true);
        \SandraCore\Setup::flushDatagraph($sandraToFlush);
        $system = new \SandraCore\System('phpUnit_',true);

        $animalFactory = new \SandraCore\EntityFactory('cat','catFile',$system);

        $animalFactoryTestSample = clone $animalFactory ;


        $animalFactory->createNew(array('name'=>'Felix','lastName'=>'Jackson'));
        $animalFactory->createNew(array('name'=>'Blackie'));
        $animalFactory->createNew(array('name'=>'Tabasco'));
        $animalFactory->createNew(array('name'=>'Felix','lastName'=>'Doe'));

        $animalFactoryTestSample->populateLocal();

        $myPortableFactory = $animalFactory->createPortableFactory();
        $myPortableFactory->populateLocal();

        // do we have the same amount of entities ?
        $this->assertCount(count($myPortableFactory->entityArray),$animalFactoryTestSample->entityArray);

        //simple data retreival test

        $firstFelixSample = $animalFactoryTestSample->first('name','Felix');
        $firstFelixPortable = $animalFactoryTestSample->first('name','Felix');

        $this->assertEquals($firstFelixPortable->get('lastName'),$firstFelixSample->get('lastName'));




    }

    public function testPortableJoined()
    {

        $sandraToFlush = new SandraCore\System('phpUnit_', true);
        \SandraCore\Setup::flushDatagraph($sandraToFlush);
        $system = new \SandraCore\System('phpUnit_',true);

        $animalFactory = new \SandraCore\EntityFactory('cat','catFile',$system);
        $localAnimalFactory = clone $animalFactory ;



        $ownerFactory = new \SandraCore\EntityFactory('person','ownerFile',$system);
        $localOwnerFactory = clone $ownerFactory ;

        $jack = $ownerFactory->createNew(array('name'=>'Jack','lastName'=>'Jackson'));
       $john = $ownerFactory->createNew(array('name'=>'John','lastName'=>'Doe'));

        $animalFactory->createNew(array('name'=>'Felix','lastName'=>'Jackson'),array('ownedBy'=>$jack));
        $animalFactory->createNew(array('name'=>'Blackie'));
        $animalFactory->createNew(array('name'=>'Tabasco'));
        $animalFactory->createNew(array('name'=>'Felix','lastName'=>'Doe'),array('ownedBy'=>$john));

        //$localAnimalFactory->populateLocal();

        $localAnimalFactory->joinFactory('ownedBy',$localOwnerFactory);

        //$localAnimalFactory->joinPopulate();
       $portableFactory =  $localAnimalFactory->createPortableFactory();

       $portableFactory->populateLocal();
       $portableFactory->joinPopulate();

       $portableFactory->return2dArray();








    }







}