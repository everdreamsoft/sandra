# Guide d'Utilisation de Sandra - Graph Database Ontologique

## Qu'est-ce que Sandra ?

Sandra est un **graph database ontologique** ecrit en PHP, base sur le modele des **triplets semantiques** (sujet-verbe-objet). Contrairement aux bases relationnelles classiques, Sandra permet de modeliser des relations complexes sans schema rigide, a la maniere de RDF/OWL mais avec une approche plus pragmatique.

### Modele de donnees fondamental

Tout dans Sandra repose sur 3 primitives :

```
CONCEPT    : Une idee, un type, une etiquette (ex: "planet", "name", "orbits")
TRIPLET    : Une relation sujet-verbe-objet entre concepts (ex: Mars -is_a-> planet)
REFERENCE  : Une valeur attachee a un triplet (ex: "name" = "Mars")
```

Une **Entite** dans Sandra est definie par un couple de triplets :
```
Entite "Mars" =
  Triplet 1 : [Mars] --is_a--> [planet]           (le type)
  Triplet 2 : [Mars] --contained_in--> [atlasFile] (le fichier/collection)
  References: name="Mars", radius[km]=3389, mass[earth]=0.107
```

---

## Cas d'Utilisation Concrets

### 1. Gestion de Collections d'Assets Numeriques (NFT, Blockchain)

Sandra est deja utilise par EverdreamSoft pour gerer des assets blockchain. Le test `JsonSerializationTest` montre ce use case.

```php
$system = new System('prod_', false, 'db-host', 'mydb', 'user', 'pass');

// Definir les factories
$assetFactory = new EntityFactory('blockchainizableAsset', 'blockchainizableAssets', $system);
$collectionFactory = new EntityFactory('collection', 'collectionFile', $system);
$contractFactory = new EntityFactory('smartContract', 'contractFile', $system);

// Creer un asset avec ses metadonnees
$asset = $assetFactory->createNew([
    'assetId' => 'ASSET-001',
    'name' => 'Crystal Sword',
    'imgUrl' => 'https://cdn.example.com/sword.png',
    'description' => 'A legendary crystal sword',
]);

// Lier l'asset a une collection et un contrat
$collection = $collectionFactory->createNew(['collectionId' => 'COL-01', 'name' => 'Fantasy Weapons']);
$contract = $contractFactory->createNew(['address' => '0xABC...', 'contractStandard' => 'ERC-721']);

$asset->setBrotherEntity('inCollection', $collection, null);
$asset->setBrotherEntity('bindToContract', $contract, ['deployDate' => '2024-01-15']);

// Synchroniser entre systemes via Gossiper (JSON serialization)
$gossiper = new Gossiper($system);
$json = $gossiper->exposeGossip($assetFactory);
// Envoyer $json a un autre systeme Sandra
```

**Pourquoi Sandra pour ca ?**
- Les assets ont des metadonnees variables (chaque collection a des champs differents)
- Les relations sont complexes (asset -> collection -> blockchain -> contrat)
- Le Gossiper permet la synchronisation entre peers (protocole gossip P2P)

---

### 2. Catalogue Scientifique / Base de Connaissances

Les tests `SubEntityFactoryTest` et `EntityTest` montrent un catalogue astronomique.

