<?php
/**
 * Created by PhpStorm.
 * User: Admin
 * Date: 17.02.2019
 * Time: 14:07
 */

namespace SandraCore;

use Exception;

class Logger implements ILogger
{

    public function __construct() {}

    public function info(string $message)
    {
    }

    public function error(Exception $exception)
    {
    }

    public function query(string $query, float $executionTime, ?Exception $error = null)
    {
    }
}
