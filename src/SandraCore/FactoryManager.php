<?php
/**
 * Created by PhpStorm.
 * User: shaban
 * Date: 12.02.19
 * Time: 15:38
 */

namespace SandraCore;


class FactoryManager
{

    public $sandraInstance ;

    public function __construct(System $sandraInstance)
    {

        $this->sandraInstance = $sandraInstance ;

    }

    public function get($factoryName)  {




}

    public function create($factoryName,$entityIsa,$entityFile)  {

        $factory = new EntityFactory($entityIsa,$entityFile,$this->sandraInstance);
        $factory->populateLocal();



        //print_r($dogFactory);

        return $factory ;


    }

    public function demo($factoryName)  {

        $dogFactory = new EntityFactory('dog','dogFactory',$this->sandraInstance);
        $dogData = array("name"=>'Felix',
            "birthYear" => "2012",
            "favoritfood" => "Chicken",
            "favoritToy" => "ball",
            "quoted" => "hey my quote '"
        );

        $dogFactory->setSuperUser(1);
        $dogFactory->populateLocal();

        $dogFactory->createNewWithAutoIncrementIndex($dogData);

        //print_r($dogFactory);

        return $dogFactory->return2dArray();


    }

}