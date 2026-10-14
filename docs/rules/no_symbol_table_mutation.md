# no_symbol_table_mutation

📝 Map named input fields explicitly instead of manufacturing local variables with `extract`.

💼 This rule is enabled in the ✅ `standard` config.

## Why

`extract` turns array keys into local variables in one shot: readers cannot see which names exist, typos become silent fallbacks, and colliding keys overwrite existing variables — including properties of the surrounding scope. The rule reports every call to `extract` and advises reading the named fields explicitly or mapping the input into a typed value object.

## Examples

```php
// ❌ Data flow hides behind manufactured variables
final class ContextHydrationManager
{
    public function hydrate(string $value): string
    {
        $context = [];
        $context['tenant'] = $value;
        extract($context);

        return $tenant;
    }
}
```

```php
// ✅ Named fields, visible data flow
final class ContextHydrationManager
{
    public function hydrate(array $context): string
    {
        return $context['tenant'];
    }
}
```

## Configuration

```php
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Architecture\NoSymbolTableMutation;

return Soda::configure()
    ->withPaths(['src/'])
    ->with([
        new NoSymbolTableMutation(),
    ]);
```

## Related rules

- [no_dynamic_properties](no_dynamic_properties.md) — flags undeclared property state, the object-level variant of shapeless data.
- [no_native_deserialization](no_native_deserialization.md) — requires decoding untrusted input into explicitly constructed types.
