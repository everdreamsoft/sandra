<?php
/**
 * Created by PhpStorm.
 * User: Admin
 * Date: 17.02.2019
 * Time: 11:43
 */

namespace InnateSkills\Explainer;


use SandraCore\EntityFactory;

class SystemDisplayer 
{

    public static function dumpDisplay($sandraCoreObject){

        switch (true){

            case $sandraCoreObject instanceOf EntityFactory:
                SystemDisplayer::dumpFactory($sandraCoreObject);
                break;


        }



    }

    public static function dumpEntityFactory(EntityFactory $factory){
        
        $result['system'] = 'foo'; 
        
        print_r($factory->returnMeta());
        
        





    }

}