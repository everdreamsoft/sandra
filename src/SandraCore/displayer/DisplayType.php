<?php
/**
 * Created by EverdreamSoft.
 * User: Shaban Shaame
 * Date: 2019-07-19
 * Time: 11:35
 */

namespace SandraCore\displayer;


 abstract class DisplayType
{

    abstract function getDisplay(Displayer $displayer):array ;


}