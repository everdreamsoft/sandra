<?php
declare(strict_types=1);

namespace SandraCore;

use SandraCore\Driver\DatabaseDriverInterface;
use SandraCore\Exception\CriticalSystemException;

/**
 * Core Sandra system instance.
 *
 * Manages the database connection, table names, system concepts, and factories.
 * Each System instance represents one Sandra environment (prefix) connected to a database.
 */
class System
{
    /** @deprecated Points at the most recently instantiated System's connection. Prefer getConnectionWrapper() on the owning System instance. */
    public static ?PdoConnexionWrapper $pdo = null;

    /**
     * Per-process cache of PDO wrappers keyed by host|db|user, so multiple
     * System instances (e.g. a v7 legacy datagraph + a v8 token store) can
     * coexist in the same process without stomping each other's connection.
     *
     * @var array<string,PdoConnexionWrapper>
     */
    private static array $pdoPool = [];

    /** This System's own connection wrapper, independent of static::$pdo. */
    protected ?PdoConnexionWrapper $pdoWrapper = null;

    public static ILogger $sandraLogger;

    public string $env = 'main';
    public string $tableSuffix = '';
    public string $tablePrefix = '';

    public ?FactoryManager $factoryManager = null;
    public ?SystemConcept $systemConcept = null;
    public ?ConceptFactory $conceptFactory = null;

    public mixed $deletedUNID = null;

    public string $conceptTable = '';
    public string $linkTable = '';
    public string $tableReference = '';
    public string $tableStorage = '';
    protected string $tableConf = '';
    public string $tableEmbedding = '';
    public string $sharedTokenTable = 'sandra_api_tokens';
    public string $sharedSessionsTable = 'sandra_mcp_sessions';
    public mixed $foreignConceptFactory = null;

    public int $errorLevelToKill = 3;
    public bool|null $registerStructure = false;
    public array $registerFactory = [];
    public string $instanceId = '';

    private array $entityClassStore = [];

    private ?DatabaseDriverInterface $driver = null;

    /**
     * Initialize a Sandra system.
     *
     * @param string $env Environment prefix for table names
     * @param bool $install If true, create database tables on init
     * @param string $dbHost Database host
     * @param string $db Database name
     * @param string $dbUsername Database username
     * @param string $dbpassword Database password
     * @param ILogger|null $logger Optional logger implementation
     * @param DatabaseDriverInterface|null $driver Optional database driver for multi-DB support
     */
    public function __construct($env = '', $install = false, $dbHost = '127.0.0.1', $db = 'sandra', $dbUsername = 'root', $dbpassword = '', ?ILogger $logger = null, ?DatabaseDriverInterface $driver = null)
    {

        self::$sandraLogger = new Logger();

        if ($driver === null) {
            $driverEnv = strtolower((string) getenv('SANDRA_DRIVER'));
            if ($driverEnv === 'sqlite') {
                $driver = new \SandraCore\Driver\SQLiteDriver();
            }
        }

        $this->driver = $driver;

        if ($driver !== null) {
            DatabaseAdapter::$driver = $driver;
        }

        $poolKey = "$dbHost|$db|$dbUsername";
        if (!isset(self::$pdoPool[$poolKey])) {
            self::$pdoPool[$poolKey] = new PdoConnexionWrapper($dbHost, $db, $dbUsername, $dbpassword, $driver);
        }
        $pdoWrapper = self::$pdoPool[$poolKey];
        $this->pdoWrapper = $pdoWrapper;
        static::$pdo = $pdoWrapper; // BC for legacy callers that read System::$pdo statically

        $this->env = $env;
        $this->tablePrefix = $env;
        $this->resolveTableNames($env);

        if ($install) $this->install();

        $this->systemConcept = new SystemConcept($pdoWrapper, null, $this->conceptTable);
        $this->deletedUNID = $this->systemConcept->get('deleted');
        $this->factoryManager = new FactoryManager($this);
        $this->conceptFactory = new ConceptFactory($this);
        $this->instanceId = rand(0, 999) . "-" . rand(0, 9999) . "-" . rand(0, 999);

        if ($logger)
            self::$sandraLogger = $logger;

    }

