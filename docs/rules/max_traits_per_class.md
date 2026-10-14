# max_traits_per_class

📝 Limits the number of traits a single class composes.

💼 This rule is enabled in the ✅ `standard` config.

## Why

Each trait a class uses silently contributes state expectations and methods to the class, so a class composed of many traits has an API and invariants no single file describes. The rule counts the traits directly imported by each class declaration and reports classes above the limit. Review the state and methods each trait expects; keep cohesive concerns on their owner, even with one consumer, and avoid extraction solely to reduce this count.

## Examples

```php
// ❌ eleven traits composed into one class
final class OrderProcessor
{
    use HandlesValidation;
    use HandlesPricing;
    use HandlesDiscounts;
    use HandlesTaxes;
    use HandlesShipping;
    use HandlesBilling;
    use HandlesRefunds;
    use HandlesNotifications;
    use HandlesAuditing;
    use HandlesCaching;
    use HandlesSerialization; // 11 traits total

    public function process(Order $order): void {}
}
```

```php
// ✅ cohesive concerns stay on their owner
final class OrderProcessor
{
    use HandlesPricing;

    public function process(Order $order): void {}
}
```

## Options

- `limit` (default `10`): maximum accepted number of traits imported by a class declaration. A non-positive limit disables the check.

## Configuration

```php
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Structure\MaxTraitsPerClass;

return Soda::configure()
    ->withPaths(['src/'])
    ->with([
        new MaxTraitsPerClass(10),
    ]);
```

## Related rules

- [max_effective_methods_per_class](max_effective_methods_per_class.md) — counts the method surface that trait composition creates.
- [max_effective_class_length](max_effective_class_length.md) — counts the source that trait composition adds to a class.
- [max_interfaces_per_class](max_interfaces_per_class.md) — bounds the other kind of contract stacking on one class.

