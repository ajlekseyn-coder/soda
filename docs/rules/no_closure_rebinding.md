# no_closure_rebinding

📝 Forbid `Closure::bind()` and `bindTo()`; pass an explicit collaborator instead of changing a closure scope at runtime.

💼 This rule is enabled in the ✅ `standard` config.

## Why

Rebinding a closure changes which object and class scope its `$this` refers to after the closure was written. That bypasses the visible scope and ownership model: code that never mentions a class can reach into its private state. The rule reports `Closure::bind(...)` static calls and `$closure->bindTo(...)` method calls, and advises passing an explicit collaborator contract instead.

## Examples

```php
// ❌
final class EncapsulatedPolicyAdapter
{
    public function prepare(): string
    {
        $policy = Closure::bind(static fn (): string => 'working', null);

        return $policy instanceof Closure ? 'bound' : 'missing';
    }
}

// ✅ Pass the collaborator explicitly
final class PolicyAdapter
{
    public function prepare(Policy $policy): string
    {
        return $policy->apply();
    }
}
```

```php
// ❌
$bound = $policy->bindTo($target);

// ✅ Declare the dependency in the signature instead
public function prepare(Policy $policy, Target $target): string
{
    return $policy->applyTo($target);
}
```

## Configuration

```php
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Architecture\NoClosureRebinding;

return Soda::configure()
    ->withPaths(['src/'])
    ->with([
        new NoClosureRebinding(),
    ]);
```

## Related rules

- [no_dynamic_invocation](no_dynamic_invocation.md) — flags runtime-selected callables, the call-side counterpart of runtime scope changes.
- [no_runtime_code_evaluation](no_runtime_code_evaluation.md) — flags `eval`, the most extreme form of manufacturing behavior at runtime.