    /**
     * Compute the table names for this Sandra instance based on the env.
     *
     * Override in subclasses to support different table-naming conventions
     * (e.g. legacy Sandra 7 uses `Concept{_env}` instead of `{env}_SandraConcept`).
     */
    protected function resolveTableNames(string $env): void
    {
        $this->conceptTable = $env . '_SandraConcept';
        $this->linkTable = $env . '_SandraTriplets';
        $this->tableReference = $env . '_SandraReferences';
        $this->tableStorage = $env . '_SandraDatastorage';
        $this->tableConf = $env . '_SandraConfig';
        $this->tableEmbedding = $env . '_SandraEmbeddings';
    }

    public function getDriver(): ?DatabaseDriverInterface
    {
        return $this->driver;
    }

    /**
     * Get the PDO connection instance.
     * Prefer using this over the static System::$pdo for testability.
     */
    public function getConnection(): \PDO
    {
        return $this->pdoWrapper->get();
    }

    /**
     * Get the PdoConnexionWrapper owned by THIS System instance (safe when
     * multiple Systems coexist in the same process).
     */
    public function getConnectionWrapper(): ?PdoConnexionWrapper
    {
        return $this->pdoWrapper;
    }

    public function getTableConf(): string
    {
        return $this->tableConf;
    }

    public function initDebugStack(): void
    {
    }

    public function install(): void
    {
        SandraDatabaseDefinition::createEnvTables($this->conceptTable, $this->linkTable, $this->tableReference, $this->tableStorage, $this->tableConf, $this->driver, $this->tableEmbedding, $this->sharedTokenTable, $this->sharedSessionsTable);
    }


    /**
     * Handle a Sandra exception: log it, print info, and re-throw.
     *
     * @throws \Exception Always re-throws the exception after logging
     */
    public static function sandraException(\Exception $exception)
    {

        // Pass exception to log
        self::$sandraLogger->error($exception);

        switch ($exception->getCode()) {
            case '42S02' :
                echo "unavailable database";
                break;
        }

        print_r($exception->getMessage());

        throw $exception;

    }

    public function registerFactory(EntityFactory $factory): void
    {
        if ($this->registerStructure) {
            $this->registerFactory[get_class($factory)] = $factory;
        }
    }

    /**
     * Report a system error. Throws if level >= errorLevelToKill.
     *
     * @param mixed $code Error code
     * @param string $source Source class/method
     * @param mixed $level Severity: 1=Notice, 2=Caution, 3=Important, 4=Critical
     * @param string $message Error description
     * @return string The message (if level is below kill threshold)
     * @throws CriticalSystemException If level >= errorLevelToKill
     */
    public function systemError(mixed $code, string $source, mixed $level, string $message): string
    {

        if (isset($level) && $level >= $this->errorLevelToKill) {
            throw new CriticalSystemException("Error : $code From $source : " . $message);
        }

        return $message;

    }

    /**
     * Immediately terminate with a critical exception. Always throws.
     *
     * @throws CriticalSystemException Always
     */
    public function killingProcessLevel(mixed $code, string $source, mixed $level, string $message): never
    {
        throw new CriticalSystemException($message);
    }

    /**
     * Get or create an entity representing a PHP class in the graph.
     *
     * @param string $className The fully qualified class name
     * @param EntityFactory $factory The factory to search/create in
     * @return Entity The entity representing the class
     */
    public function entityToClassStore(string $className, EntityFactory $factory): Entity
    {
        if (!isset($this->entityClassStore[$className])) {
            $factory->populateLocal();
            $this->entityClassStore[$className] = $factory->getOrCreateFromRef('class_name', $className);
        }
        return $this->entityClassStore[$className];
    }

    public function destroy(): void
    {
        $this->factoryManager->destroy();
        $this->conceptFactory->destroy();
        $this->conceptFactory->system = null;
        $this->registerStructure = null;
    }

}
