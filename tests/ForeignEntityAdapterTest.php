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
use SandraCore\Entity;
use SandraCore\ForeignEntityAdapter;


final class ForeignEntityAdapterTest extends TestCase
{
    private $baseFactory ;

    public function testCanBeCreated(): void    {

        $sandraToFlush = new SandraCore\System('_phpUnit',true);
        \SandraCore\Setup::flushDatagraph($sandraToFlush);

        $sandra = new SandraCore\System('_phpUnit',true);


        $createdClass =  new \SandraCore\ForeignEntityAdapter('http://www.google.com','',$sandra);

        $this->assertInstanceOf(
            \SandraCore\ForeignEntityAdapter::class,
            $createdClass
        );
    }

    public function testPureForeignAdapter(): void    {



        $sandraToFlush = new SandraCore\System('_phpUnit', true);
        \SandraCore\Setup::flushDatagraph($sandraToFlush);
        
        $sandra = new SandraCore\System('_phpUnit',true);
        $factory = $sandra->factoryManager->create('personnelFactory','person','peopleFile');

        $foreignAdapter = new ForeignEntityAdapter("https://script.google.com/macros/s/AKfycbzJWIW1e0rsVx611g4EcYmZ9SJonpnzwmskDsg_A_9j3qGlht0/exec",'user',$sandra);

        $this->assertInstanceOf(
            \SandraCore\ForeignEntityAdapter::class,
            $foreignAdapter
        );

        $this->assertInstanceOf(
            \SandraCore\EntityFactory::class,
            $factory
        );


        $foreignAdapter->populate();


      $this->assertArrayHasKey('f:1',$foreignAdapter->entityArray,'Failed to populate foreign entity');

      //search
        
             $entityArray = $foreignAdapter->getAllWith('Visa','MR542');
             $noMatch = $foreignAdapter->getAllWith('Visa','MR599'); //Unexisting entity
             $invalidRef = $foreignAdapter->getAllWith('VisaX','MR542'); // Unexisting Reference


            //$entityArray = $foreignAdapter->getAllWith('Visa','MR548','Error getting Visa ');
             $entity = reset($entityArray);
             $this->assertNull($invalidRef,'Invalid Reference returned a response');
             $this->assertNull($noMatch,'Non existing reference value  returned something');
             $this->assertIsArray($entityArray,'Search for Visa MR542 Did not return an Array of Entities');



                    $this->assertInstanceOf(\SandraCore\ForeignEntity::class,$entity,'Entity with Visa MR542 Not found');

        $vocabulary = array(
            'Visa' => 'visa',
            'Title'=> 'Title',
        );

/*
        $foreignAdapter->adaptToLocalVocabulary($vocabulary); // If after populate then fail

        $factory->foreignPopulate($foreignAdapter,500);
        $factory->setFuseForeignOnRef('Visa','visa',$vocabulary);
        $factory->fuseRemoteEntity();
        $factory->saveEntitiesNotInLocal();

*/


    }

    public function testForeignAndLocalFusion(): void    {



        $sandraToFlush = new SandraCore\System('_phpUnit', true);
        \SandraCore\Setup::flushDatagraph($sandraToFlush);

        $sandra = new SandraCore\System('_phpUnit',true);
        $factory = $sandra->factoryManager->create('personnelFactoryTestLocal','person','peopleFile');
        $factory->populateLocal();


        $foreignAdapter = new ForeignEntityAdapter("https://script.google.com/macros/s/AKfycbzJWIW1e0rsVx611g4EcYmZ9SJonpnzwmskDsg_A_9j3qGlht0/exec",'user',$sandra);


// testing limit should return only one entity
        $foreignAdapter->populate(1);

        $this->assertCount(1,$foreignAdapter->entityArray,'The limitation of 1 in Foreign entity failed');


        $vocabulary = array(
            'Visa' => 'visa',
            'Title' => 'Title',
            'Age' => 'age',
            'First Name' => 'firstname'
        );


                $foreignAdapter->adaptToLocalVocabulary($vocabulary); // If after populate then fail
        $controlFactory = clone $factory ;

                $factory->foreignPopulate($foreignAdapter,2);


        $this->assertCount(2,$factory->entityArray,'Our local factory should have 2 foreign concepts');

                $factory->setFuseForeignOnRef('Visa','visa',$vocabulary);
        $entityArray = $factory->getAllWith('Visa', 'MR542');
                $factory->fuseRemoteEntity();
        //  $factory->saveEntitiesNotInLocal();
        $factory->saveEntitiesNotInLocal();

                //We should have a local entity
                $entityArray = $factory->getAllWith('Visa','MR542');
                $entity = reset($entityArray);



        $controlFactory->populateLocal();
        //did the two has been saved in the database
        $this->assertCount(2,$controlFactory->entityArray,'The two foreign entity not successful saved');
        $controlFactory->foreignPopulate($foreignAdapter,3);
        $controlFactory->setFuseForeignOnRef('Visa','visa',$vocabulary);
        $controlFactory->fuseRemoteEntity();

        //Now we should have two local entities and other foreign assuming the size of foreign data




        $dump= $controlFactory->dumpMeta();

        //


    }

    public function testFusionAndUpdate()
    {


        $sandra = new SandraCore\System('_phpUnit', true);

        $foreignAdapter = new ForeignEntityAdapter("https://script.googleusercontent.com/macros/echo?user_content_key=BOhSs78kogORwwxMQYorClE9WArdfTgNaG8ta6jCvMBhEVzGhzeADYbWquh988RM2kzpycxoXJjxXhyDkG6d0zUHNbGjG8gAm5_BxDlH2jW0nuo2oDemN9CCS2h10ox_1xSncGQajx_ryfhECjZEnJiAX7CGU1Nfkw_f4I0D2VGRnCi3suSssTD3wo78iqcZlr0Bqrn_VaBemvrYY_vLpdpytj_A4aoE&lib=M2kgtu8MqTDiOvkCzXVbAhPk0k5gXsDeZ", 'data', $sandra);

        $factory = $sandra->factoryManager->create('personnelFactoryTestLocal', 'person', 'peopleFile');
        $controlFactory = clone $factory;
        $factory->populateLocal();
        $ent = $foreignAdapter->populate(5);


        $vocabulary = array(
            'Visa' => 'visa',
            'Title' => 'Title',
            'Age' => 'age',
            'First Name' => 'firstname'
        );

        $foreignAdapter->adaptToLocalVocabulary($vocabulary); // If after populate then fail

        $ent2 = $factory->foreignPopulate($foreignAdapter, 5);

        $factory->setFuseForeignOnRef('Visa', 'visa', $vocabulary);
        $factory->mergeEntities('Visa', 'visa');
        $factory->fuseRemoteEntity(true);


        print_r($factory->getDisplay('array'));
        $this->assertEquals(1,1);


    }




}