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





final class DisplayerTest extends TestCase
{

    public function testDisplay()
    {

        $sandraToFlush = new SandraCore\System('_phpUnit', true);
        \SandraCore\Setup::flushDatagraph($sandraToFlush);

        $sandra = new SandraCore\System('_phpUnit', true);
        $this->sandra = $sandra ;



        $employeeFactory = new \SandraCore\EntityFactory('employee','employeeFile',$sandra);

        $employee1 = ['firstName'=>'John',
            'LastName'=>'Doe',
            'Age'=>'44',
            'Email'=>'john@company.com'
        ];

        $employeeFactory->createNew($employee1);


        $employeeFactory->createNew(['firstName'=>'Jack',
            'LastName'=>'Bauer',
            'Age'=>'40',
            'Email'=>'jack@company.com'
        ]);

        //get first employee data
        $employeeFullData = $employeeFactory->getDisplay('array');
        $employee1TestData = reset($employeeFullData);
        $this->assertEquals($employee1,$employee1TestData);




    }

    public function testFilteredDisplay()
    {

        $sandraToFlush = new SandraCore\System('_phpUnit', true);
        \SandraCore\Setup::flushDatagraph($sandraToFlush);

        $sandra = new SandraCore\System('_phpUnit', true);
        $this->sandra = $sandra ;

        $employeeFactory = new \SandraCore\EntityFactory('employee','employeeFile',$sandra);



        $employee1 = ['firstName'=>'John',
            'LastName'=>'Doe',
            'Age'=>'44',
            'Email'=>'john@company.com'
        ];

        $employeeFactory->createNew($employee1);


        $employeeFactory->createNew(['firstName'=>'Jack',
            'LastName'=>'Bauer',
            'Age'=>'40',
            'Email'=>'jack@company.com'
        ]);

        $lightData = array_slice($employee1,0,2);



        $employeeFactory->populateLocal();
        $employeeFirstTwoData = $employeeFactory->getDisplay('array',array_keys($lightData));

        $employee1LightData = reset($employeeFirstTwoData);


        $this->assertEquals($employee1LightData,$lightData);



    }

    public function testAdvancedDisplayer()
    {

        $sandraToFlush = new SandraCore\System('_phpUnit', true);
        \SandraCore\Setup::flushDatagraph($sandraToFlush);

        $sandra = new SandraCore\System('_phpUnit', true);
        $this->sandra = $sandra ;

        $employeeFactory = new \SandraCore\EntityFactory('employee','employeeFile',$sandra);



        $employee1 = ['firstName'=>'John',
            'LastName'=>'Doe',
            'Age'=>'44',
            'Email'=>'john@company.com'
        ];

        $employeeFactory->createNew($employee1);


        $employeeFactory->createNew(['firstName'=>'Jack',
            'LastName'=>'Bauer',
            'Age'=>'40',
            'Email'=>'jack@company.com'
        ]);

        $lightData = array_slice($employee1,0,2);



        $employeeFactory->populateLocal();

        $advancedDisplayer = new \SandraCore\displayer\AdvancedDisplay() ;
        $advancedDisplayer->conceptDisplayProperty('Email','email');
        //$advancedDisplayer->setShowUnid();

        $employeeFirstTwoData = $employeeFactory->getDisplay('array',null,null,$advancedDisplayer);

        print_r($employeeFirstTwoData);


    }








}