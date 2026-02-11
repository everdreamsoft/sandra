
# Sandra REST API - Step-by-Step Guide

How to expose your Sandra entities as a REST API.

---

## Overview

Sandra's API layer is **framework-agnostic**. It takes a simple request object and returns a response object. You wire it into whatever HTTP layer you use (vanilla PHP, Slim, Laravel, Symfony, etc.).

**Three classes:**

| Class | Role |
|-------|------|
| `ApiHandler` | Registers factories, routes requests to CRUD operations |
| `ApiRequest` | Wraps an incoming HTTP request (method, path, query, body) |
| `ApiResponse` | Wraps the outgoing response (status, data, error, JSON) |

---

## Step 1: Create Your System and Factories

```php
<?php
require_once 'vendor/autoload.php';

use SandraCore\System;
use SandraCore\EntityFactory;

// Connect to your database
$system = new System('myapp', true, '127.0.0.1', 'my_database', 'root', 'password');

// Define your entity factories
$products = new EntityFactory('product', 'productsFile', $system);
$customers = new EntityFactory('customer', 'customersFile', $system);
```

---

## Step 2: Set Up the API Handler

```php
use SandraCore\Api\ApiHandler;

$api = new ApiHandler($system);
```

---

## Step 3: Register Your Factories as Resources

Each `register()` call creates a full set of REST routes for that resource.

```php
$api->register('products', $products, [
    'read'       => true,         // GET endpoints
    'create'     => true,         // POST endpoint
    'update'     => true,         // PUT endpoint
    'delete'     => true,         // DELETE endpoint
    'searchable' => ['name', 'category'],  // fields for ?search= queries
]);

$api->register('customers', $customers, [
    'read'       => true,
    'create'     => true,
    'update'     => true,
    'delete'     => false,        // disable delete
    'searchable' => ['name', 'email'],
]);
```

**Generated routes per resource:**

| Method | Path | Action |
|--------|------|--------|
| `GET` | `/{name}` | List all (paginated) |
| `GET` | `/{name}/{id}` | Get one by concept ID |
| `GET` | `/{name}?search=term` | Search (if `searchable` is set) |
| `POST` | `/{name}` | Create new entity |
| `PUT` | `/{name}/{id}` | Update entity |
| `DELETE` | `/{name}/{id}` | Soft-delete entity |

---

## Step 4: Handle Incoming Requests

Create an `ApiRequest` from your HTTP input and pass it to `handle()`.

```php
use SandraCore\Api\ApiRequest;

// Build the request from PHP globals
$method = $_SERVER['REQUEST_METHOD'];
$path   = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$query  = $_GET;
$body   = json_decode(file_get_contents('php://input'), true) ?? [];

$request  = new ApiRequest($method, $path, $query, $body);
$response = $api->handle($request);
```

---

## Step 5: Send the Response

```php
http_response_code($response->getStatus());
header('Content-Type: application/json');
echo $response->toJson();
```

---

## Complete Minimal Example (Vanilla PHP)

Save this as `api.php` and point your web server to it:

```php
<?php
require_once 'vendor/autoload.php';

use SandraCore\System;
use SandraCore\EntityFactory;
use SandraCore\Api\ApiHandler;
use SandraCore\Api\ApiRequest;

// --- Setup ---
$system   = new System('myapp', true, '127.0.0.1', 'my_database', 'root', '');
$products = new EntityFactory('product', 'productsFile', $system);
$products->populateLocal();

// --- API ---
$api = new ApiHandler($system);
$api->register('products', $products, [
    'searchable' => ['name', 'category'],
]);

// --- Handle request ---
$request = new ApiRequest(
    $_SERVER['REQUEST_METHOD'],
    parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH),
    $_GET,
    json_decode(file_get_contents('php://input'), true) ?? []
);

$response = $api->handle($request);

// --- Send response ---
http_response_code($response->getStatus());
header('Content-Type: application/json');
echo $response->toJson();
```

---

## API Calls in Practice

### List all products

```
GET /products
```

Response (200):
```json
{
    "data": {
        "items": [
            { "id": 42, "refs": { "name": "Widget", "category": "Tools", "price": "9.99" } },
            { "id": 43, "refs": { "name": "Gadget", "category": "Electronics", "price": "24.99" } }
        ],
        "total": 2,
        "limit": 50,
        "offset": 0
    },
    "status": 200
}
```

### List with pagination

```
GET /products?limit=10&offset=20
```

