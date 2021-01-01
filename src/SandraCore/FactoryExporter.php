<?php
/**
 * Created by EverdreamSoft.
 * User: Shaban Shaame
 * Date: 24.12.20
 * Time: 14:21
 */

namespace SandraCore;


class FactoryExporter
{

    public $is_a ;
    public $contained_in_file ;
    public $entityArray ;


    public function __construct($is_a,$contained_in_file)
    {

        $this->is_a = $is_a ;
        $this->contained_in_file = $contained_in_file ;

    }



}