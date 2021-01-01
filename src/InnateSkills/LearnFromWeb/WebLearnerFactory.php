<?php
/**
 * Created by EverdreamSoft.
 * User: Shaban Shaame
 * Date: 15.02.19
 * Time: 11:17
 */

namespace InnateSkills\LearnFromWeb;

use SandraCore\EntityFactory;
use SandraCore\System;

class WebLearnerFactory extends EntityFactory
{

    public $system ;

    public function __construct(System $system)
    {
        $entityIsa = 'webLearner';
        $entityContainedIn = 'ewbLearnerFile';
        $this->system = $system ;

        parent::__construct($entityIsa, $entityContainedIn, $system);
    }


}