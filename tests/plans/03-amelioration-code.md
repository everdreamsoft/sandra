# Plan d'Amelioration du Code - Phase 3 - Sandra Graph Database

## Vue d'ensemble

Ce document identifie les faiblesses techniques restantes apres les phases 1 et 2 (plans 01 et 02).
L'analyse couvre le code source, les tests, l'architecture et la securite.

**Baseline actuel** : 245 tests, 559 assertions (1E, 3F, 1R pre-existantes)

---

## PRIORITE CRITIQUE

### 1. Correction du bug Reference::reload()

**Probleme** : `Reference.php:38` appelle une fonction `getReference()` qui n'existe pas. C'est un bug critique qui provoquera un fatal error si cette methode est jamais appelee.

**Fichier concerne** :
- `src/SandraCore/Reference.php:38`

**Solution** :
```php
// AVANT (bug)
public function reload(): mixed {
    $newValue = getReference($this->refConcept->idConcept, $this->refEntity->entityId);
    // ...
}

// APRES
public function reload(): mixed {
    $newValue = DatabaseAdapter::getReference(
        $this->refConcept->idConcept,
        $this->refEntity->entityId,
        $this->system
    );
    $this->refValue = $newValue;
    return $newValue;
}
```

**Actions** :
- [ ] Corriger l'appel de fonction dans `Reference::reload()`
- [ ] Ajouter un test unitaire pour `reload()`
- [ ] Verifier si `DatabaseAdapter::getReference()` existe ou doit etre cree

---

### 2. Injections SQL residuelles dans ConceptManager

**Probleme** : Malgre les corrections de la phase 1, plusieurs requetes dans `ConceptManager.php` utilisent encore l'interpolation directe de variables.

**Fichiers concernes** :
- `ConceptManager.php:195` : `WHERE l.id = $linkId` — non parametre
- `ConceptManager.php:134-175` : construction SQL avec `IN($targetConcept[lklk])` — meme avec `sanitizeIntList()`, le pattern est fragile
- `ConceptManager.php:435, 441, 551-558` : melange de SQL parametre et d'interpolation directe

**Solution** :
```php
// AVANT (vulnerable)
$sql = "SELECT ... FROM $this->tableLink l WHERE l.id = $linkId";

// APRES (securise)
$sql = "SELECT ... FROM {$this->tableLink} l WHERE l.id = :linkId";
$stmt = $pdo->prepare($sql);
$stmt->execute([':linkId' => (int)$linkId]);
```

**Actions** :
- [ ] Auditer TOUTES les requetes restantes dans `ConceptManager.php`
- [ ] Remplacer chaque interpolation de variable par des prepared statements
- [ ] Etendre `SanitizationTest.php` avec 20+ vecteurs d'injection SQL
- [ ] Ajouter un test specifique pour `getResultsFromLink()` avec des IDs malicieux

---

### 3. Implementation incomplete de FactoryManager::get()

**Probleme** : `FactoryManager.php:22-27` a une methode `get()` avec un corps vide. Cette methode est appelee mais ne retourne rien.

**Fichier concerne** :
- `src/SandraCore/FactoryManager.php:22`

**Solution** :
```php
// AVANT (vide)
public function get($factoryName) {
    // Rien!
}

// APRES
public function get(string $factoryName): ?FactoryBase {
    return $this->factories[$factoryName] ?? null;
}
```

**Actions** :
- [ ] Implementer `FactoryManager::get()` avec recherche par nom
- [ ] Supprimer `FactoryManager::demo()` (ligne 71-89) — code mort de debug
- [ ] Ajouter des tests unitaires pour `FactoryManager`

---

## PRIORITE HAUTE

### 4. Elimination du couplage statique (System::$pdo)

**Probleme** : La connexion PDO est toujours statique (`System::$pdo`), utilisee dans 50+ endroits. Cela empeche :
- Le multi-tenancy
- Les tests paralleles
- L'injection de mock pour les tests unitaires

**Fichiers concernes** :
- `System.php:17` : `public static ?PdoConnexionWrapper $pdo = null`
- `System.php:18` : `public static ILogger $sandraLogger`
- `DatabaseAdapter.php:18` : `public static ?DatabaseDriverInterface $driver = null`
- `TransactionManager.php:17-19` : tout l'etat est statique
- Tous les fichiers qui font `System::$pdo->get()`

