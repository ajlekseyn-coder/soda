# no_reflection

📝 Replace reflective construction with explicit factories, mapping, or framework conventions.

💼 This rule is enabled in the ✅ `standard` config.

## Why

Constructing objects through `ReflectionClass` (and the rest of the `Reflection*` API) hides both construction and contracts: the instantiated type is only known at runtime, constructor requirements are bypassed silently, and refactoring tools cannot follow the dependency. The rule reports every `new` expression whose class name starts with `Reflection` (case-insensitive, so `new \ReflectionClass(...)`, `new ReflectionMethod(...)`, and similar are all covered) and advises explicit factories, mapping, or framework conventions instead.

## Examples

```php
// ❌ The constructed type and its contract are invisible until runtime
final class MetadataHydrator
{
    public function hydrate(string $class): object
    {
        $metadata = new ReflectionClass($class);

        return $metadata->newInstanceWithoutConstructor();
    }
}
```

```php
// ✅ An explicit factory names the constructed type and its requirements
final class ReportFactory
{
    public function create(string $kind): Report
    {
        return match ($kind) {
            'summary' => new SummaryReport,
            'detail' => new DetailReport,
        };
    }
}
```

## Configuration

```php
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Architecture\NoReflection;

return Soda::configure()
    ->withPaths(['src/'])
    ->with([
        new NoReflection(),
    ]);
```

## Related rules

- [no_runtime_contract_probes](no_runtime_contract_probes.md) — flags runtime capability discovery, the reflective reading side of the same problem.
- [no_anonymous_classes](no_anonymous_classes.md) — flags unnamed types, the other construction pattern tools cannot follow.