```php
$system = new System('astro_', true);

$planetFactory = new EntityFactory('planet', 'atlasFile', $system);
$starFactory = new EntityFactory('star', 'atlasFile', $system);
$constellationFactory = new EntityFactory('constellation', 'constellationFile', $system);

// Creer le systeme solaire
$sun = $starFactory->createNew(['name' => 'Sun', 'type' => 'G-type main-sequence']);

$mercury = $planetFactory->createNew([
    'name' => 'Mercury',
    'revolution[day]' => 88,
    'rotation[day]' => 59,
    'mass[earth]' => 0.06
]);

$venus = $planetFactory->createNew([
    'name' => 'Venus',
    'revolution[day]' => 225,
    'mass[earth]' => 0.82
]);

$earth = $planetFactory->createNew([
    'name' => 'Earth',
    'revolution[day]' => 365,
    'rotation[h]' => 24,
    'mass[earth]' => 1
]);

// Lier les planetes a leur etoile
$sun->setBrotherEntity('illuminates', $mercury, ['distance[au]' => '0.39']);
$sun->setBrotherEntity('illuminates', $venus, ['distance[au]' => '0.72']);
$sun->setBrotherEntity('illuminates', $earth, ['distance[au]' => '1.0']);

// Requeter le graphe
$starFactory->joinFactory('illuminates', $planetFactory);
$starFactory->populateLocal();
$starFactory->joinPopulate();

$sunEntity = $starFactory->first('name', 'Sun');
$planets = $sunEntity->getJoinedEntities('illuminates');
// Retourne les 3 planetes avec toutes leurs references
```

**Pourquoi Sandra pour ca ?**
- Les unites sont integrees dans les noms de references : `mass[earth]`, `distance[au]`
- La structure est naturellement un graphe (etoile -> planetes -> satellites)
- Pas besoin de modifier un schema pour ajouter un nouveau type d'attribut

---

### 3. Systeme de Gestion de Contenu (CMS Semantique)

```php
$system = new System('cms_');

$articleFactory = new EntityFactory('article', 'contentFile', $system);
$authorFactory = new EntityFactory('author', 'peopleFile', $system);
$tagFactory = new EntityFactory('tag', 'taxonomyFile', $system);
$categoryFactory = new EntityFactory('category', 'taxonomyFile', $system);

// Creer un article
$article = $articleFactory->createNew([
    'title' => 'Introduction to Graph Databases',
    'slug' => 'intro-graph-db',
    'publishDate' => '2024-06-15',
    'status' => 'published',
]);

// Le contenu long va dans le storage (pas limite a 255 chars)
DatabaseAdapter::setStorage($article, '<h1>Introduction...</h1><p>Long HTML content...</p>');

// Relations
$author = $authorFactory->createNew(['firstName' => 'Jane', 'lastName' => 'Doe', 'email' => 'jane@example.com']);
$article->setBrotherEntity('writtenBy', $author, ['role' => 'primary']);

$tag1 = $tagFactory->createNew(['name' => 'database']);
$tag2 = $tagFactory->createNew(['name' => 'graphdb']);
$article->setBrotherEntity('taggedWith', $tag1, null);
$article->setBrotherEntity('taggedWith', $tag2, null);

$cat = $categoryFactory->createNew(['name' => 'Technology']);
$article->setBrotherEntity('inCategory', $cat, null);

// Rechercher les articles d'un auteur
$authorArticles = new EntityFactory('article', 'contentFile', $system);
$authorArticles->setFilter('writtenBy', $author);
$authorArticles->populateLocal();
// Retourne tous les articles ecrits par Jane Doe

// Afficher en JSON pour une API
$display = $articleFactory->getDisplay('array', ['title', 'slug', 'publishDate']);
```

**Pourquoi Sandra pour ca ?**
- Les articles, tags, categories forment un graphe naturel
- Le storage supporte le contenu long (HTML, Markdown)
- Le Displayer permet de formater la sortie pour differents besoins (API, template)

---

### 4. Gestion d'Identites et Relations Sociales

