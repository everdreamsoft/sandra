<?php
declare(strict_types=1);

namespace SandraCore;


class FactoryManager
{

    public $sandraInstance ;
    public $factoryRegistry = array() ;

    public function __construct(System $sandraInstance)
    {

        $this->sandraInstance = $sandraInstance ;



    }

    public function get($factoryName)  {




}

    public function create($factoryName,$entityIsa,$entityFile)  {

        $factory = new EntityFactory($entityIsa,$entityFile,$this->sandraInstance);




        return $factory ;


    }

    public function register(EntityFactory $factory)  {

        $this->factoryRegistry[] = $factory;




        return $factory ;


    }


    public function destroy()  {

        foreach ($this->factoryRegistry as $factory){
            $factory->destroy();

        }
        $this->factoryRegistry = null;
        unset($this->sandraInstance);







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

        return $dogFactory->return2dArray();


    }

}