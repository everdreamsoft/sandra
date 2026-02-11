# Plan d'Amelioration du Code - Sandra Graph Database

## Vue d'ensemble

Ce document identifie les faiblesses techniques du codebase Sandra et propose des ameliorations concretes, classees par priorite.

---

## PRIORITE CRITIQUE

### 1. Securite SQL - Parametrisation des requetes

**Probleme** : Plusieurs requetes dans `DatabaseAdapter.php` et `ConceptManager.php` utilisent l'interpolation directe de variables dans le SQL, ce qui expose a des injections SQL.

**Fichiers concernes** :
- `DatabaseAdapter.php` : `rawCreateTriplet()` ligne ~127 utilise `'$conceptSubject', '$conceptVerb', '$conceptTarget'` directement dans le SQL
- `DatabaseAdapter.php` : `rawGetTriplets()` utilise l'interpolation directe pour les IDs
- `DatabaseAdapter.php` : `rawFlag()` ligne ~228 `flag = $flag->idConcept WHERE id = $entity->entityId`
- `ConceptManager.php` : `buildFilterSQL()` injecte des IDs de concepts directement

**Solution** :
```php
// AVANT (vulnerable)
$sql = "INSERT INTO $tableLink VALUES ('$conceptSubject', '$conceptVerb', '$conceptTarget', 0)";

// APRES (securise)
$sql = "INSERT INTO $tableLink VALUES (:subject, :verb, :target, 0)";
$stmt = $pdo->prepare($sql);
$stmt->execute([
    ':subject' => (int)$conceptSubject,
    ':verb' => (int)$conceptVerb,
    ':target' => (int)$conceptTarget
]);
```

**Actions** :
- [ ] Auditer TOUTES les requetes SQL dans `DatabaseAdapter.php`
- [ ] Auditer TOUTES les requetes dans `ConceptManager.php`
- [ ] Remplacer chaque interpolation par des prepared statements avec binding
- [ ] Caster les IDs en `(int)` en plus du binding pour double securite
- [ ] Ajouter un test de sanitization plus exhaustif (le test actuel `SanitizationTest.php` ne couvre que `searchConcept`)

---

### 2. Gestion des erreurs - Eliminer les `die()`

**Probleme** : Le code utilise `die()` dans plusieurs endroits critiques, ce qui tue le processus entier sans possibilite de recovery.

**Fichiers concernes** :
- `ConceptFactory.php:67` : `die("invalid concept $conceptWeDontKnow")`
- Potentiellement d'autres endroits dans le codebase

**Solution** :
```php
// AVANT
if (!$conceptId) {
    die("invalid concept $conceptWeDontKnow");
}

// APRES
if (!$conceptId) {
    throw new \SandraCore\Exception\ConceptNotFoundException(
        "Concept not found: " . $conceptWeDontKnow
    );
}
```

**Actions** :
- [ ] Creer un namespace `SandraCore\Exception` avec des exceptions specifiques :
  - `ConceptNotFoundException`
  - `EntityCreationException`
  - `DatabaseConnectionException`
  - `InvalidTripletException`
  - `SerializationException`
- [ ] Remplacer tous les `die()` par des exceptions appropriees
- [ ] Ajouter des blocs try/catch dans les points d'entree (factories)
- [ ] Documenter les exceptions que chaque methode publique peut lancer

---

### 3. Atomicite des transactions

**Probleme** : La gestion des transactions dans `DatabaseAdapter` est fragile. Le flag statique `$transactionStarted` est partage entre toutes les operations. Si une exception survient entre le `beginTransaction()` et le `commit()`, les donnees partielles persistent.

**Fichiers concernes** :
- `DatabaseAdapter.php` : toutes les methodes `raw*`
- `EntityFactory.php` : `createNew()` appelle commit() directement

**Solution** :
```php
class TransactionManager
{
    private static int $depth = 0;

    public static function begin(PDO $pdo): void
    {
        if (self::$depth === 0) {
            $pdo->beginTransaction();
        }
        self::$depth++;
    }

    public static function commit(PDO $pdo): void
    {
        self::$depth--;
        if (self::$depth === 0) {
            $pdo->commit();
        }
    }

    public static function rollback(PDO $pdo): void
    {
        if (self::$depth > 0) {
            $pdo->rollBack();
            self::$depth = 0;
        }
    }

    public static function wrap(PDO $pdo, callable $fn)
    {
        self::begin($pdo);
        try {
            $result = $fn();
            self::commit($pdo);
            return $result;
        } catch (\Throwable $e) {
            self::rollback($pdo);
            throw $e;
        }
    }
}
```

**Actions** :
- [ ] Creer `TransactionManager` avec support de transactions imbriquees (savepoints)
- [ ] Refactorer `DatabaseAdapter` pour utiliser `TransactionManager::wrap()`
- [ ] Grouper creation du triplet + references dans une seule transaction atomique
- [ ] Ajouter des tests unitaires verifiant le rollback en cas d'erreur

---

## PRIORITE HAUTE

### 4. Typage strict et compatibilite PHP 8+