```php
$system = new System('social_');

$personFactory = new EntityFactory('person', 'peopleFile', $system);
$orgFactory = new EntityFactory('organization', 'orgFile', $system);
$skillFactory = new EntityFactory('skill', 'skillFile', $system);

// Creer des personnes
$alice = $personFactory->createNew(['name' => 'Alice', 'email' => 'alice@example.com']);
$bob = $personFactory->createNew(['name' => 'Bob', 'email' => 'bob@example.com']);
$charlie = $personFactory->createNew(['name' => 'Charlie']);

// Relations sociales
$alice->setBrotherEntity('knows', $bob, ['since' => '2020', 'context' => 'work']);
$alice->setBrotherEntity('knows', $charlie, ['since' => '2018', 'context' => 'school']);
$bob->setBrotherEntity('knows', $charlie, null);

// Relations professionnelles
$company = $orgFactory->createNew(['name' => 'TechCorp', 'industry' => 'software']);
$alice->setBrotherEntity('worksAt', $company, ['role' => 'CTO', 'since' => '2021']);
$bob->setBrotherEntity('worksAt', $company, ['role' => 'Developer', 'since' => '2022']);

// Competences
$php = $skillFactory->createNew(['name' => 'PHP']);
$graphdb = $skillFactory->createNew(['name' => 'Graph Databases']);
$alice->setBrotherEntity('hasSkill', $php, ['level' => 'expert']);
$alice->setBrotherEntity('hasSkill', $graphdb, ['level' => 'intermediate']);

// Trouver toutes les personnes qui travaillent chez TechCorp
$employees = new EntityFactory('person', 'peopleFile', $system);
$employees->setFilter('worksAt', $company);
$employees->populateLocal();
// Retourne Alice et Bob

// Trouver qui connait Bob
$friendsOfBob = new EntityFactory('person', 'peopleFile', $system);
$friendsOfBob->setFilter('knows', $bob);
$friendsOfBob->populateLocal();
// Retourne Alice
```

---

### 5. Integration de Donnees Externes (ETL / Data Pipeline)

Le `ForeignEntityAdapter` est unique a Sandra et permet d'integrer des APIs externes.

```php
$system = new System('etl_');

// Source : API externe de pays
$foreignAdapter = new ForeignEntityAdapter(
    'https://api.example.com/countries',
    'data.countries',  // Path dans le JSON pour acceder aux items
    $system
);

// Mapper le vocabulaire etranger vers le local
$foreignAdapter->adaptToLocalVocabulary([
    'countryName' => 'name',
    'countryCode' => 'code',
    'population' => 'population',
    'continent' => 'continent',
]);

// Charger les donnees distantes
$foreignAdapter->populate(100);

// Verifier une entite avant fusion
$france = $foreignAdapter->first('name', 'France');
echo $france->getReference('population'); // "67390000"

// Creer la factory locale
$countryFactory = new EntityFactory('country', 'geoFile', $system);

// Fusionner les entites etrangeres dans la base locale
$foreignAdapter->setLocalReference('code');     // Cle de correspondance locale
$foreignAdapter->setRemoteFuseReference('code'); // Cle de correspondance distante

// Fusion : les entites existantes sont mises a jour, les nouvelles sont creees
$foreignAdapter->fuseRemoteEntity($countryFactory);

// Sauvegarder les entites qui n'existaient pas en local
$foreignAdapter->saveEntitiesNotInLocal($countryFactory);
```

**Pourquoi Sandra pour ca ?**
- Le `ForeignEntityAdapter` gere tout : HTTP, parsing JSON, mapping de vocabulaire, fusion
- Support des paths JSON imbriques (`data.countries.items`)
- Strategies de fusion configurables (mise a jour, creation seule, etc.)

---

### 6. Gestion de Configurations et Feature Flags

```php
$system = new System('config_');

$configFactory = new EntityFactory('config', 'configFile', $system);
$featureFactory = new EntityFactory('feature', 'featureFile', $system);
$envFactory = new EntityFactory('environment', 'envFile', $system);

// Environnements
$prod = $envFactory->createNew(['name' => 'production']);
$staging = $envFactory->createNew(['name' => 'staging']);

// Feature flags
$darkMode = $featureFactory->createNew([
    'name' => 'dark-mode',
    'description' => 'Enable dark mode UI',
]);

$darkMode->setBrotherEntity('enabledIn', $staging, ['percentage' => '100']);
$darkMode->setBrotherEntity('enabledIn', $prod, ['percentage' => '25']); // 25% rollout

// Verifier un flag
$featureFactory->populateLocal();
$featureFactory->populateBrotherEntities();
$feature = $featureFactory->first('name', 'dark-mode');
$envs = $feature->getBrotherEntity('enabledIn');
```

