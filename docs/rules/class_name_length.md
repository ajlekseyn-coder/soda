# class_name_length

📝 Keeps declared class, interface, trait, and enum names within inclusive length bounds.

💼 This rule is enabled in the ✅ `standard` config.

## Why

A very long class name usually signals a class that owns several responsibilities and tries to state them all in one identifier, while a one- or two-character name is too cryptic to carry domain meaning. Bounding the length in both directions pushes every type toward one clear domain role. The rule measures the byte length of each declared class-like name and reports names below the minimum or above the maximum.

## Examples

```php
// ❌
final class VeryLongClassNameThatDescribesManyResponsibilities {}

// ✅
final class Invoice {}
```

```php
// ❌ Too cryptic to carry meaning.
final class A {}

// ✅
final class Account {}
```

## Options

| Argument | Type | Default | Description |
| --- | --- | --- | --- |
| `$min` | `int` | `3` | Minimum accepted identifier length, inclusive. |
| `$max` | `int` | `32` | Maximum accepted identifier length, inclusive. |

## Configuration

```php
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Naming\ClassNameLength;

return Soda::configure()
    ->withPaths(['src/'])
    ->with([
        new ClassNameLength(min: 3, max: 32),
    ]);
```

## Related rules

- [namespace_name_length](namespace_name_length.md) — applies the same bounds to each namespace segment.
- [method_name_length](method_name_length.md) — applies the same idea to method identifiers.
