# no_runtime_code_evaluation

📝 Represent policy as typed code or validated data instead of evaluating generated source with `eval`.

💼 This rule is enabled in the ✅ `standard` config.

## Why

`eval` turns data into executable behavior at runtime: readers cannot see what runs, static analysis cannot follow it, and any unvalidated input that reaches the expression becomes a code injection path. The rule reports every `eval()` call and advises representing the policy as typed code — a match on explicit cases, strategy objects — or as validated data-driven configuration.

## Examples

```php
// ❌ Policy compiled from a string at runtime
final class TenantPolicyCompiler
{
    public function compile(string $tenant): string
    {
        return eval("return 'enabled:'.".var_export($tenant, true).';');
    }
}
```

```php
// ✅ Explicit cases, visible to readers and tools
final class TenantPolicy
{
    public function enabled(string $tenant): string
    {
        return match ($tenant) {
            'enterprise' => 'enabled:enterprise',
            default => 'disabled',
        };
    }
}
```

## Configuration

```php
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Architecture\NoRuntimeCodeEvaluation;

return Soda::configure()
    ->withPaths(['src/'])
    ->with([
        new NoRuntimeCodeEvaluation(),
    ]);
```

## Related rules

- [no_runtime_includes](no_runtime_includes.md) — flags pulling executable files in at runtime, the file-based variant of dynamic behavior.
- [no_dynamic_invocation](no_dynamic_invocation.md) — flags runtime-selected callables, the function-level variant of the same opacity.