---

### 7. Systeme de Versioning / Audit Trail

```php
$system = new System('audit_');

$documentFactory = new EntityFactory('document', 'docFile', $system);
$versionFactory = new EntityFactory('version', 'versionFile', $system);
$userFactory = new EntityFactory('user', 'userFile', $system);

$user = $userFactory->createNew(['name' => 'Editor']);

// Creer un document
$doc = $documentFactory->createNew([
    'title' => 'Company Policy',
    'status' => 'draft',
]);

// Chaque modification cree une version
$v1 = $versionFactory->createNew([
    'versionNumber' => '1.0',
    'timestamp' => date('Y-m-d H:i:s'),
    'changeNote' => 'Initial draft',
]);
DatabaseAdapter::setStorage($v1, 'Full content of version 1...');
$doc->setBrotherEntity('hasVersion', $v1, null);
$v1->setBrotherEntity('editedBy', $user, null);

// Nouvelle version
$v2 = $versionFactory->createNew([
    'versionNumber' => '1.1',
    'timestamp' => date('Y-m-d H:i:s'),
    'changeNote' => 'Fixed typos',
]);
DatabaseAdapter::setStorage($v2, 'Full content of version 1.1...');
$doc->setBrotherEntity('hasVersion', $v2, null);
$v2->setBrotherEntity('editedBy', $user, null);
$v2->setBrotherEntity('supersedes', $v1, null);
```

---

## Patterns d'Utilisation de Sandra

### Pattern 1 : Initialisation standard

```php
// 1. Creer le systeme (une seule fois)
$system = new System(
    env: 'myapp_',      // Prefixe des tables (isolation par app)
    install: true,       // Cree les tables si elles n'existent pas
    dbHost: '127.0.0.1',
    db: 'sandra',
    dbUsername: 'root',
    dbpassword: ''
);

// 2. Definir les factories (les "types" d'entites)
$factory = new EntityFactory(
    'entityType',   // is_a (le type : "planet", "user", "article"...)
    'fileName',     // contained_in (le fichier/collection)
    $system
);

// 3. Creer / Lire / Mettre a jour / Supprimer
```

### Pattern 2 : CRUD basique

```php
// CREATE
$entity = $factory->createNew(['name' => 'value', 'prop' => 'data']);

// READ - par reference
$factory->populateLocal();
$entity = $factory->first('name', 'value');

// READ - get or create
$entity = $factory->getOrCreateFromRef('name', 'Mars');

// UPDATE
$entity->createOrUpdateRef('name', 'updatedValue');
// ou via factory :
$factory->createOrUpdateOnReference('name', 'value', ['prop' => 'newData']);

// DELETE (soft delete via flag)
$entity->delete();

// Pour vraiment lire les entites supprimees :
$factory->setBypassFlag(true);
```

### Pattern 3 : Relations entre entites

```php
// Creer une relation (triplet) entre entites
$entityA->setBrotherEntity('relationVerb', $entityB, ['metadata' => 'value']);

// Lire les relations
$factory->populateBrotherEntities();
$brothers = $entityA->getBrotherEntity('relationVerb');

// Lire une reference sur la relation
$ref = $entityA->getBrotherReference('relationVerb', 'entityBName', 'metadata');

// Factories jointes (pour des requetes efficaces)
$starFactory->joinFactory('illuminates', $planetFactory);
$starFactory->populateLocal();
$starFactory->joinPopulate();
$planets = $star->getJoinedEntities('illuminates');
```

### Pattern 4 : Filtrage

```php
// Trouver les entites ayant une relation specifique
$factory->setFilter('verb', $targetEntity);
$factory->populateLocal();

// Negation : trouver les entites SANS cette relation
$factory->setFilter('verb', $targetEntity, true);

// Filtre generique : entites ayant N'IMPORTE QUELLE relation vers $target
$factory->setFilter(0, $targetEntity);

// Filtre generique : entites ayant le verbe 'verb' vers N'IMPORTE QUI
$factory->setFilter('verb', 0);
```

