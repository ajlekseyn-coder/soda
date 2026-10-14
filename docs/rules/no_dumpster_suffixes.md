# no_dumpster_suffixes

📝 Rejects type names ending in generic role suffixes instead of naming what the type does.

💼🚫 This rule is **not** enabled by default. Add it to your `soda.php` config to enable it.

## Why

`Manager`, `Helper`, `Util` and friends name a job category, not a thing. Every such type hides a real noun behind a word that says nothing: an `OrderHelper` is a `Pricer`, an `Exporter`, a `Validator` — the name should say which. Category names also grow into dumping grounds: everything that does not fit elsewhere ends up in the `Helper`. The rule checks class, trait and interface names against the rejected suffixes and reports each match at the declaration line.

## Examples

```php
// ❌
final class OrderHelper {}
trait CacheManager {}
abstract class ImageProcessor {}

// ✅
final class OrderPricer {}
trait CacheWarmer {}
abstract class ImageResizer {}
```

## Options

| Argument | Type | Default | Description |
| --- | --- | --- | --- |
| `$extraSuffixes` | `string[]` | `[]` | Additional rejected suffixes merged with the built-in ones (`Manager`, `Helper`, `Util`, `Utils`, `Processor`, `Service`, `Handler`, `Wrapper`). |

## Configuration

```php
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Naming\NoDumpsterSuffixes;

return Soda::configure()
    ->withPaths(['src/'])
    ->with([
        new NoDumpsterSuffixes(extraSuffixes: ['Evidence', 'Probe']),
    ]);
```

## Related rules

- [boolean_methods_without_prefix](boolean_methods_without_prefix.md) — the method-level counterpart: names must state their role.
- [class_name_length](class_name_length.md) — bounds name length independently of vocabulary.
