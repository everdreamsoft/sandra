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

    public function testA(): void    {



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
        
             $entityArray = $foreignAdapter->getAllWith('Visa','MR542','Error getting Visa ');
             $noMatch = $foreignAdapter->getAllWith('Visa','MR599','Error getting Visa '); //Unexisting entity
             $invalidRef = $foreignAdapter->getAllWith('VisaX','MR542','Error getting Visa '); // Unexisting Reference


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





}