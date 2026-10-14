# max_interfaces_per_class

📝 Limits the number of interfaces a single class implements.

💼 This rule is enabled in the ✅ `standard` config.

## Why

A class that implements many interfaces promises unrelated roles to unrelated clients; every new contract constrains its future changes and makes the class the default dumping ground for behavior that belongs elsewhere. The rule counts the interfaces in each class `implements` clause and reports classes above the limit. Split unrelated roles into focused objects rather than making one class satisfy every contract.

## Examples

```php
// ❌ six contracts satisfied by one class
final class OrderProcessor implements
    Processable,
    Serializable,
    Loggable,
    Cacheable,
    Retryable,
    Auditable
{
    // ...
}
```

```php
// ✅ each role belongs to a focused object
final class OrderProcessor implements Processable
{
    // ...
}

final class OrderAuditTrail implements Auditable
{
    // ...
}
```

## Options

- `limit` (default `5`): maximum accepted number of implemented interfaces per class. A non-positive limit disables the check.

## Configuration

```php
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Structure\MaxInterfacesPerClass;

return Soda::configure()
    ->withPaths(['src/'])
    ->with([
        new MaxInterfacesPerClass(5),
    ]);
```

## Related rules

- [max_traits_per_class](max_traits_per_class.md) — bounds the other way of stacking capabilities onto one class.
- [max_public_methods](max_public_methods.md) — many implemented contracts usually mean many public methods.
- [no_empty_local_interfaces](no_empty_local_interfaces.md) — flags local interfaces that add contracts without adding content.

