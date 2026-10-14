# no_static_only_class

📝 Reports classes whose declared surface is entirely static.

💼🚫 This rule is not enabled by default. Add it to your `soda.php` config to enable it.

## Why

A class that offers only static members is a namespace in disguise: callers cannot inject it, mock it, or substitute it behind a contract. The declaration also hides what the operations read and write, because static state survives every call. Name the operations as free functions or constants when they are pure, or keep real instance state on the class and let callers depend on the type.

The rule requires at least one method and no instance properties, so constant holders stay accepted. Classes that extend a parent or consume traits are out of scope: their inherited or mixed-in surface is unknown without project facts. Abstract classes, interfaces, and enums are also outside the comparison.

## Examples

```php
// ❌ a namespace pretending to be a class
final class InvoiceRates
{
    public static function usd(): int
    {
        return 1;
    }

    private static function eur(): int
    {
        return 2;
    }
}

// ✅ the operations keep one owner of state
final class InvoiceRates
{
    public function __construct(private readonly Currency $base) {}

    public function usd(): int
    {
        return $this->base->scale();
    }
}
```

```php
// ✅ constants alone are accepted
final class InvoiceRates
{
    public const USD = 1;
    public const EUR = 2;
}
```

## Configuration

```php
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Structure\NoStaticOnlyClass;

return Soda::configure()
    ->withPaths(['src/'])
    ->with([
        new NoStaticOnlyClass(),
    ]);
```

## Related rules

- [no_singleton_access](no_singleton_access.md) — flags the static accessor calls this class invites.
- [no_static_mutable_properties](no_static_mutable_properties.md) — forbids the shared state such classes tend to carry.
- [no_trivial_factories](no_trivial_factories.md) — reports static construction helpers that only forward their arguments.