### Get one product

```
GET /products/42
```

Response (200):
```json
{
    "data": { "id": 42, "refs": { "name": "Widget", "category": "Tools", "price": "9.99" } },
    "status": 200
}
```

### Search

```
GET /products?search=widget
```

Only searches fields listed in the `searchable` option. Case-insensitive, supports partial matches.

### Create a product

```
POST /products
Content-Type: application/json

{ "name": "New Product", "category": "Tools", "price": "14.99" }
```

Response (201):
```json
{
    "data": { "id": 55, "refs": { "name": "New Product", "category": "Tools", "price": "14.99" } },
    "status": 201
}
```

### Update a product

```
PUT /products/42
Content-Type: application/json

{ "price": "12.99" }
```

Only the fields you send are updated. Other fields remain unchanged.

### Delete a product

```
DELETE /products/42
```

Response (200):
```json
{
    "data": { "deleted": true, "id": 42 },
    "status": 200
}
```

---

## Adding Validation

Attach validation rules to the factory **before** registering it. The API will automatically return `422` on validation failure.

```php
$products->setValidation([
    'name'     => ['required', 'string', 'maxlength:100'],
    'price'    => ['required', 'numeric'],
    'category' => ['required'],
]);

$api->register('products', $products);
```

A failed POST:
```
POST /products
{ "price": "not_a_number" }
```

Response (422):
```json
{
    "error": "Field 'name' is required",
    "data": {
        "errors": {
            "name": ["required"],
            "price": ["numeric"]
        }
    },
    "status": 422
}
```

---

## Read-Only Resources

Disable write operations to expose data without allowing modifications:

```php
$api->register('reports', $reportsFactory, [
    'read'   => true,
    'create' => false,
    'update' => false,
    'delete' => false,
]);
```

POST, PUT, and DELETE will return `405 Method Not Allowed`.

---

## Multiple Resources

Register as many factories as you need. Each gets its own route namespace.

```php
$api->register('products',  $productsFactory);
$api->register('customers', $customersFactory);
$api->register('orders',    $ordersFactory, ['delete' => false]);
```

```
GET /products    -> lists products
GET /customers   -> lists customers
GET /orders      -> lists orders
DELETE /orders/1 -> 405 (disabled)
```

---

## HTTP Status Codes

| Code | Meaning |
|------|---------|
| `200` | Success (GET, PUT, DELETE) |
| `201` | Created (POST) |
| `404` | Resource or entity not found |
| `405` | Method not allowed (disabled operation or unknown method) |
| `422` | Validation error or missing data |

---

## Response Object API

```php
$response->getStatus();   // int: 200, 201, 404, 405, 422
$response->getData();     // array: response payload
$response->getError();    // ?string: error message (null on success)
$response->toJson();      // string: full JSON response
$response->isSuccess();   // bool: true if status is 2xx
```

---

## Integration with Frameworks

### Slim 4

```php
$app->any('/{path:.*}', function ($request, $response) use ($api) {
    $body = json_decode((string)$request->getBody(), true) ?? [];
    $apiRequest = new ApiRequest(
        $request->getMethod(),
        $request->getUri()->getPath(),
        $request->getQueryParams(),
        $body
    );

    $apiResponse = $api->handle($apiRequest);

    $response->getBody()->write($apiResponse->toJson());
    return $response
        ->withHeader('Content-Type', 'application/json')
        ->withStatus($apiResponse->getStatus());
});
```

### Laravel

```php
Route::any('api/{path}', function (Request $request, string $path) use ($api) {
    $apiRequest = new ApiRequest(
        $request->method(),
        $path,
        $request->query(),
        $request->json()->all()
    );

    $apiResponse = $api->handle($apiRequest);

    return response()->json(
        json_decode($apiResponse->toJson(), true),
        $apiResponse->getStatus()
    );
})->where('path', '.*');
```

---

## Entity Serialization Format

Every entity is serialized as:

```json
{
    "id": 42,
    "refs": {
        "name": "value",
        "category": "value"
    }
}
```

- `id` is the entity's concept ID (integer)
- `refs` contains all references except `creationTimestamp`
- Reference keys are the shortnames defined in your Sandra system

---

## Brother Entities (Graph Relationships)

Sandra entities can have **brother relationships** — graph links in the form of subject-verb-target triplets with optional references. The API supports exposing these relationships via an opt-in `brothers` option.

### Enabling Brothers on a Resource