**Probleme** : Le code utilise peu de type hints, rendant le debogage difficile et empechant l'analyse statique.

**Actions** :
- [ ] Ajouter des return types a toutes les methodes publiques
- [ ] Ajouter des type hints aux parametres (deja partiellement fait)
- [ ] Utiliser les union types PHP 8 (`Entity|null` au lieu de `?Entity`)
- [ ] Remplacer `@var` annotations par des proprietes typees PHP 7.4+
- [ ] Ajouter `declare(strict_types=1)` a tous les fichiers source
- [ ] Mettre a jour `composer.json` : `"require": { "php": ">=8.0" }`
- [ ] Remplacer les acces dynamiques par des proprietes declarees

**Exemple** :
```php
// AVANT
public $entityArray = array();
public $subjectConcept;

// APRES
/** @var array<int, Entity> */
public array $entityArray = [];
public ?Concept $subjectConcept = null;
```

---

### 5. Refactoring de la connexion PDO

**Probleme** : La connexion PDO est statique dans `System` (`System::$pdo`), ce qui :
- Empeche le multi-tenancy (plusieurs bases de donnees)
- Rend les tests difficiles (pas de mock possible)
- Cree du couplage statique partout

**Solution** :
```php
interface DatabaseConnection
{
    public function getPdo(): PDO;
    public function getDatabase(): string;
}

class PdoConnection implements DatabaseConnection
{
    private PDO $pdo;
    private string $database;

    public function __construct(string $host, string $db, string $user, string $pass)
    {
        $this->pdo = new PDO("mysql:host=$host;dbname=$db", $user, $pass);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->database = $db;
    }

    public function getPdo(): PDO { return $this->pdo; }
    public function getDatabase(): string { return $this->database; }
}
```

**Actions** :
- [ ] Creer l'interface `DatabaseConnection`
- [ ] Injecter la connexion dans `System` au lieu de `static::$pdo`
- [ ] Passer `System` (ou la connexion) aux methodes de `DatabaseAdapter` au lieu d'utiliser `System::$pdo`
- [ ] Supprimer les proprietes statiques de `DatabaseAdapter` (`$pdo`, `$transactionStarted`)
- [ ] Permettre l'injection d'un mock pour les tests unitaires

---

### 6. Separation du `DatabaseAdapter` en couches

**Probleme** : `DatabaseAdapter` est une classe statique monolithique qui melange :
- La construction SQL
- L'execution des requetes
- La gestion des transactions
- Le logging

**Solution** : Separer en 3 classes :
```
DatabaseAdapter (facade)
  ├── QueryBuilder (construction SQL)
  ├── QueryExecutor (execution + logging + error handling)
  └── TransactionManager (transactions)
```

**Actions** :
- [ ] Extraire `QueryBuilder` pour la construction SQL
- [ ] Extraire `QueryExecutor` pour l'execution
- [ ] Rendre `DatabaseAdapter` non-statique et injectable
- [ ] Les methodes `raw*` deviennent des methodes d'instance

---

### 7. Nettoyage des globals et du couplage

**Probleme** : `ConceptManager.php:76` utilise encore `global $tableLink, $tableReference...`.

**Actions** :
- [ ] Supprimer toutes les declarations `global` dans `ConceptManager::buildFilterSQL()`
- [ ] Utiliser les proprietes d'instance (`$this->tableLink`, etc.) qui existent deja
- [ ] Verifier et supprimer tout autre usage de `global` dans le codebase
- [ ] Nettoyer les commentaires debug laisses (`//echoln`, `//die(...)`)

---

## PRIORITE MOYENNE

### 8. Gestion de la memoire et lazy loading

**Probleme** : `populateLocal()` charge TOUTES les entites en memoire, ce qui peut provoquer un OOM sur de grandes bases.

**Solution** : Implementer un systeme de pagination/streaming :
```php
public function populateLocal(int $limit = 10000, int $offset = 0, ...): self
{
    // Deja supporte limit/offset mais pas de lazy iterator
}

// Nouveau : Generator pour le streaming
public function streamEntities(int $chunkSize = 1000): \Generator
{
    $offset = 0;
    do {
        $entities = $this->fetchChunk($chunkSize, $offset);
        foreach ($entities as $entity) {
            yield $entity;
        }
        $offset += $chunkSize;
    } while (count($entities) === $chunkSize);
}
```

**Actions** :
- [ ] Ajouter un mode "generator" a `populateLocal()` pour le streaming
- [ ] Implementer un cache LRU optionnel pour les entites chargees
- [ ] Ajouter une methode `count()` qui fait un `SELECT COUNT(*)` sans charger les donnees
- [ ] Ameliorer `MemoryTest.php` avec des tests de stress sur de grands volumes

---

### 9. Amelioration du systeme de test

**Probleme** : Plusieurs tests sont incomplets (assertions `$this->assertEquals(1, 1)`) et certains patterns se repetent beaucoup.

