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

##### Filter type (`::parent` \| `::child` \| `::both`)

When the condition targets a relation, append a filter type (default is `parent`):

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

# per-condition filter type
?where=comments.content[like]<{deadlock%}>::parent||tags.name[=]<{urgent}>::child
```

Separators are ignored when they appear inside `<{ }>`, so values may contain `,`, `||`, `&&` or `::` safely.

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