**Solution** : Migration progressive en 3 etapes :
1. Ajouter un getter d'instance `System::getConnection()` (deja fait)
2. Migrer tous les appels `System::$pdo->get()` vers `$system->getConnection()`
3. Rendre `$pdo` private et retirer le static

**Actions** :
- [ ] Recenser tous les usages de `System::$pdo` dans le codebase
- [ ] Migrer chaque usage vers `$system->getConnection()`
- [ ] Rendre `DatabaseAdapter::$driver` non-statique (injecte via System)
- [ ] Refactorer `TransactionManager` en instance (plus de static)
- [ ] Passer `System::$sandraLogger` en propriete d'instance
- [ ] Marquer les proprietes statiques `@deprecated` pour la transition

---

### 5. Proprietes publiques restantes a encapsuler

**Probleme** : De nombreuses proprietes critiques sont encore `public` alors qu'elles devraient etre encapsulees.

**Fichiers concernes** :

**Entity.php** (les plus critiques) :
- `public $subjectConcept` (ligne 26) — acces en lecture partout
- `public $verbConcept` (ligne 28)
- `public $targetConcept` (ligne 30)
- `public $entityId` (ligne 32)
- `public $entityRefs` (ligne 33) — tableau de References
- `public $dataStorage` (ligne 34)
- `public $system` (ligne 37)

**Concept.php** :
- `public $tripletArray` (ligne 13) — structure interne
- `public $entityArray` (ligne 23)
- `public $system` (ligne 22)

**ConceptManager.php** :
- `public $concepts` (ligne 10)
- `public $conceptArray` (ligne 15)
- `public $mainQuerySQL` (ligne 16) — debug seulement

**System.php** :
- `public string $env` (ligne 20)
- `public string $tableSuffix` (ligne 21)
- `public string $tablePrefix` (ligne 22)

**Solution** :
```php
// AVANT
public $subjectConcept;

// APRES
private ?Concept $subjectConcept = null;

public function getSubjectConcept(): ?Concept {
    return $this->subjectConcept;
}

// Pour retrocompatibilite temporaire
public function __get(string $name): mixed {
    if ($name === 'subjectConcept') {
        trigger_error('Direct property access deprecated, use getSubjectConcept()', E_USER_DEPRECATED);
        return $this->subjectConcept;
    }
    // ...
}
```

**Actions** :
- [ ] Phase 1 : Entity.php — ajouter getters, rendre properties private
- [ ] Phase 2 : Concept.php — idem
- [ ] Phase 3 : ConceptManager.php — idem
- [ ] Phase 4 : System.php — rendre env/prefix/suffix readonly
- [ ] Mettre a jour tous les callers (QueryBuilder, ApiHandler, tests, etc.)
- [ ] Ajouter `__get()` deprecie pour retrocompatibilite

---

### 6. Comparaisons non-strictes persistantes

**Probleme** : Le code utilise encore `==` au lieu de `===` dans plusieurs endroits critiques.

**Fichiers concernes** :
- `DatabaseAdapter.php:39, 105, 185` : `== false` au lieu de `=== false`
- `DatabaseAdapter.php:115` : `== 1` au lieu de `=== 1`
- `Reference.php:29` : `==` pour comparaison d'egalite
- `Entity.php:268` : comparaison mixte avec `is_null()`

**Actions** :
- [ ] Remplacer tous les `==` par `===` dans DatabaseAdapter.php
- [ ] Remplacer tous les `==` par `===` dans Reference.php
- [ ] Remplacer `is_null($x)` par `$x === null` partout (plus rapide et coherent)
- [ ] Ajouter une regle PHPStan/Psalm pour interdire `==`

---

### 7. Type hints et return types manquants

**Probleme** : Malgre le travail de la phase 1, 40+ methodes n'ont toujours pas de type hints ou return types.

**Fichiers les plus concernes** :

