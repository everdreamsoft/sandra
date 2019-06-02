<?php
/**
 * Created by PhpStorm.
 * User: Admin
 * Date: 02.06.2019
 * Time: 11:48
 */

namespace InnateSkills\Cartographer;

use SandraCore\System;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\Route;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGenerator;

use InnateSkills\Cartographer\ConceptController ;
//use ConceptController ;


use Symfony\Component\Routing\Exception\ResourceNotFoundException;

class Cartographer
{

    public function deployWithMasterKey($key){

echo "pretry";
        try
        {
            // Init basic route
            $foo_route = new Route(
                '/concept',
                array('controller' => 'FooController')
            );

            // Init route with dynamic placeholders
            $foo_placeholder_route = new Route(
                '/concept/{id}',
                array('controller' => 'ConceptController', 'method'=>'load'),
                array('id' => '[0-9]+')
            );

            // Add Route object(s) to RouteCollection object
            $routes = new RouteCollection();
            $routes->add('foo_route', $foo_route);
            $routes->add('conceptView', $foo_placeholder_route);

            // Init RequestContext object
            $context = new RequestContext();
            $context->fromRequest(Request::createFromGlobals());

            // Init UrlMatcher object
            $matcher = new UrlMatcher($routes, $context);

            // Find the current route
            $parameters = $matcher->match($context->getPathInfo());

            // How to generate a SEO URL
            $generator = new UrlGenerator($routes, $context);
            $url = $generator->generate('conceptView', array(
                'id' => 123,
            ));

            echo '<pre>';
            print_r($parameters);
            $this->routing($parameters);

            echo 'Generated URL: ' . $url;
            exit;
        }
        catch (ResourceNotFoundException  $e)
        {
            echo $e->getMessage();
        }

    }

    private function routing($parameters)
    {

        $sandra = new System(null,true);
       // die('stop');
        $controller = "InnateSkills\Cartographer\\".$parameters['controller'];
        $method = $parameters['method'];

        $class = new $controller($sandra);
        $class->$method($parameters);

        //print_r($class);


    }

}