Specify which verb relationships to expose when registering a factory:

```php
$api->register('rockets', $rocketFactory, [
    'brothers' => ['hasStage', 'fuelType'],  // verbs to expose
]);
```

If `brothers` is empty or absent, behavior is unchanged (no brothers loaded or serialized).

### GET — Reading Brothers

When brothers are enabled, GET responses include a `brothers` key grouped by verb:

```
GET /rockets/42
```

Response (200):
```json
{
    "data": {
        "id": 42,
        "refs": { "name": "Saturn V" },
        "brothers": {
            "hasStage": [
                { "target": "S-IC", "targetConceptId": 100, "refs": { "manufacturer": "Boeing" } },
                { "target": "S-II", "targetConceptId": 101, "refs": { "manufacturer": "North American" } }
            ],
            "fuelType": []
        }
    },
    "status": 200
}
```

Each brother entry contains:
- `target` — shortname of the target concept
- `targetConceptId` — integer concept ID of the target
- `refs` — references attached to the brother link (excluding `creationTimestamp`)

Brothers are also included in list responses (`GET /rockets`), on each item.

### POST — Creating with Brothers

Include a `brothers` key in the POST body to create brother links alongside the entity:

```
POST /rockets
Content-Type: application/json

{
    "name": "Saturn V",
    "brothers": {
        "hasStage": [
            { "target": "S-IC", "refs": { "manufacturer": "Boeing" } },
            { "target": "S-II", "refs": { "manufacturer": "North American" } }
        ]
    }
}
```

Only verbs listed in the `brothers` option are accepted. Others are silently ignored.

### PUT — Adding Brothers on Update

Include a `brothers` key in the PUT body to add new brother links:

```
PUT /rockets/42
Content-Type: application/json

{
    "thrust": "7891000",
    "brothers": {
        "hasStage": [
            { "target": "S-IVB", "refs": { "manufacturer": "Douglas" } }
        ]
    }
}
```

New brothers are added alongside existing ones (does not replace).

---

## Joined Entities (Cross-Factory Links)

Sandra entities can link to full entities in another `EntityFactory` via **joined relationships**. Unlike brothers (which target a concept shortname), joined entities reference real entities in a separate factory. The API supports exposing these relationships via an opt-in `joined` option.

### Enabling Joined Entities on a Resource

Specify which verb relationships to expose and their target factories when registering:

```php
$planetFactory = new EntityFactory('planet', 'planetsFile', $system);
$planetFactory->populateLocal();

$constellationFactory = new EntityFactory('constellation', 'constellationsFile', $system);
$constellationFactory->populateLocal();

$api->register('stars', $starFactory, [
    'joined' => [
        'illuminePlanet' => $planetFactory,
        'belongToConstellation' => $constellationFactory,
    ],
]);
```

During `register()`, `joinFactory()` is called automatically for each verb/factory pair. If `joined` is empty or absent, behavior is unchanged.

### GET — Reading Joined Entities

When joined is enabled, GET responses include a `joined` key grouped by verb. Each entry has `id` (concept ID) and `refs` (entity references):

```
GET /stars/42
```

Response (200):
```json
{
    "data": {
        "id": 42,
        "refs": { "name": "YZ Ceti" },
        "joined": {
            "illuminePlanet": [
                { "id": 100, "refs": { "name": "YZ Ceti b", "Mass[Em]": "0.75" } },
                { "id": 101, "refs": { "name": "YZ Ceti c", "Mass[Em]": "0.04" } }
            ],
            "belongToConstellation": [
                { "id": 200, "refs": { "name": "Cetus" } }
            ]
        }
    },
    "status": 200
}
```

Joined entities are also included in list responses (`GET /stars`), on each item.

### POST — Creating with Joined Entities

Include a `joined` key in the POST body with arrays of concept IDs from the target factory:

```
POST /stars
Content-Type: application/json

{
    "name": "YZ Ceti",
    "joined": {
        "illuminePlanet": [100, 101]
    }
}
```

Values are arrays of concept IDs. Entities must already exist in the joined factory. Unknown IDs are silently skipped. Only verbs listed in the `joined` option are accepted.

### PUT — Adding Joined Entities on Update

Include a `joined` key in the PUT body to add new joined links:

```
PUT /stars/42
Content-Type: application/json

{
    "luminosity": "0.0019",
    "joined": {
        "illuminePlanet": [102]
    }
}
```

New joined links are added alongside existing ones (does not replace).