**ConceptManager.php** — le pire :
- `__construct($su, ...)` : parametres non types (ligne 24-54)
- `sanitizeIntList()` : pas de return type (ligne 65)
- `setFilter()`, `buildFilterSQL()` : pas de types (lignes 78, 88)
- `getResultsFromLink()` : pas de types (ligne 192)
- `getConceptsFromLinkAndTarget()` : pas de return type (ligne 225)
- `getReferences()` : pas de return type (ligne 379)
- `getTriplets()`, `getConceptsFromArray()` : pas de types (lignes 518, 570)

**Entity.php** :
- `get()` : return type manquant (ligne 94)
- `getJoined()` : return type manquant (ligne 112)
- Methodes brother (lignes 184-260) : types incomplets

**FactoryBase.php** :
- `populateLocal()` signature abstraite incomplète (ligne 49)
- `setFilter()` return type incorrect (ligne 181) — annonce `EntityFactory` mais est sur `FactoryBase`

**Actions** :
- [ ] Ajouter tous les type hints a ConceptManager.php (priorite maximale)
- [ ] Completer les return types de Entity.php
- [ ] Corriger la signature de `FactoryBase::setFilter()` (return `static` ou `self`)
- [ ] Ajouter les types manquants dans SystemConcept.php et ConceptFactory.php
- [ ] Configurer PHPStan level 6+ pour detecter les types manquants

---

### 8. Adoption des fonctionnalites PHP 8.1+

**Probleme** : Le code n'utilise pas les fonctionnalites modernes de PHP 8.x qui amelioreraient la lisibilite et la securite.

**Actions** :

**Readonly properties** (PHP 8.1) :
```php
// System.php — proprietes immutables apres construction
public readonly string $env;
public readonly string $tablePrefix;
public readonly string $tableSuffix;
```

**Enums** (PHP 8.1) :
```php
// Remplacer les magic strings pour les directions de tri
enum SortDirection: string {
    case ASC = 'ASC';
    case DESC = 'DESC';
}

// Remplacer les niveaux d'erreur
enum ErrorLevel: int {
    case INFO = 1;
    case WARNING = 2;
    case CRITICAL = 3;
}
```

**Match expression** (PHP 8.0) :
```php
// System.php:138 — remplacer switch
$result = match ($exception->getCode()) {
    '42S02' => $this->handleTableNotFound(),
    default => throw $exception,
};
```

**Constructor property promotion** (PHP 8.0) :
```php
// Reference.php
public function __construct(
    private ?Concept $refConcept,
    private ?Entity $refEntity,
    private mixed $refValue,
    private ?System $system
) {}
```

**Nullsafe operator** (PHP 8.0) :
```php
// AVANT
if (isset($this->subjectConcept) && isset($this->subjectConcept->tripletArray)) { ... }

// APRES
if ($this->subjectConcept?->tripletArray) { ... }
```

- [ ] Mettre a jour `composer.json` : `"php": ">=8.1"`
- [ ] Convertir les proprietes immutables en `readonly`
- [ ] Creer les enums `SortDirection`, `ErrorLevel`, `DatabaseDriver`
- [ ] Remplacer les `switch` par `match` ou c'est pertinent
- [ ] Utiliser constructor property promotion dans les nouvelles classes
- [ ] Utiliser `?->` dans les verifications null chainées

---

## PRIORITE MOYENNE

### 9. Constantes pour les valeurs magiques

**Probleme** : Le code utilise des valeurs numeriques et des strings sans explication.

**Exemples** :
- `System.php:91` : `rand(0, 999)` — instance ID sans constante
- `System.php:140` : `"42S02"` — code d'erreur SQL sans constante
- `System.php:170` : `3` — seuil errorLevelToKill
- `DatabaseAdapter.php:51` : `255` — taille max des strings
- `ConceptManager.php:90` : `0` utilise pour "any filter"

**Solution** :
```php
class SandraConstants {
    const MAX_REFERENCE_LENGTH = 255;
    const DB_ERROR_TABLE_NOT_FOUND = '42S02';
    const MAX_INSTANCE_ID = 9999;
    const ERROR_KILL_THRESHOLD = 3;
    const FILTER_ANY = 0;
    const DEFAULT_ENTITY_LIMIT = 10000;
}
```

