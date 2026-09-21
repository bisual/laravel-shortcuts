# Laravel Shortcuts for Software Agencies

[![Latest Version on Packagist](https://img.shields.io/packagist/v/bisual/laravel-shortcuts.svg?style=flat-square)](https://packagist.org/packages/bisual/laravel-shortcuts)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/bisual/laravel-shortcuts/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/bisual/laravel-shortcuts/actions?query=workflow%3ATests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/bisual/laravel-shortcuts/pint.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/bisual/laravel-shortcuts/actions?query=workflow%3APint+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/bisual/laravel-shortcuts.svg?style=flat-square)](https://packagist.org/packages/bisual/laravel-shortcuts)

This is where your description should go. Limit it to a paragraph or two. Consider adding a small example.

## Installation

You can install the package via composer:

```bash
composer require bisual/laravel-shortcuts
```

You can publish and run the migrations with:

```bash
php artisan vendor:publish --tag="laravel-shortcuts-migrations"
php artisan migrate
```

You can publish the config file with:

```bash
php artisan vendor:publish --tag="laravel-shortcuts-config"
```

This is the contents of the published config file:

```php
return [
];
```

Optionally, you can publish the views using

```bash
php artisan vendor:publish --tag="laravel-shortcuts-views"
```

## Artisan generators

The package registers generators for repositories, DTOs, form requests, MCP tools, and a resource bundle. Generated PHP lives under the usual Laravel `App\` namespaces. Controllers go to `App\Http\Controllers\API`.

### `make:repository`

Creates a class in `app/Repositories`. The `Repository` suffix is added if you omit it.

```bash
php artisan make:repository Post
php artisan make:repository Post --model=Post
```

Without `--model`, you get an empty `final` class. With `--model=Post` (or `App\Models\Post`), the class extends `CrudRepository`, sets `$model`, and includes `@extends CrudRepository<Post>`.

### `make:dto`

Creates a `final readonly` class in `app/DTOs`. The name is normalized to a `DTO` suffix (`RegisterUser` and `RegisterUserDTO` both become `RegisterUserDTO`).

```bash
php artisan make:dto RegisterUser
```

### `make:request-dto`

Creates a matching DTO and a form request in `app/Http/Requests`. `RegisterUser` yields `RegisterUserDTO` plus `RegisterUserRequest` with empty `rules()`, `authorize(): true`, and a `dto()` method.

```bash
php artisan make:request-dto RegisterUser
```

### `make:mcp-crud-resource`

Requires [`laravel/mcp`](https://github.com/laravel/mcp). Creates `app/Mcp/Cruds/{Name}CrudMcp.php` extending `CrudMcpResource`.

```bash
php artisan make:mcp-crud-resource Post
php artisan make:mcp-crud-resource Post --model=Post --repository=PostRepository
```

### `make:mcp-tool`

Requires `laravel/mcp`. Creates a standalone custom tool in `app/Mcp/Tools`.

```bash
php artisan make:mcp-tool ResolveTaskByCode --description="Resolve a product task by code or git branch"
```

### `make:bisual-resource`

Scaffolds an Eloquent model and optional companions. The name is the model (`Post`). If the model already exists, the command fails.

```bash
php artisan make:bisual-resource Post -crfspd
php artisan make:bisual-resource Post -cr --mcp
```

If you omit the name or flags, Laravel Prompts asks for the model name and which components to generate.

| Flag | Long name | Generates |
| --- | --- | --- |
| `-c` | `--controller` | `App\Http\Controllers\API\PostController` extending `CrudController` |
| `-r` | `--repository` | `PostRepository` bound to `Post` |
| `-f` | `--factory` | Laravel factory |
| `-s` | `--seeder` | Laravel seeder |
| `-p` | `--policy` | Laravel policy for the model |
| `-d` | `--dto` | `StorePostDTO` and `UpdatePostDTO` |
| `-m` | `--migration` | Laravel migration |
| | `--mcp` | `App\Mcp\Cruds\PostCrudMcp` |
| `-a` | `--all` | All of the above (including `--mcp`) |

Factory, seeder, policy, migration, and the model itself are delegated to Laravel's `make:*` commands.

When `-c` and `-d` are used together, the command also runs `make:request-dto` for `StorePost` and `UpdatePost`, and the controller wires:

```php
public static $storeRequestClass = StorePostRequest::class;
public static $updateRequestClass = UpdatePostRequest::class;
```

With `-c` and no `-d`, those properties are empty arrays (a minimal CRUD controller). Routes are not registered automatically.

## MCP CRUD (`CrudMcpResource`)

Optional integration with [Laravel MCP](https://github.com/laravel/mcp). Install it in the app:

```bash
composer require laravel/mcp
```

Declare a resource the same way you declare a `CrudController`, then spread `::tools()` into your MCP server:

```php
namespace App\Mcp\Cruds;

use App\Models\CompanyProduct;
use App\Repositories\CompanyProductRepository;
use Bisual\LaravelShortcuts\Mcp\CrudMcpResource;
use Illuminate\Contracts\Auth\Authenticatable;

final class CompanyProductCrudMcp extends CrudMcpResource
{
    public static string $model = CompanyProduct::class;
    public static string $repository = CompanyProductRepository::class;

    public static ?array $only = ['index', 'show']; // which tools to register

    public static array $abilities = [
        'index' => 'index', // Policy uses index instead of viewAny
    ];

    public static function prepareIndexParams(array &$params, Authenticatable $user): void
    {
        // e.g. force a scope
    }
}
```

```php
use App\Mcp\Cruds\CompanyProductCrudMcp;
use App\Mcp\Cruds\ProductTaskCrudMcp;
use Laravel\Mcp\Server;

final class BisualServer extends Server
{
    protected function boot(): void
    {
        $this->tools = [
            ...CompanyProductCrudMcp::tools(),
            ...ProductTaskCrudMcp::tools(),
        ];
    }
}
```

| Property | Role |
| --- | --- |
| `$model` / `$repository` | Same as `CrudController` |
| `$only` / `$except` | Which actions become MCP tools (`index`, `show`, `store`, `update`, `destroy`) |
| `$authorize` | Whether each action calls the model Policy |
| `$abilities` | Map action → Gate ability (defaults: `viewAny`, `view`, `create`, `update`, `delete`) |
| `$indexQueryValidations` | Extra index rules (merged with the CrudRepository catalog) |
| `$storeRequestClass` / `$updateRequestClass` | Array rules or FormRequest class |
| `$descriptions` | Optional tool description overrides per action |
| `$extraTools` | Extra standalone tool classes mixed into `::tools()` |
| `prepareIndexParams` / `prepareStoreData` / `prepareUpdateData` | Hooks before the repository call |

Tool input schemas are built from `CrudRepository::parameterDefinitions()` (the reserved params `index` / `show` already understand), so LLMs see the same dialect as the HTTP API: `with`, `order_by`, `page`, `per_page`, `limit`, `scopes`, `search`, `append`, `without`, `select`, plus `id` for show/update/destroy. Additional column filters can still be passed through on index (same as the repository).

## Custom query params usage

You can build different formats of query params to handle sort, select and with in different depths of your query.

#### -- WITH --

To indicate depth within your query param 'with' you should use this format.

```bash
?with=relation..relation2..relation3
```
The '..' character indicates one level deeper.

#### -- ORDER BY --

You can simply indicate the field to order by entering it in your query as you have done all your life.

```bash
?order_by=created_at
```

But you can also choose which fields of your relations to order and in which depth to do it, as well as indicate 'order_by_direction' in the same string.

```bash
?order_by=relation..relation2..relation3.created_at:desc
```

_If you do not indicate your 'order_by_direction' with ':' next to the field to sort by, it will sort in 'asc' direction by default_.

#### -- SELECT --

You can indicate a single field of your main table to get only that information (you don't need to add the id).

```bash
?select=name
```

And once again, you can choose what information about your relationship you receive at the same time. Different fields of the same relationship level will be separated by '|'.

```bash
?select=relation..relation2..relation3.name|description
```

#### -- FILTER BY RELATION ATTRIBUTE --

You can filter parent rows by an attribute of a related model. The same value rules as for normal attribute filters apply (`null`, `notnull`, enums, comma-separated lists, booleans, numeric equality, or `LIKE` for strings).

```bash
# HTTP query string — use "-" (PHP turns "." into "_" in query keys)
?records-is_archived=false

# PHP / tinker / arrays — use "."
YourRepository::index(params: [
    'author.company_id' => 1,
]);
```

- `.` — programmatic params (`author.name`, `records.is_archived`)
- `-` — HTTP query keys (`author-name`, `records-is_archived`)

BelongsTo / HasMany / similar relations use `whereHas`. MorphTo relations use `whereHasMorph` and only query morph types that actually have that column.

When you also pass `with=relation` and use the `.` form (`relation.attribute=value`), the constraint is applied both to parent existence and to the eager-loaded relation. The `-` form still filters parents, but does not constrain the eager load.

```bash
?with=author&author.company_id=1
```

#### ⚙️ Generalities

In all cases, to separate different relationships, regardless of the depth level, they must be separated by a ','.

```bash
?with=users,relation..relation2
?order_by=users.name,relation..relation2.created_at:desc
?select=users.name,relation..relation2.title|description|created_at
```

**NOTE**: The query param 'order_by_direction' is not necessary when using laravel-shortcuts since it is applied directly in 'order_by', using it could cause errors.

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
