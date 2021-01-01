<?php


namespace InnateSkills\SandraHealth;


class MemoryManagement
{

    public static function getSystemMemory(){

        return self::convert(memory_get_usage());
    }

    //memory
    private static function convert($size)
    {
        $unit=array('b','kb','mb','gb','tb','pb');
        return @round($size/pow(1024,($i=floor(log($size,1024)))),2).' '.$unit[$i];
    }

    public static function echoMemoryUsage(){

        echo "Memory ".self::getSystemMemory()."";
    }


}