**Actions** :
- [ ] Creer `src/SandraCore/SandraConstants.php`
- [ ] Remplacer toutes les valeurs magiques par les constantes
- [ ] Documenter chaque constante avec un commentaire

---

### 10. Gestion de la memoire et references circulaires

**Probleme** : Des references circulaires empechent le garbage collector de liberer la memoire :
- `Entity` → `Factory` → `entityArray[]` → `Entity` (circulaire)
- `Entity` → `Concept` → `entityArray[]` → `Entity` (circulaire)
- `Reference` → `Entity` → `entityRefs[]` → `Reference` (circulaire)

**Fichiers concernes** :
- `Entity.php:33, 37` : Entity detient Factory et System
- `Concept.php:23` : Concept detient entityArray
- `Reference.php:8-11` : Reference detient Entity bidirectionnellement
- `ConceptFactory.php:10` : static `$conceptMapFromId` accumule sans limite

**Solution** :
```php
// Utiliser WeakReference (PHP 8.0) pour casser les cycles
class Entity {
    private \WeakReference $factoryRef;

    public function getFactory(): ?EntityFactory {
        return $this->factoryRef->get();
    }
}

// Nettoyer le cache statique de ConceptFactory
class ConceptFactory {
    private static array $conceptMapFromId = [];

    public static function clearCache(): void {
        self::$conceptMapFromId = [];
    }
}
```

**Actions** :
- [ ] Utiliser `WeakReference` pour les back-references Entity → Factory
- [ ] Ajouter `ConceptFactory::clearCache()` et l'appeler dans `System::destroy()`
- [ ] Ameliorer `Entity::destroy()` pour casser explicitement les cycles
- [ ] Ajouter un test de stress memoire avec 1000+ iterations

---

### 11. Amelioration de la couverture de tests

**Probleme** : Plusieurs tests sont des placeholders et des composants critiques n'ont pas de tests.

**Tests placeholders a corriger** :
- `ConceptTest.php:54` : `$this->assertEquals(1, 1)` — pas de vraie assertion
- `EntityFactoryTest.php:29` : test minimal sans assertion significative
- `JsonSerializationTest.php:968` : `$this->assertEquals(1, 1)` — edge case non teste
- `MemoryTest.php:28` : `$this->assertEquals($initialMemory, $initialMemory)` — toujours vrai

**Composants sans tests** :
- `TransactionManager` : pas de test de rollback ni de transactions imbriquees
- Conditions d'erreur : aucun test des chemins d'exception
- Concurrence : aucun test de race conditions
- Driver PostgreSQL : jamais teste malgre l'implementation
- `CacheTest.php:55-66` : le TTL ne expire jamais (pas de sleep/mock du temps)

**Actions** :
- [ ] Corriger les 4 tests placeholders avec de vraies assertions
- [ ] Creer `tests/TransactionTest.php` : rollback, nesting, wrap, erreur
- [ ] Creer `tests/ErrorHandlingTest.php` : 20+ scenarios d'erreur
- [ ] Ajouter des tests PostgreSQL dans `MultiDatabaseTest.php`
- [ ] Corriger le test TTL dans `CacheTest.php` (utiliser ClockMock ou sleep(1))
- [ ] Ajouter des tests pour `QueryBuilder` : operateurs invalides, LIMIT 0, OFFSET negatif
- [ ] Configurer la couverture de code PHPUnit (`--coverage-html`)

---

### 12. Code mort et methodes incompletes

**Probleme** : Du code mort persiste dans le codebase.

**Code mort identifie** :
- `FactoryManager.php:71-89` : methode `demo()` — clairement du code de debug
- `Concept.php:183-225` : methode `output()` — jamais appelee, fonctionnalite dupliquee
- `EntityFactory.php:860` : `//TODO update brother reference` — TODO orphelin
- `ConceptManager.php:584-595` : `createView()` — SQL construit mais jamais execute
- `ConceptManager.php:244-246` : TODO access control — disabled indefiniment

**Actions** :
- [ ] Supprimer `FactoryManager::demo()`
- [ ] Supprimer `Concept::output()` si non utilise
- [ ] Resoudre ou supprimer le TODO dans `EntityFactory.php:860`
- [ ] Completer ou supprimer `ConceptManager::createView()`
- [ ] Documenter la decision sur le TODO access control (ConceptManager:244)

