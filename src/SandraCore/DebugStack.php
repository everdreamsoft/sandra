<?php

namespace SandraCore;

/**
 * Includes executed SQLs in a Debug Stack.
 * Inspired by Doctrine's DebugStack
 * @since  0.0
 * @author Sébastien de Graffenried <s.degraff@everdreamsoft.com>
 */
class DebugStack
{

    /**
     * Messages.
     *
     * @var array
     */
    public $messages = array();

    /**
     * Executed SQL queries.
     *
     * @var array
     */
    public $queries = array();

    /**
     * If Debug Stack is enabled (log queries) or not.
     *
     * @var boolean
     */
    public $enabled = true;

    /**
     * @var float|null
     */
    public $start = null;

    /**
     * @var integer
     */
    public $currentQuery = 0;

    /**
     * @var array $connectionInfo

    public $connectionInfo = null;
     * */

    /**
     * Logs a SQL statement somewhere.
     *
     * @param string     $sql    The SQL to be executed.
     * @param array|null $params The SQL parameters.
     * @param array|null $types  The SQL parameter types.
     *
     * @return void
     */
    public function startQuery($sql, array $params = null, array $types = null)
    {
        if ($this->enabled) {
            $trace = debug_backtrace();
            $caller = $trace[2]['function'];
            
            $this->start = microtime(true);
            $this->queries[++$this->currentQuery] = array(
                'sql' => $sql,
                'params' => $params,
                'types' => $types,
                'caller' => $caller,
                'executionMS' => 0
            );
        }
    }

    /**
     * Marks the last started query as stopped. This can be used for timing of queries.
     *
     * @return void
     */
    public function stopQuery($error = null)
    {
        if ($this->enabled) {
            $this->queries[$this->currentQuery]['executionMS'] = (microtime(true) - $this->start) * 1000;
            $this->queries[$this->currentQuery]['error'] = $error;
        }
    }


    public function registerMessage($message)
    {
        if ($this->enabled) {
            $trace = debug_backtrace();
            $caller = $trace[2]['function'];

            $this->start = microtime(true);
            $this->messages[] = array(
               $message,
                'executionMS' => 0
            );
        }
    }
}
