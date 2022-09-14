<?php
/**
 * Created by PhpStorm.
 * User: Admin
 * Date: 13.09.2022
 * Time: 16:00
 */

namespace SandraCore;

use Exception;

interface ILogger
{
    /**
     * Logs message as info.
     *
     * @param string $message Message to be logged.
     *
     * @return void
     */
    public function info(string $message);

    /**
     * Logs error.
     *
     * @param Exception $exception Error to be logged.
     *
     * @return void
     */
    public function error(Exception $exception);


    /**
     * Logs sql query.
     *
     * @param string $query Executed query to be logged.
     * @param float $executionTime Query execution time.
     * @param ?Exception $error Exception during query execution.
     * @return void
     */
    public function query(string $query, float $executionTime, ?Exception $error = null);

}