**Actions** :
- [ ] Completer `SystemTest::testLogger` (marque TODO)
- [ ] Completer `SystemTest::testConnections` (test vide)
- [ ] Completer `NotaryTest::testLog` (return premature)
- [ ] Extraire un `SandraTestCase` de base avec helpers :
  ```php
  abstract class SandraTestCase extends TestCase
  {
      protected System $system;

      protected function setUp(): void
      {
          $flusher = new System('phpUnit_', true);
          Setup::flushDatagraph($flusher);
          $this->system = new System('phpUnit_', true);
      }

      protected function createFactory(string $isa, string $file): EntityFactory
      {
          return new EntityFactory($isa, $file, $this->system);
      }
  }
  ```
- [ ] Monter PHPUnit de 8.* a 10.* ou 11.* (PHP 8 compatible)
- [ ] Ajouter des data providers pour les tests parametriques
- [ ] Ajouter de la couverture de code (phpunit --coverage)
- [ ] Deplacer `MyService.php` et `TestService.php` dans un namespace de test dedie

---

### 10. Nommage et conventions PSR

**Probleme** : Le code melange PSR-0 (autoload dans composer.json) et ne suit pas PSR-4/PSR-12 de facon coherente.

**Actions** :
- [ ] Migrer de PSR-0 a PSR-4 dans `composer.json` :
  ```json
  "autoload": {
      "psr-4": {
          "SandraCore\\": "src/SandraCore/"
      }
  }
  ```
- [ ] Renommer les fichiers/classes pour suivre PSR-4 :
  - Les sous-dossiers (displayer, humanLanguage, queryTraits, Tools) sont bons
  - Verifier que chaque classe est dans le bon namespace
- [ ] Appliquer PSR-12 pour le style :
  - Accolades sur nouvelle ligne pour classes/methodes
  - Espaces consistants
  - Pas de lignes vides multiples
- [ ] Ajouter un `phpcs.xml` pour enforcer le style
- [ ] Corriger les fautes : `$udateOnExistingLK` -> `$updateOnExistingLink`, `retreived` -> `retrieved`

---

### 11. Visibilite des proprietes

**Probleme** : Beaucoup de proprietes sont `public` alors qu'elles devraient etre `private` ou `protected` avec des accesseurs.

**Fichiers les plus concernes** :
- `EntityFactory.php` : `$entityArray`, `$refMap`, `$brotherEntitiesArray`, `$brotherMap` sont tous publics
- `Entity.php` : `$subjectConcept`, `$entityRefs` sont publics
- `System.php` : `$pdo` est statique et public

**Actions** :
- [ ] Identifier les proprietes accedees en dehors de la classe
- [ ] Les rendre `private`/`protected` et creer des getters
- [ ] Pour les proprietes en lecture seule, utiliser `readonly` (PHP 8.1+)
- [ ] Garder la retrocompatibilite avec des `__get()` deprecies si necessaire

---

## PRIORITE BASSE

### 12. Documentation inline (PHPDoc)

**Actions** :
- [ ] Ajouter des docblocks aux methodes publiques principales
- [ ] Documenter les parametres complexes (surtout les array shapes)
- [ ] Documenter les exceptions potentielles avec `@throws`

### 13. Nettoyage du code mort

**Actions** :
- [ ] Supprimer les methodes commentees et le code mort
- [ ] Supprimer les `//die()` et `//echoln()` debug
- [ ] Nettoyer les imports inutilises (`use Opcodes\LogViewer\Log` dans System.php)
- [ ] Supprimer `EntityBase.php` s'il est inutilise
- [ ] Verifier l'utilite de `DatagraphUnit.php`

### 14. Interface Logger

**Probleme** : `Logger.php` implemente `ILogger` mais toutes les methodes sont vides (no-op).

**Actions** :
- [ ] Creer un `NullLogger` explicite pour le no-op actuel
- [ ] Creer un `FileLogger` pour la production
- [ ] Creer un `ConsoleLogger` pour le developpement
- [ ] Rendre le logger configurable via le constructeur de `System`

---

## Resume des priorites

| Priorite | Tache | Impact | Effort | Statut |
|----------|-------|--------|--------|--------|
| CRITIQUE | Parametrisation SQL | Securite | Moyen | FAIT |
| CRITIQUE | Elimination des die() | Stabilite | Faible | FAIT |
| CRITIQUE | Transactions atomiques | Integrite donnees | Moyen | FAIT |
| HAUTE | Typage strict PHP 8+ | Maintenabilite | Eleve | FAIT |
| HAUTE | Refactoring PDO | Testabilite | Moyen | FAIT |
| HAUTE | Separation DatabaseAdapter | Architecture | Eleve | FAIT |
| HAUTE | Nettoyage globals | Qualite | Faible | FAIT |
| MOYENNE | Lazy loading / memoire | Performance | Moyen | FAIT |
| MOYENNE | Amelioration tests | Qualite | Moyen | FAIT |
| MOYENNE | Conventions PSR | Maintenabilite | Faible | FAIT |
| MOYENNE | Visibilite proprietes | Encapsulation | Moyen | FAIT |
| BASSE | Documentation | DX | Faible | FAIT |
| BASSE | Code mort | Lisibilite | Faible | FAIT |
| BASSE | Logger | Observabilite | Faible | FAIT |
