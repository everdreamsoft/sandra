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

     protected $displayer ;

    abstract function getDisplay():array ;



     function bindToDisplayer(Displayer $displayer)
 {
     $this->displayer = $displayer ;
     /** @var Displayer $displayer */
 }

     function destroy()
     {
         $this->displayer = null ;
     }


}