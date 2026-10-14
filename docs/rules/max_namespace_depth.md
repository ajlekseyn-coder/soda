# max_namespace_depth

📝 Limits the number of segments in the namespace enclosing a class.

💼 This rule is enabled in the ✅ `standard` config.

## Why

Deeply nested namespaces mirror the whole directory tree instead of a domain vocabulary, so every import grows long and readers must reconstruct where a type lives from a dozen path segments. The rule counts the segments of the namespace each class or trait is declared in (the class name itself is not counted) and reports declarations whose namespace depth exceeds the limit. Flatten the namespace around a clear domain capability; avoid encoding the entire directory tree.

## Examples

```php
// ❌ five namespace segments before the type name
namespace App\Infrastructure\Persistence\Doctrine\Order\Projections;

final class OrderProjection {}
```

```php
// ✅ the namespace names a capability, not a directory path
namespace App\Order;

final class OrderProjection {}
```

## Options

- `limit` (default `4`): maximum accepted number of namespace segments per class or trait declaration. A non-positive limit disables the check.

## Configuration

```php
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Structure\MaxNamespaceDepth;

return Soda::configure()
    ->withPaths(['src/'])
    ->with([
        new MaxNamespaceDepth(4),
    ]);
```

## Related rules

- [max_classes_per_namespace](max_classes_per_namespace.md) — bounds how many types share one namespace.
- [namespace_name_length](namespace_name_length.md) — bounds the length of the namespace name itself.
- [max_layer_dominance_percentage](max_layer_dominance_percentage.md) — checks the architectural role mix of a directory.

