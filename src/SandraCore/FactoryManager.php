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

    public function create($factoryName)  {

    $myFactory = new EntityFactory('test','test',$this->sandraInstance);




    }

}