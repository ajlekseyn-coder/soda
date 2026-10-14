# no_singleton_access

📝 Construct collaborators at the composition root instead of reaching for a static `instance()`/`getInstance()` accessor.

💼 This rule is enabled in the ✅ `standard` config.

## Why

A static singleton accessor hides construction and lifetime: callers cannot see that they depend on the singleton's type, the shared instance cannot be replaced for tests, and lifetime decisions leak into every call site. The rule reports every static call named `instance` or `getInstance` (case-insensitive) and advises constructing collaborators at the composition root and injecting their explicit lifetime instead.

## Examples

```php
// ❌ Construction and lifetime are hidden behind a static accessor
final class GlobalFeatureManager
{
    public static function instance(): self
    {
        return new self();
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
