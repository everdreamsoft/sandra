# sandra

<p align="center">
<img src="resources/images/SandraBanner.png">


</p>



## Getting Started

Using composer

    composer require evedreamsoft/sandra
    
    
To instantiate your datagraph

    $sandra = new \SandraCore\System('myFirstDatagraph,
    true,
    'your_DB_HOST',
    'your_DB_name',
    'your_DB_username',
    'your_db_password');
    
 For example
 
    $sandra = new \SandraCore\System('myFirstDatagraph,'true,'127.0.0.1','sandra','root','');

### Writing data

#### Initialization

    $sandra = new System('AnimalShelter',true);
    $catFactory = new EntityFactory('cat','catFile',$sandra);
    
We create 3 cats
    
    $felixEntity = $catFactory->createNew(['name' => 'Felix',
        'birthYear' => 2012]);
    
    $smokeyEntity = $catFactory->createNew(['name' => 'Smokey',
        'birthYear' => 2015]);
    
  
    $missyEntity = $catFactory->createNew(['name' => 'Missy',
        'birthYear' => 2015,
        'handicap' => 'blind'
        ]);
        
 Each cat has name reference and birthYear the last cat  Missy has additional "handicap" reference
 
### Reading data
 
     $catFactoryForRead = new EntityFactory('cat','AnimalFile',$sandra);
     
     //The factory is empty we need to load the 3 cats into memory
     
     $catFactoryForRead->populateLocal(1000); //we read a limit of 1000 cats
     $catEntityArray = $catFactoryForRead->getEntities();
     foreach ($catEntityArray as $cat){
        
         echo $cat->get('name')."\n";
        
     }
returns :

Felix

Smokey

Missy





