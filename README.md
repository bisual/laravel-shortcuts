# Laravel Shortcuts for Software Agencies

[![Latest Version on Packagist](https://img.shields.io/packagist/v/bisual/laravel-shortcuts.svg?style=flat-square)](https://packagist.org/packages/bisual/laravel-shortcuts)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/bisual/laravel-shortcuts/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/bisual/laravel-shortcuts/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/bisual/laravel-shortcuts/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/bisual/laravel-shortcuts/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
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

The package registers generators for repositories, DTOs, form requests, and a resource bundle. Generated PHP lives under the usual Laravel `App\` namespaces. Controllers go to `App\Http\Controllers\API`.

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

### `make:bisual-resource`

Scaffolds an Eloquent model and optional companions. The name is the model (`Post`). If the model already exists, the command fails.

```bash
php artisan make:bisual-resource Post -crfspd
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
| `-a` | `--all` | All of the above |

Factory, seeder, policy, migration, and the model itself are delegated to Laravel's `make:*` commands.

When `-c` and `-d` are used together, the command also runs `make:request-dto` for `StorePost` and `UpdatePost`, and the controller wires:

```php
public static $storeRequestClass = StorePostRequest::class;
public static $updateRequestClass = UpdatePostRequest::class;
```

With `-c` and no `-d`, those properties are empty arrays (a minimal CRUD controller). Routes are not registered automatically.

## Custom query params usage

You can build different formats of query params to handle sort, select, with and where in different depths of your query.

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

#### -- WHERE --

Filter results with a structured condition string. Basic shape:

```bash
?where=field[operator]<{value}>
```

Values must be wrapped in `<{ }>`. Relation depth uses `..`, same as `with`. The column is the last segment after `.`.

```bash
# root table
?where=status[=]<{active}>

# nested relation
?where=users..profiles.role[=]<{manager}>
```

##### Operators

| Operator | Example |
|---|---|
| `=` `!=` `>` `<` `>=` `<=` | `age[>=]<{18}>` |
| `like` / `notLike` | `name[like]<{deadlock%}>` |
| `in` / `notIn` | `slug[in]<{draft\|published}>` |
| `null` / `notNull` | `deleted_at[null]<>` |
| `between` / `notBetween` | `id[between]<{10\|20}>` |
| `date,<op>` | `created_at[date,>=]<{2024-01-01}>` |

Multiple values for `in`, `notIn`, `between` and `notBetween` are separated by `|` inside `<{ }>`.

##### Relation filter mode (`::parent` \| `::child` \| `::both`)

When the condition targets a relation, append a relation filter mode (default is `parent`):

```bash
# parent (default): keep parents that match via whereHas
?where=comments.content[like]<{deadlock%}>
?where=comments.content[like]<{deadlock%}>::parent

# child: do not filter parents; only constrain eager-loaded children (use with)
?where=comments.content[like]<{deadlock%}>::child&with=comments

# both: whereHas + constrained eager load
?where=comments.content[like]<{deadlock%}>::both&with=comments
```

##### Grouping

| Separator | Meaning |
|---|---|
| `,` | AND between condition blocks |
| `\|\|` | OR inside a block |
| `&&` | AND inside a block (also without OR) |

```bash
# AND (comma)
?where=status[=]<{active}>,type[=]<{task}>

# AND without OR
?where=slug[=]<{undefined}>&&name[like]<{%contract%}>

# OR
?where=status[=]<{draft}>||status[=]<{published}>

# OR across relations (group-level ::parent applies to both unless overridden)
?where=children.name[=]<{Flutter}>||children.name[=]<{Next Lives}>::parent

# per-condition relation filter mode
?where=comments.content[like]<{deadlock%}>::parent||tags.name[=]<{urgent}>::child
```

Separators are ignored when they appear inside `<{ }>` or `[ ]`, so values and operators may contain `,`, `||`, `&&` or `::` safely (e.g. `created_at[date,>=]<{2024-01-01}>`).

#### -- FILTER BY RELATION ATTRIBUTE --

For simple equality-style filters without the `where` DSL, you can still pass related attributes as normal params. The same value rules as for root attributes apply (`null`, `notnull`, enums, comma-separated lists, booleans, numeric equality, or `LIKE` for strings).

```bash
# HTTP query string — use "-" (PHP turns "." into "_" in query keys)
?records-is_archived=false

# PHP / tinker / arrays — use "."
YourRepository::index(params: [
    'author.company_id' => 1,
]);
```

- `.` — programmatic params (`author.name`, `records.is_archived`); with `with=relation`, also constrains the eager load
- `-` — HTTP query keys (`author-name`, `records-is_archived`); filters parents only

BelongsTo / HasMany / similar relations use `whereHas`. MorphTo uses `whereHasMorph` and only queries morph types that have that column.

For operators, nesting, OR/AND grouping, or explicit `::parent` / `::child` / `::both` control, prefer the `where` param above.

#### ⚙️ Generalities

In all cases, to separate different relationships, regardless of the depth level, they must be separated by a ','.

```bash
?with=users,relation..relation2
?order_by=users.name,relation..relation2.created_at:desc
?select=users.name,relation..relation2.title|description|created_at
?where=status[=]<{active}>,users..profiles.role[=]<{manager}>::parent
```

**NOTE**: The query param 'order_by_direction' is not necessary when using laravel-shortcuts since it is applied directly in 'order_by', using it could cause errors.

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
