# no_singleton_access

📝 Construct collaborators at the composition root instead of reaching for a static `instance()`/`getInstance()` accessor.

💼 This rule is enabled in the ✅ `standard` config.

## Why

A static singleton accessor hides construction and lifetime: callers cannot see that they depend on the singleton's type, the shared instance cannot be replaced for tests, and lifetime decisions leak into every call site. The rule checks static calls named `instance` or `getInstance` (case-insensitive) only when a resolved declaration proves that an object is cached in shared storage. The name alone does not establish a singleton: a factory can construct or clone an independent value.

The current proof recognizes a static method returning `self::$slot ??= new ...`
or `static::$slot ??= new ...`, with an initially null static property declared
by that class or trait. Inherited and trait methods are resolved across analyzed files;
overrides replace the inherited body. Missing or ambiguous declarations and
other method bodies remain unreported. Package code is never loaded to infer
behavior. A first-class callable reference is not itself an accessor execution.

## Examples

```php
// ❌ Construction and lifetime are hidden behind a static accessor
final class GlobalFeatureManager
{
    private static ?self $current = null;

    public static function instance(): self
    {
        return self::$current ??= new self();
    }

    public function resolve(string $feature): string
    {
        return 'enabled:'.$feature;
    }
}

$features = GlobalFeatureManager::instance()->resolve('exports');
```

```php
// ✅ The collaborator arrives with an explicit lifetime
final class ExportsJob
{
    public function __construct(private readonly GlobalFeatureManager $features) {}

    public function handle(): void
    {
        $enabled = $this->features->resolve('exports');
    }
}
```

## Configuration

```php
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Architecture\NoSingletonAccess;

return Soda::configure()
    ->withPaths(['src/'])
    ->with([
        new NoSingletonAccess(),
    ]);
```

## Related rules

- [no_service_locator_calls](no_service_locator_calls.md) — flags pulling dependencies from the global container instead of the constructor.
- [no_static_mutable_properties](no_static_mutable_properties.md) — flags the static mutable storage singletons usually sit on.
- [no_global_state](no_global_state.md) — flags global variable access, the rawest form of shared state.