### Pattern 5 : Serialization / Synchronisation

```php
// Exporter une factory en JSON
$gossiper = new Gossiper($system);
$json = $gossiper->exposeGossip($factory);

// Importer depuis JSON (sur un autre systeme)
$remoteSystem = new System('remote_');
$gossiper = new Gossiper($remoteSystem);
$gossiper->receiveEntityFactory($json);
```

### Pattern 6 : Affichage formate

```php
// Affichage basique
$display = $factory->getDisplay('array');

// Affichage avec selection de champs
$display = $factory->getDisplay('array', ['name', 'email']);

// Affichage avance avec renommage
$advancedDisplay = new AdvancedDisplay();
$advancedDisplay->conceptDisplayProperty('firstName', 'first_name');
$advancedDisplay->conceptDisplayProperty('lastName', 'last_name');
$advancedDisplay->setShowUnid();
$display = $factory->getDisplay('array', null, null, $advancedDisplay);
```

---

## Quand utiliser Sandra ?

### Sandra est ideal pour :

| Use Case | Pourquoi |
|----------|----------|
| **Donnees a schema variable** | Pas besoin d'ALTER TABLE, on ajoute des references a la volee |
| **Relations complexes N:M** | Les triplets modelisent naturellement toute relation |
| **Metadonnees sur les relations** | Les references sur les triplets stockent des donnees sur la relation elle-meme |
| **Integration de sources multiples** | Le ForeignEntityAdapter fusionne des APIs externes |
| **Synchronisation P2P** | Le Gossiper permet l'echange de donnees entre peers |
| **Prototypage rapide** | Pas de migration, pas de schema a definir, on cree et on itere |
| **Catalogues et taxonomies** | Hierarchies naturelles via les triplets |
| **Systemes multi-tenant** | Le prefixe `env` isole completement les tenants |

### Sandra n'est PAS ideal pour :

| Use Case | Pourquoi | Alternative |
|----------|----------|-------------|
| **Donnees tabulaires simples** | Overhead des triplets inutile pour du CRUD basique | MySQL/PostgreSQL classique |
| **Analytics / OLAP** | Pas optimise pour les aggregations massives | ClickHouse, BigQuery |
| **Tres gros volumes (>10M entites)** | Le populateLocal charge en memoire | Neo4j, JanusGraph |
| **Recherche full-text avancee** | Pas d'index full-text natif | Elasticsearch + Sandra |
| **Temps reel / streaming** | Pas de pub/sub ni de changefeed | Redis Streams, Kafka |

---

## Architecture type d'un projet Sandra

```
my-project/
├── config/
│   └── sandra.php           # Configuration DB et systeme
├── src/
│   ├── Factories/
│   │   ├── UserFactory.php   # extends EntityFactory avec logique metier
│   │   ├── ArticleFactory.php
│   │   └── TagFactory.php
│   ├── Services/
│   │   ├── UserService.php   # Logique applicative utilisant les factories
│   │   └── ContentService.php
│   └── Api/
│       └── Controllers/      # Points d'entree API
├── tests/
│   ├── UserFactoryTest.php
│   └── ContentServiceTest.php
└── vendor/
    └── everdreamsoft/sandra/  # Le package Sandra
```

### Exemple de Factory metier

```php
namespace App\Factories;

use SandraCore\EntityFactory;
use SandraCore\System;

class UserFactory extends EntityFactory
{
    public function __construct(System $system)
    {
        parent::__construct('user', 'userFile', $system);
        $this->setGeneratedClass(User::class); // Entites typees
    }

    public function findByEmail(string $email): ?User
    {
        $this->populateLocal();
        return $this->first('email', $email);
    }

    public function createUser(string $name, string $email): User
    {
        return $this->createNew([
            'name' => $name,
            'email' => $email,
            'createdAt' => date('Y-m-d H:i:s'),
            'status' => 'active',
        ]);
    }
}
```
