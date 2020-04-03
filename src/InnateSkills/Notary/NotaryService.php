<?php
/**
 * Created by EverdreamSoft.
 * User: Shaban Shaame
 * Date: 29.03.20
 * Time: 16:35
 */

namespace InnateSkills\Notary;


use SandraCore\System;

class NotaryService
{
    public $logDatagraph = null;

    public function __construct()
    {

        $logDatagraph = new System('log');
        $this->logDatagraph = $logDatagraph;


    }

    public function log(System $system)
    {


    }

}