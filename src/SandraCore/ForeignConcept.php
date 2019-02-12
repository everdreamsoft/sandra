<?php
/**
 * Created by PhpStorm.
 * User: shaban
 * Date: 12.02.19
 * Time: 16:55
 */

namespace SandraCore;


class ForeignConcept extends Concept
{

    public function setConceptId($value)
    {

        if (is_nan($value)) {
            die("We are in a foreign concept it should not be numberic $value");
        }

        $this->idConcept = $value;

    }




}