---

### 13. Hierarchie d'exceptions plus complete

**Probleme** : Seulement 3 exceptions custom existent. La gestion d'erreur est inconsistante : certaines methodes lancent des exceptions, d'autres retournent null silencieusement, d'autres font echo/print_r.

**Exceptions existantes** :
- `SandraException` (base)
- `ConceptNotFoundException`
- `CriticalSystemException`
- `ValidationException`

**Exceptions manquantes** :
```php
namespace SandraCore\Exception;

class DatabaseException extends SandraException {}        // Erreurs SQL
class QueryException extends SandraException {}           // Requetes invalides
class TransactionException extends SandraException {}     // Echec transaction
class EntityNotFoundException extends SandraException {}  // Entity ID introuvable
class ConnectionException extends SandraException {}      // Connexion DB impossible
class ConcurrencyException extends SandraException {}     // Conflit optimistic lock
```

**Actions** :
- [ ] Creer les 6 nouvelles classes d'exception
- [ ] Remplacer les `return null` silencieux par des exceptions dans DatabaseAdapter
- [ ] Remplacer les `echo`/`print_r` dans System.php par des exceptions ou du logging
- [ ] Documenter quelles exceptions chaque methode publique peut lancer

---

## PRIORITE BASSE

### 14. Style de code et conventions

**Probleme** : Inconsistances mineures de style.

**Actions** :
- [ ] Remplacer tous les `array()` par `[]` (ConceptManager.php:15, DatabaseAdapter.php:338)
- [ ] Unifier les commentaires : supprimer les commentaires en francais/anglais mixes
- [ ] Supprimer les `Entity.php:32` commentaires grammaticalement incorrects
- [ ] Ajouter `phpcs.xml` avec regles PSR-12
- [ ] Configurer un pre-commit hook pour le linting

### 15. Constructeur Entity.php avec side-effect

**Probleme** : `Entity::__construct()` (ligne 79) peut retourner un `ForeignEntity` au lieu de `$this`. Ce pattern est un anti-pattern PHP — le constructeur ne devrait jamais retourner un objet different.

**Solution** : Utiliser une factory method :
```php
// AVANT
$entity = new Entity($concept, $refs, $factory);
// Le constructeur retourne parfois ForeignEntity!

// APRES
$entity = Entity::create($concept, $refs, $factory);
// create() retourne Entity|ForeignEntity selon le cas
```

**Actions** :
- [ ] Extraire le pattern ForeignEntity dans une factory method statique
- [ ] Simplifier le constructeur pour ne plus avoir de return

---

## Resume des priorites

| Priorite | Tache | Impact | Effort | Statut |
|----------|-------|--------|--------|--------|
| CRITIQUE | Bug Reference::reload() | Stabilite | Faible | A FAIRE |
| CRITIQUE | SQL injection ConceptManager | Securite | Moyen | A FAIRE |
| CRITIQUE | FactoryManager::get() vide | Fonctionnel | Faible | A FAIRE |
| HAUTE | Elimination static $pdo | Architecture | Eleve | A FAIRE |
| HAUTE | Encapsulation proprietes | Maintenabilite | Eleve | A FAIRE |
| HAUTE | Comparaisons strictes | Fiabilite | Faible | A FAIRE |
| HAUTE | Type hints manquants | Maintenabilite | Moyen | A FAIRE |
| HAUTE | Adoption PHP 8.1+ | Modernite | Moyen | A FAIRE |
| MOYENNE | Constantes magiques | Lisibilite | Faible | A FAIRE |
| MOYENNE | References circulaires | Performance | Moyen | A FAIRE |
| MOYENNE | Couverture de tests | Qualite | Eleve | A FAIRE |
| MOYENNE | Code mort | Lisibilite | Faible | A FAIRE |
| MOYENNE | Hierarchie exceptions | Robustesse | Moyen | A FAIRE |
| BASSE | Style de code | Coherence | Faible | A FAIRE |
| BASSE | Constructeur Entity | Architecture | Moyen | A FAIRE |
