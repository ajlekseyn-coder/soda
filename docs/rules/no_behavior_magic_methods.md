# no_behavior_magic_methods

📝 Replace `__call`, `__callStatic`, `__get`, `__set`, `__isset`, and `__unset` with explicit methods and contracts.

💼 This rule is enabled in the ✅ `standard` config.

## Why

Behavioral magic methods hide the supported contract. Callers, static analysis tools, and IDEs cannot see which members actually exist, so every interaction with the object becomes a guess validated only at runtime. A typo silently turns into a magic lookup instead of an error. The rule reports each of these methods and advises exposing explicit methods so callers and tools can see the supported contract.

## Examples

```php
// ❌
final class TenantDashboard
{
    /** @param list<string> $features */
    public function __construct(private array $features) {}

    public function __get(string $feature): string
    {
        return in_array($feature, $this->features, true) ? 'enabled' : 'disabled';
    }
}

// ✅
final class TenantDashboard
{
    /** @param list<string> $features */
    public function __construct(private array $features) {}

    public function feature(string $name): string
    {
        return in_array($name, $this->features, true) ? 'enabled' : 'disabled';
    }
}
```

```php
// ❌
final class ModelRepository
{
    public function __call(string $method, array $arguments): mixed
    {
        return $this->query()->{$method}(...$arguments);
    }
}

// ✅
final class ModelRepository
{
    public function find(int $id): ?Model
    {
        return $this->query()->find($id);
    }
}
```

## Configuration

```php
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Architecture\NoBehaviorMagicMethods;

return Soda::configure()
    ->withPaths(['src/'])
    ->with([
        new NoBehaviorMagicMethods(),
    ]);
```

## Related rules

- [no_dynamic_invocation](no_dynamic_invocation.md) — flags the runtime-selected calls magic methods typically route to.
- [no_dynamic_properties](no_dynamic_properties.md) — flags `#[AllowDynamicProperties]`, the state-shape counterpart of hidden member access.
