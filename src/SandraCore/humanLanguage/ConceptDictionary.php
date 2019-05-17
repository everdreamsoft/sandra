<?php
/**
 * Created by EverdreamSoft.
 * User: Shaban Shaame
 * Date: 20.02.19
 * Time: 11:40
 */

namespace SandraCore\humanLanguage;



use SandraCore\Entity;
use SandraCore\EntityBase;
use SandraCore\ForeignEntity;
use SandraCore\ForeignEntityAdapter;
use SandraCore\System;

class ConceptDictionary extends EntityBase
{

    public $name;
    public $language;


    public static function buildForeign($dictionary,System $system){

        $foreignFactory = new ForeignEntityAdapter(null,'',$system);




        $foreignEntity = new ForeignEntity(null,$dictionary,$foreignFactory,'D',$system);

        return $foreignEntity ;


    }

    public static function stringToStringDict($dictionary){



    return $dictionary ;


}




}