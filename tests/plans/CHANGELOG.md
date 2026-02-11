# Sandra Graph Database - Changelog des Ameliorations

## Session 1 - 2026-02-11

### Baseline des tests avant modifications
- 55 tests, 134 assertions
- 1 Error pre-existante (ForeignEntityAdapterTest::testFusionAndUpdate - Connection refused mid-test)
- 3 Failures pre-existantes (testCreateOrUpdate, testExport, testWithShortnameAsLink)
- 1 Risky (testBrotherReference - pas d'assertions)

---

### [CRITIQUE] Elimination des die() - Remplacement par des exceptions

**Fichiers crees :**
- `src/SandraCore/Exception/SandraException.php` - Exception de base
- `src/SandraCore/Exception/ConceptNotFoundException.php`
- `src/SandraCore/Exception/CriticalSystemException.php`

**Fichiers modifies :**
- `src/SandraCore/ConceptFactory.php` - `die("invalid concept")` -> `throw new ConceptNotFoundException()`
- `src/SandraCore/System.php` - `die()` dans `systemError()` et `killingProcessLevel()` -> `throw new CriticalSystemException()`
- `src/SandraCore/System.php` - Suppression import inutilise `Opcodes\LogViewer\Log`

**Tests :** Aucune regression

---

### [CRITIQUE] Parametrisation des requetes SQL

**`src/SandraCore/DatabaseAdapter.php` :**
- `rawCreateReference()` : `$conceptId`, `$tripletId` lies via `bindValue(:param, val, PARAM_INT)`
- `rawCreateTriplet()` : 3 requetes securisees (SELECT existant, UPDATE target, INSERT nouveau)
- `getStorage()` : `$entity->entityId` lie en INT
- `rawFlag()` : `$flag->idConcept`, `$entity->entityId` lies en INT
- `rawCreateConcept()` : `$code` lie en STR (etait le plus critique - string user-facing)
- `rawGetTriplets()` : parametres dynamiques avec array de bindings
- `searchConcept()` : tous les IDs de concepts lies via `$bindParamArray`
- `getAllocatedMemory()` : noms de tables et schema lies comme parametres

**`src/SandraCore/SystemConcept.php` :**
- `getFromDBWithCode()` : `$code` lie en STR (remplace interpolation directe avec LIKE)
- `getFromDBWithId()` : `$concept_id` lie en INT (remplace `query()` par `prepare()`)
- `getFromDBWithIdIncludingCode()` : idem
- `getFromDB()` : `$shortname` lie en STR (remplace `pdo->quote()`)

**`src/SandraCore/ConceptManager.php` :**
- Ajout methode `sanitizeIntList()` pour securiser les valeurs IN()
- `buildFilterSQL()` : cast `(int)` + `sanitizeIntList()` sur tous les filter values
- `getConceptsFromLinkAndTarget()` : cast `(int)` sur `$linkConcept`, `$targetConcept`, `$limit`, `$offset`, `$deletedUNID`, `$sortableRef`
- `getConceptsFromLinkAndTarget()` : sanitisation du `$asc` (whitelist ASC/DESC)
- `getReferences()` : cast `(int)` sur `$idConceptLink`, `$idConceptTarget`, `$deletedUNID`, `$sortableRef`
- `getReferences()` : `array_map('intval')` sur `$concepts` et `$refIdArray`
- `getTriplets()` : `array_map('intval')` sur `$concepts`, `$lklkArray`, `$lktgArray`

**Tests :** Aucune regression

---

### [HAUTE] Nettoyage des variables globales

**`src/SandraCore/ConceptManager.php` :**
- Supprime 6x `global` dans : `buildFilterSQL`, `getResultsFromLink`, `getConceptsFromLinkAndTarget`, `getConceptsFromLink`, `getReferences`, `getTriplets`
- Remplace par proprietes d'instance `$this->deletedUnid`, `$this->tableLink`, `$this->tableReference`
- Nettoyage du bloc legacy `$_SESSION['accessToFiles']` (commente avec TODO pour futur refactoring)

**`src/SandraCore/Reference.php` :**
- Supprime 2x `global` dans `hasChangedFromDatabase()` et `reload()` (n'etaient pas utilises)

**`src/SandraCore/Concept.php` :**
- Supprime 1x `global` dans `setConceptTriplets()` (n'etait pas utilise)

**Tests :** Aucune regression

---

### [HAUTE] Amelioration gestion des transactions

**Fichier cree :**
- `src/SandraCore/TransactionManager.php`
  - `begin()` / `commit()` avec compteur de profondeur (transactions imbriquees)
  - `rollback()` automatique
  - `wrap(PDO, callable)` pour execution atomique avec try/catch
  - `isActive()` pour introspection

**`src/SandraCore/DatabaseAdapter.php` :**
- Remplace 5 blocs identiques de gestion de transaction par `TransactionManager::begin()`
- `commit()` delegue a `TransactionManager::commit()`
- Les anciennes proprietes `$transactionStarted` et `$pdo` marquees `@deprecated`

**Tests :** Aucune regression

---

### Etat final des tests - Session 1
```
Tests: 55, Assertions: 134, Errors: 1, Failures: 3, Risky: 1
```
Identique a la baseline - zero regression introduite.

---

## Session 2 - 2026-02-11

### [MOYENNE] Nettoyage du code mort et imports inutilises

**Import supprime :**
- `ConceptManager.php` : `use phpDocumentor\Reflection\Types\Boolean` (jamais utilise)

**Commentaires debug supprimes (14 occurrences) :**
- `Entity.php` : `//echoln(...)`, commentaire systemError
- `ConceptFactory.php` : 2x `//echoln(...)`, `//getDisplayName()`
- `ConceptManager.php` : `//echoln(...)`, `//echo"..."`, commentaires SQL morts, commentaire test incomplet `//be s`
- `Concept.php` : `//die(print_r(...))`
- `ForeignConcept.php` : bloc `//if(is_nan) die(...)` commente
- `ForeignEntity.php` : `//print_r(...)`, `//Todo rebuild factory index`, `//$this->factory`
- `ForeignEntityAdapter.php` : `//$json = $this->testJson()`, `//die(...)`, `//foreach()`, `//$entity->save()`, blocs `$refMap` commentes
- `System.php` : `//print_r($exception)`
- `FactoryManager.php` : `//print_r($dogFactory)`
- `EntityFactory.php` : `//print_r($this->refMap)`, `//$this->foreignAdapter->addToLocalVocabulary(...)`, commentaires `sandraReferenceMap`
- `DatabaseAdapter.php` : `//mysqli_real_escape_string` legacy
- `SystemConcept.php` : `//echo"loading..."`, bloc 35 lignes de fonctions globales mortes en fin de fichier
- `displayer/Displayer.php` : `//ConceptDictionary::buildForeign(...)`, commentaire explicatif

**Migration legacy mysqli -> PDO (4 appels coriges) :**
- `ConceptManager.php` : `getResultsFromLink()` - `mysqli_query()`/`mysqli_fetch_array()` -> `pdo->prepare()`/`fetchAll()`
- `ConceptManager.php` : `getConceptsFromLink()` - idem + suppression du parametre debug `echoln()`
- `SystemConcept.php` : `migrateShortname()` - `mysqli_query()` -> `pdo->prepare()` avec `bindValue` securise + correction du bug `$unidList` indefini

**Tests :** Aucune regression

---

### [MOYENNE] Correction des fautes de nommage et migration PSR-4

**Migration autoload PSR-0 -> PSR-4 :**
- `composer.json` : `"psr-0": {"SandraCore": "src/"}` -> `"psr-4": {"SandraCore\\": "src/SandraCore/"}`
- Suppression du `classmap` redondant

**Corrections de typos dans le code :**
- `DatabaseAdapter.php` : `$udateOnExistingLK` -> `$updateOnExistingLK` (2 occurrences)
- `EntityFactory.php` : `$tripletRetreived` -> `$tripletRetrieved` (5 occurrences)
- `Entity.php` : `$joindedConceptId` -> `$joinedConceptId` (4 occurrences)
- `ConceptManager.php` : `$conditionnalClause` -> `$conditionalClause` (9 occurrences)
- `ConceptManager.php` : commentaire `//eclusion` -> `//exclusion`
- `ConceptManager.php` : suppression commentaire inutile `// This is the CLASS DEFINITION...`

**Tests :** Aucune regression

---

### [MOYENNE] Creation SandraTestCase de base

**Fichier cree :**
- `tests/SandraTestCase.php`
  - Classe abstraite etendant `TestCase`
  - `setUp()` automatique : flush + creation `System`
  - Helpers : `createFactory()`, `createPopulatedFactory()`
  - Pret a l'emploi pour les nouveaux tests et migration progressive des existants

**Tests :** Aucune regression

---

### Etat final des tests - Session 2
```
Tests: 55, Assertions: 134, Errors: 1, Failures: 3, Risky: 1
```
Identique a la baseline - zero regression introduite.

---

## Session 3 - 2026-02-11

### [HAUTE] Typage strict PHP 8+

**`composer.json` :**
- Ajout `"php": ">=8.0"` dans `require`

**`declare(strict_types=1)` ajoute a tous les fichiers source (20+) :**
- Toutes les classes du namespace `SandraCore` et sous-namespaces (`Exception`, `displayer`, etc.)

**Proprietes typees ajoutees :**
- `System.php` : toutes les proprietes typees (`string`, `?FactoryManager`, `?SystemConcept`, `mixed`, `array`, etc.)
- `PdoConnexionWrapper.php` : `private PDO $pdo`, `public string $host`, `public string $database`
- `Reference.php` : `?Concept $refConcept`, `?Entity $refEntity`, `mixed $refValue`, `?System $system`
- `TransactionManager.php` : return types sur toutes les methodes
- `Logger.php`, `ILogger.php`, `NullLogger.php` : return types `: void`
- `Dumpable.php`, `DatagraphUnit.php`, `EntityBase.php` : `declare(strict_types=1)`

**Return types ajoutes :**
- `System` : `getConnection(): \PDO`, `install(): void`, `registerFactory(): void`, `systemError(): string`, `killingProcessLevel(): never`, `entityToClassStore(): Entity`, `destroy(): void`
- `CommonFunctions` : `somethingToConceptId(): mixed`, `somethingToConcept(): Concept`, `createEntity(): Entity`

**Corrections de bugs lies au typage strict :**
- `DatabaseAdapter::rawCreateReference()` : `(string)$value` avant `strlen()` (recevait des int)
- `Concept::setConceptId()` : suppression code mort `is_nan()` (TypeError + appel a methode inexistante)
- `CommonFunctions::createEntity()` : `$updateOnExistingVerb` type `int|bool` (callers passent `false`)
- `SystemConcept::get()` : reordonnancement `is_numeric()` avant `strtolower()` (int cause TypeError)
- `ForeignEntityAdapter` : `json_decode($json, 1)` -> `json_decode($json, true)` (bool attendu, pas int)
- `EntityFactory::countEntitiesOnRequest()` : `(int)$count` cast (DB retourne string)

**Tests :** Aucune regression

---

### [HAUTE] Refactoring de la connexion PDO pour injection de dependance

**Fichier cree :**
- `src/SandraCore/DatabaseConnection.php` - Interface avec `get(): PDO`, `getDatabase(): string`, `getHost(): string`

**Fichiers modifies :**
- `PdoConnexionWrapper.php` : implemente `DatabaseConnection`, ajout `getDatabase()` et `getHost()`
- `System.php` : ajout methode d'instance `getConnection(): \PDO` (alternative au statique `System::$pdo->get()`)
- `DatabaseAdapter.php` : toutes les methodes avec `System $system` utilisent `$system->getConnection()` au lieu de `System::$pdo->get()`
- `DatabaseAdapter::setStorage()` / `getStorage()` : utilisent `$entity->system->getConnection()`
- `DatabaseAdapter::executeSQL()` / `getAllocatedMemory()` : ajout parametre optionnel `?System $system = null` avec fallback `System::$pdo->get()`
- `FactoryBase.php` : `createView()` passe `$sandra` a `executeSQL()`

**Tests :** Aucune regression

---

### [BASSE] Implementation du Logger

**Fichiers crees :**
- `src/SandraCore/NullLogger.php` - Logger no-op explicite (identique au Logger existant)
- `src/SandraCore/ConsoleLogger.php` - Logger vers STDOUT/STDERR avec timing des requetes SQL
- `src/SandraCore/FileLogger.php` - Logger fichier avec timestamps, option `logQueries`

**Design :**
- Tous implementent `ILogger` (info, error, query)
- `Logger.php` existant conserve pour retrocompatibilite
- Configurables via le constructeur de `System` (`$logger` parametre)

**Tests :** Aucune regression

---

### Etat final des tests - Session 3
```
Tests: 55, Assertions: 134, Errors: 1, Failures: 3, Risky: 1
```
Identique a la baseline - zero regression introduite.

---

## Session 4 - 2026-02-11

### [HAUTE] Separation DatabaseAdapter en couches (QueryExecutor)

**Fichier cree :**
- `src/SandraCore/QueryExecutor.php`
  - `execute(PDO, string, array): ?PDOStatement` - Prepare, bind, execute avec logging et error handling
  - `fetchAll(PDO, string, array): ?array` - Execute + fetchAll en une etape
  - `insert(PDO, string, array): ?string` - Execute + lastInsertId
  - Support binding flexible : `[:param => value]` ou `[:param => [value, PDO::PARAM_*]]`

**`src/SandraCore/DatabaseAdapter.php` refactorise :**
- Toutes les methodes `raw*` utilisent maintenant `QueryExecutor` au lieu du boilerplate try/catch/log
- Suppression methode vide `getSujectConcept()`
- Suppression proprietes statiques depreciees `$transactionStarted` et `$pdo`
- `setStorage()` : correction SQL pour parametres nommes uniques (`:storeValue` / `:storeValue2`)

**Tests :** Aucune regression

---

### [MOYENNE] Lazy loading avec Generator (streamEntities)

**`src/SandraCore/EntityFactory.php` :**
- Ajout methode `streamEntities(int $chunkSize, string $asc, ?string $sortByRef, bool $numberSort): \Generator`
- Charge les entites par blocs de `$chunkSize` (defaut 1000) et yield une a une
- Evite le chargement complet en memoire pour les grands volumes
- Reset le ConceptManager entre chaque chunk pour eviter l'accumulation

**Tests :** Aucune regression

---

### [MOYENNE] Amelioration de la visibilite des proprietes (encapsulation)

**`src/SandraCore/EntityFactory.php` :**
- `$populated` : `public` -> `protected` + getter `isPopulated(): bool`
- `$foreignPopulated` : `public` -> `private`
- `$populatedFull` : `public` -> `private` + getter `isFullyPopulated(): bool`
- `$sandraReferenceMap` : `public` -> `protected` + getter `getReferenceMap(): ?array`
- `$brotherMap` : `public` -> `private`

**`src/SandraCore/FactoryBase.php` :**
- `$populated` : `public` -> `protected` (pour permettre le `protected` dans EntityFactory)

**`src/SandraCore/System.php` :**
- `$tableConf` : `public` -> `private` + getter `getTableConf(): string`

**Callers mis a jour :**
- `Gossiper.php` : `$entityFactory->populated` -> `$entityFactory->isPopulated()`
- `Displayer.php` : `$this->mainFactory->sandraReferenceMap` -> `$this->mainFactory->getReferenceMap()`
- `Setup.php` : `$system->tableConf` -> `$system->getTableConf()`
- `ForeignEntityAdapter.php` : ajout return type `?array` a `getReferenceMap()` pour compatibilite

**Tests :** Aucune regression

---

### Etat final des tests - Session 4
```
Tests: 55, Assertions: 134, Errors: 1, Failures: 3, Risky: 1
```
Identique a la baseline - zero regression introduite.

---

## Session 5 - 2026-02-11

### [MOYENNE] Tests completes et ameliores

**`tests/SystemTest.php` reecrit (12 tests, 26 assertions) :**
- `testLoggerDefault()` : verifie que le logger par defaut est `Logger`
- `testLoggerInjection()` : verifie l'injection d'un `NullLogger` via le constructeur
- `testNullLoggerImplementsInterface()` : verifie que NullLogger no-op fonctionne
- `testFileLoggerWritesToFile()` : verifie ecriture fichier (info, error, query, sql_error)
- `testFileLoggerSkipsQueriesWhenDisabled()` : verifie que `logQueries=false` skip les queries normales
- `testConnections()` : verifie `getConnection()`, `System::$pdo`, `PDO` instance
- `testSystemErrorBelowKillLevel()` : verifie que level < kill retourne message
- `testSystemErrorAboveKillLevel()` : verifie que level >= kill throw `CriticalSystemException`
- `testKillingProcessLevel()` : verifie throw avec bon message
- `testTableNames()` : verifie les noms de tables avec prefix
- `testInstanceIdUniqueness()` : verifie unicite des instance IDs
- `testGetTableConf()` : verifie le getter `getTableConf()`

**`tests/NotaryTest.php` reecrit (6 tests, 14 assertions) :**
- `testTransactionManagerBeginAndCommit()` : cycle begin/commit avec isActive()
- `testTransactionManagerNestedDepth()` : transactions imbriquees (depth 2)
- `testTransactionManagerRollback()` : rollback remet isActive a false
- `testTransactionManagerWrap()` : wrap retourne la valeur du callable
- `testTransactionManagerWrapRollsBackOnException()` : rollback auto sur exception
- `testTransactionWrapWithEntityCreation()` : creation d'entites dans un wrap + verification commit

**`src/SandraCore/TransactionManager.php` :**
- Ajout `reset(): void` pour reinitialiser l'etat statique entre les tests

**Tests :** 70 tests, 171 assertions (+15 tests, +37 assertions vs baseline)

---

### [BASSE] Documentation PHPDoc

**Classes documentees avec docblocks de classe :**
- `DatabaseAdapter` : description + docblocks sur les 10 methodes publiques
- `System` : description + docblocks constructeur, `sandraException`, `systemError`, `killingProcessLevel`, `entityToClassStore`
- `Entity` : description + docblocks `get`, `getJoined`, `setBrotherEntity`, `getReference`, `createOrUpdateRef`, `getOrInitReference`, `delete`, `flag`
- `EntityFactory` : description avec exemple d'usage + docblocks `populateLocal`, `createNew`, `streamEntities`
- `FactoryBase` : docblocks `first`, `last`, `getOrCreateFromRef`, `setFilter`
- `TransactionManager` : description classe
- `QueryExecutor` : (deja documente a la creation)

**Tests :** Aucune regression

---

### Etat final des tests - Session 5
```
Tests: 70, Assertions: 171, Errors: 1, Failures: 3, Risky: 1
```
Meme erreurs/failures pre-existantes. +15 tests, +37 assertions vs baseline original.

---

---

## Plan 02 Phase 1 - Fonctionnalites fondamentales

### [HAUTE] Systeme de Validation

**Fichiers crees :**
- `src/SandraCore/Validation/Validator.php` - Moteur de regles de validation
  - Regles integrees : `required`, `string`, `numeric`, `integer`, `min:N`, `max:N`, `email`, `unique`, `maxlength:N`
  - Support de regles personnalisees via `addRule(string $name, callable $fn)`
  - `validate(array $data, EntityFactory $factory)` - lance `ValidationException` si echec
- `src/SandraCore/Validation/ValidationException.php` - Exception avec details des erreurs
  - `getErrors(): array` - `['fieldName' => ['rule1', 'rule2'], ...]`
  - `getFirstError(): string` - message lisible de la premiere erreur

**Fichiers modifies :**
- `src/SandraCore/FactoryBase.php` : propriete `?Validator $validator`, methodes `setValidation()`, `addValidator()`
- `src/SandraCore/EntityFactory.php` : hook de validation au debut de `createNew()`

**Tests :** 19 tests, 30 assertions (`tests/ValidationTest.php`)

---

### [HAUTE] Systeme de Cache

**Fichiers crees :**
- `src/SandraCore/Cache/CacheInterface.php` - Interface PSR-16 inspiree (`get`, `set`, `delete`, `has`, `flush`)
- `src/SandraCore/Cache/MemoryCache.php` - Cache en memoire avec TTL optionnel
- `src/SandraCore/Cache/NullCache.php` - Cache no-op (desactive)

**Fichiers modifies :**
- `src/SandraCore/FactoryBase.php` : propriete `?CacheInterface $cache`, methode `enableCache()`
- `src/SandraCore/EntityFactory.php` :
  - `populateLocal()` : verification du cache avant requete DB, stockage en cache apres
  - `createNew()` : invalidation du cache apres creation
  - Methodes privees `getCacheKey()` et `invalidateCache()`

**Tests :** 15 tests, 27 assertions (`tests/CacheTest.php`)

---

### [HAUTE] Query Builder Fluent

**Fichiers crees :**
- `src/SandraCore/Query/QueryBuilder.php` - API fluent de requetes
  - `whereHasBrother(verb, target)` -> filtre SQL via `setFilter()` (efficace)
  - `whereNotHasBrother(verb, target)` -> filtre SQL exclusion
  - `whereRef(field, operator, value)` -> filtre post-chargement sur references
  - `where(field, value)` -> raccourci pour `whereRef(field, '=', value)`
  - `orderBy(ref, direction)`, `limit(n)`, `offset(n)`
  - `get()` -> `QueryResult`, `first()` -> `?Entity`, `count()` -> `int`
  - Operateurs supportes : `=`, `!=`, `>`, `>=`, `<`, `<=`, `like`
- `src/SandraCore/Query/WhereClause.php` - Condition individuelle (type, field, operator, value, exclusion)
- `src/SandraCore/Query/QueryResult.php` - Resultat pagine
  - Implemente `Countable`, `IteratorAggregate`
  - `count()`, `getTotal()`, `first()`, `last()`, `toArray()`, `isEmpty()`

**Fichiers modifies :**
- `src/SandraCore/FactoryBase.php` : methode `query(): QueryBuilder`

**Design :**
- Le QueryBuilder clone la factory pour ne pas muter l'originale
- Les filtres `whereHasBrother/whereNotHasBrother` sont delegues au SQL (performant)
- Les filtres `whereRef` sont appliques post-chargement (pragmatique pour <10K entites)
- `limit/offset` delegues au SQL quand pas de filtre ref, sinon appliques en memoire

**Tests :** 27 tests, 36 assertions (`tests/QueryBuilderTest.php`)

---

### Etat final des tests - Plan 02 Phase 1
```
Tests: 131, Assertions: 264, Errors: 1, Failures: 3, Risky: 1
```
+61 tests, +93 assertions vs baseline Plan 01. Memes erreurs/failures pre-existantes.

---

## Plan 02 Phase 2 - Fonctionnalites avancees

### [HAUTE] Systeme d'Evenements

**Fichiers crees :**
- `src/SandraCore/Events/EventDispatcher.php` - Registre de listeners simple
  - `on(string $eventName, callable $listener)` - Enregistre un listener
  - `off(string $eventName, callable $listener)` - Supprime un listener
  - `dispatch(string $eventName, EntityEvent $event)` - Dispatch avec stop propagation
  - `hasListeners(string $eventName)` - Verification d'existence
- `src/SandraCore/Events/EntityEvent.php` - Objet de donnees pour les listeners
  - Constantes : `ENTITY_CREATING`, `ENTITY_CREATED`, `ENTITY_UPDATED`, `BROTHER_LINKED`, `ENTITY_DELETING`, `ENTITY_DELETED`
  - `stopPropagation()` / `isPropagationStopped()` pour annulation pre-events

**Fichiers modifies :**
- `src/SandraCore/FactoryBase.php` : propriete `?EventDispatcher $eventDispatcher`, methodes `on()` (lazy-init), `dispatchEvent()` (no-op si null)
- `src/SandraCore/EntityFactory.php` : hooks `ENTITY_CREATING`/`ENTITY_CREATED` dans `createNew()`, `ENTITY_UPDATED` dans `update()`
- `src/SandraCore/Entity.php` : hooks `BROTHER_LINKED` dans `setBrotherEntity()`, `ENTITY_DELETING`/`ENTITY_DELETED` dans `delete()`

**Design :**
- Opt-in : aucun overhead si aucun listener n'est enregistre (dispatcher null)
- Pre-events (`CREATING`, `DELETING`) peuvent annuler l'operation via `stopPropagation()`
- `CREATING` annule leve `SandraException`; `DELETING` annule retourne silencieusement

**Tests :** 17 tests, 28 assertions (`tests/EventSystemTest.php`)

---

### [HAUTE] Traversee de Graphe

**Fichiers crees :**
- `src/SandraCore/Graph/GraphTraverser.php` - Algorithmes de traversee
  - `bfs(Entity, verb, maxDepth)` - Parcours en largeur
  - `dfs(Entity, verb, maxDepth)` - Parcours en profondeur
  - `descendants(Entity, verb, maxDepth)` - Alias BFS
  - `ancestors(Entity, verb, maxDepth)` - BFS sur index inverse
  - `hasCycle(Entity, verb, maxDepth)` - Detection de cycles (DFS avec stack)
  - `findPaths(from, to, verbs, maxDepth)` - Tous les chemins (DFS + Path immutable)
  - `shortestPath(from, to, verbs)` - Plus court chemin (BFS)
- `src/SandraCore/Graph/Path.php` - Chemin immutable
  - `append(Entity)` retourne nouvelle instance
  - `contains(Entity)` par concept ID
  - `getLength()`, `getStart()`, `getEnd()`
- `src/SandraCore/Graph/TraversalResult.php` - Resultat avec groupement par profondeur
  - Implemente `Countable`, `IteratorAggregate`
  - `getAtDepth(int)`, `getMaxDepth()`, `hasCycle()`

**Design :**
- Prerequis : `populateLocal()` + `getTriplets()` avant traversee
- `ancestors()` construit un index inverse en scannant tous les triplets de la factory
- Les verbs sont resolus via `CommonFunctions::somethingToConceptId()`

**Tests :** 20 tests, 29 assertions (`tests/GraphTraversalTest.php`)

---

### [HAUTE] Export/Import CSV

**Fichiers crees :**
- `src/SandraCore/Export/ExporterInterface.php` - Interface `export(EntityFactory, columns): string`
- `src/SandraCore/Export/CsvExporter.php` - Export CSV
  - `export(EntityFactory, columns)` - Export en string via `php://temp` + `fputcsv()`
  - `exportToFile(EntityFactory, filePath, columns)` - Export vers fichier
  - Auto-detection des colonnes via `getReferenceMap()`, filtre `creationTimestamp`
  - Support delimiteur, enclosure, avec/sans header
- `src/SandraCore/Import/CsvImporter.php` - Import CSV
  - `importString(EntityFactory, csv)` / `importFile(EntityFactory, filePath)`
  - `setColumnMapping(array)` - Mapping header-name ou index vers ref shortnames
  - Sans mapping explicite, utilise les headers comme shortnames
  - Appelle `factory->createNew()` par ligne (beneficie validation + events)
  - Accumule les erreurs par ligne dans ImportResult
- `src/SandraCore/Import/ImportResult.php` - Resultat d'import
  - `getCreated()`, `getCreatedCount()`, `getErrors()`, `getErrorCount()`
  - `getTotalRows()`, `hasErrors()`, `isFullySuccessful()`

**Tests :** 18 tests, 40 assertions (`tests/ExportImportTest.php`)

---

### Etat final des tests - Plan 02 Phase 2
```
Tests: 186, Assertions: 361, Errors: 1, Failures: 3, Risky: 1
```
+55 tests, +97 assertions vs Plan 02 Phase 1. Memes erreurs/failures pre-existantes.

---

## Plan 02 Phase 3 - Multi-Database, Full-Text Search, REST API

### [HAUTE] Support Multi-Database

**Fichiers crees :**
- `src/SandraCore/Driver/DatabaseDriverInterface.php` - Interface abstraite pour drivers DB
  - `getDsn()`, `getCreateTableSQL()`, `getUpsertReferenceSQL()`, `getUpsertTripletSQL()`
  - `getUpsertStorageSQL()`, `getRandomOrderSQL()`, `getCastNumericSQL()`, `getName()`
- `src/SandraCore/Driver/MySQLDriver.php` - Driver MySQL (retourne le SQL existant tel quel)
- `src/SandraCore/Driver/SQLiteDriver.php` - Driver SQLite
  - DSN: `sqlite:$database`
  - Tables: `INTEGER PRIMARY KEY AUTOINCREMENT`
  - Upsert: `ON CONFLICT ... DO UPDATE SET`
  - Storage: `INSERT OR REPLACE`
  - Random: `RANDOM()`, Cast: `CAST($col AS REAL)`

**Fichiers modifies :**
- `src/SandraCore/PdoConnexionWrapper.php` : parametre optionnel `?DatabaseDriverInterface $driver`, utilise `$driver->getDsn()` si fourni
- `src/SandraCore/SandraDatabaseDefinition.php` : parametre optionnel driver, utilise `getCreateTableSQL()` par table
- `src/SandraCore/DatabaseAdapter.php` : propriete statique `$driver`, methodes upsert utilisent le driver si defini, fallback SQLite pour `lastInsertId()`
- `src/SandraCore/System.php` : parametre optionnel `$driver` au constructeur, propage aux composants, getter `getDriver()`
- `src/SandraCore/ConceptManager.php` : `getCastNumericSQL()` via driver pour le tri numerique

**Tests :** 14 tests, 42 assertions (`tests/MultiDatabaseTest.php`)
- Theme: bibliotheque de livres (titre, auteur, genre, pages)
- SQLiteDriver: getDsn, createTableSQL, upsertSQL, random, cast, getName
- MySQLDriver: memes verifications
- Integration SQLite: creer System en memoire, creer des livres, populateLocal, getAllWith
- Round-trip SQLite: creer livres, repopuler factory, verifier donnees

---

### [HAUTE] Recherche Full-Text

**Fichiers crees :**
- `src/SandraCore/Search/SearchInterface.php` - Interface de recherche (`search`, `searchByField`)
- `src/SandraCore/Search/BasicSearch.php` - Recherche en memoire compatible toutes bases
  - Filtre post-chargement sur les references via `mb_stripos`
  - Multi-mots: split sur espaces, logique AND (chaque mot doit matcher)
  - Tri par pertinence: match exact (3) > commence par (2) > contient (1)

**Fichiers modifies :**
- `src/SandraCore/FactoryBase.php` : methode `search(string $query, int $limit): array` comme raccourci

**Tests :** 10 tests, 25 assertions (`tests/FullTextSearchTest.php`)
- Theme: annuaire de contacts (nom, prenom, ville, email)
- Chercher "Dupont" trouve le contact
- Chercher "xyz" ne trouve rien
- Recherche insensible a la casse
- Match partiel ("Dup" trouve "Dupont")
- Multi-mots ("Jean Paris" trouve Jean Dupont a Paris)
- searchByField sur un champ specifique (ville uniquement)
- Limite de resultats respectee
- Recherche vide retourne vide
- Tri par pertinence (exact > debut > contient)
- Raccourci factory->search()

---

### [HAUTE] API REST Auto-Generee

**Fichiers crees :**
- `src/SandraCore/Api/ApiRequest.php` - Objet requete simple (method, path, query, body)
- `src/SandraCore/Api/ApiResponse.php` - Objet reponse (status, data, error, toJson, isSuccess)
- `src/SandraCore/Api/ApiHandler.php` - Handler agnostique de framework
  - `register(name, factory, options)` - Enregistre une factory comme ressource
  - `handle(ApiRequest): ApiResponse` - Route et traite la requete
  - Routes generees: GET liste paginee, GET par ID, POST creation, PUT mise a jour, DELETE soft delete
  - Options: `read`, `create`, `update`, `delete`, `searchable`
  - Serialisation: `{id: conceptId, refs: {refName: value, ...}}`

**Tests :** 15 tests, 51 assertions (`tests/RestApiTest.php`)
- Theme: gestion de restaurant (plats, prix, categorie, disponibilite)
- GET liste des plats, GET avec pagination, GET par ID
- GET plat inexistant -> 404
- POST cree un plat -> 201
- POST avec erreur validation -> 422
- PUT met a jour un plat
- DELETE supprime un plat -> 200
- GET avec ?search=pizza
- Route non enregistree -> 404
- Methode non supportee -> 405
- Factory read-only rejette POST/PUT/DELETE
- Plusieurs factories enregistrees
- Liste vide -> 200 avec array vide
- Format JSON de la reponse

---

### Etat final des tests - Plan 02 Phase 3
```
Tests: 225, Assertions: 479, Errors: 1, Failures: 3, Risky: 1
```
+39 tests, +118 assertions vs Plan 02 Phase 2. Memes erreurs/failures pre-existantes.

---

## Plan 02 Phase 4 - Support Brother Entity dans l'API REST

### [HAUTE] Brother Entity Support dans ApiHandler

**Fichier modifie :**
- `src/SandraCore/Api/ApiHandler.php`
  - Ajout `'brothers' => []` dans `$defaultOptions`
  - `serializeEntity()` accepte `$options` et serialise les brothers (target, targetConceptId, refs) groupes par verb
  - `handleGet()` passe `$options` a `serializeEntity()` (single, list, search)
  - `handlePost()` extrait `brothers` du body, cree l'entite, puis appelle `setBrotherEntity()` pour chaque verb/target autorise
  - `handlePut()` meme logique que POST pour ajouter des brothers lors de la mise a jour
  - Seuls les verbs declares dans l'option `brothers` sont acceptes (opt-in)

**Fichier modifie :**
- `docs/api-guide.md` - Section "Brother Entities (Graph Relationships)" ajoutee avec exemples GET/POST/PUT

**Tests :** 8 nouveaux tests dans `tests/RestApiTest.php` (23 tests, 83 assertions total)
- `testGetPlatWithBrothers` - GET single entite inclut brothers
- `testGetListWithBrothers` - GET liste inclut brothers sur chaque item
- `testPostCreatePlatWithBrothers` - POST avec brothers cree entite + liens
- `testPutUpdateAddBrother` - PUT ajoute un nouveau brother
- `testGetWithoutBrothersOptionExcludesBrothers` - factory sans option brothers = pas de cle brothers
- `testBrothersWithReferences` - brother entity a ses references serialisees
- `testGetPlatBrothersMultipleEntries` - entite avec plusieurs brothers sous meme verb
- `testPostBrothersOnReadOnlyRejectsWrite` - factory read-only rejette POST avec brothers

### Etat final des tests - Plan 02 Phase 4
```
Tests: 233, Assertions: 511, Errors: 1, Failures: 3, Risky: 1
```
+8 tests, +32 assertions vs Plan 02 Phase 3. Memes erreurs/failures pre-existantes.

---

## Plan 02 Phase 4b - Support Joined Entity dans l'API REST

### [HAUTE] Joined Entity Support dans ApiHandler

**Fichier modifie :**
- `src/SandraCore/Api/ApiHandler.php`
  - Ajout `'joined' => []` dans `$defaultOptions`
  - `register()` appelle `joinFactory(verb, factory)` pour chaque verb/factory dans l'option `joined`
  - `handleGet()` appelle `joinPopulate()` si `joined` est non-vide (charge les entites jointes)
  - `serializeEntity()` serialise les entites jointes comme `{id, refs}` groupees par verb
  - `handlePost()` extrait `joined` du body, trouve les entites par concept ID, appelle `setJoinedEntity()`
  - `handlePut()` meme logique que POST pour ajouter des joined lors de la mise a jour
  - Seuls les verbs declares dans l'option `joined` sont acceptes (opt-in)

**Fichier modifie :**
- `docs/api-guide.md` - Section "Joined Entities (Cross-Factory Links)" ajoutee avec exemples GET/POST/PUT

**Tests :** 7 nouveaux tests dans `tests/RestApiTest.php`
- `testGetPlatWithJoined` - GET single entite inclut joined avec ingredients
- `testGetListWithJoined` - GET liste inclut joined sur chaque item
- `testPostCreatePlatWithJoined` - POST avec joined cree entite + liens
- `testPutUpdateAddJoined` - PUT ajoute un nouveau joined
- `testGetWithoutJoinedOptionExcludesJoined` - factory sans option joined = pas de cle joined
- `testGetJoinedMultipleEntities` - entite jointe a plusieurs entites sous meme verb
- `testJoinedEntityRefsAreSerialized` - entites jointes ont id (int) et refs serialisees

---

## Plan 01 - TERMINE

Toutes les 14 taches du plan d'amelioration code sont maintenant **FAIT** :

| # | Tache | Statut |
|---|-------|--------|
| 1 | Parametrisation SQL | FAIT |
| 2 | Elimination des die() | FAIT |
| 3 | Transactions atomiques | FAIT |
| 4 | Typage strict PHP 8+ | FAIT |
| 5 | Refactoring PDO | FAIT |
| 6 | Separation DatabaseAdapter | FAIT |
| 7 | Nettoyage globals | FAIT |
| 8 | Lazy loading / memoire | FAIT |
| 9 | Amelioration tests | FAIT |
| 10 | Conventions PSR | FAIT |
| 11 | Visibilite proprietes | FAIT |
| 12 | Documentation | FAIT |
| 13 | Code mort | FAIT |
| 14 | Logger | FAIT |
