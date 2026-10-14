# no_dynamic_invocation

📝 Reports indirect callable helpers and method calls whose name is selected at runtime.

💼 This rule is enabled in the ✅ `standard` config.

## Why

`call_user_func`, `call_user_func_array`, and `forward_static_call` hide which operation runs behind a helper function, and computed method names like `$object->$method()` hide it inside the call itself. In both cases static analysis, IDE navigation, and readers cannot see the target without executing the code. The rule reports each construct and advises invoking callables directly or replacing computed dispatch with a literal method name or an explicit dispatch map.

## Examples

```php
// ❌ Indirect callable helper
public function dispatch(string $command): string
{
    return call_user_func($this->handlers['normalize'], $command);
}

// ✅ Invoke the callable directly
public function dispatch(Closure $normalize, string $command): string
{
    return $normalize($command);
}
```

```php
// ❌ Computed method name
public function run(object $gateway, string $method): string
{
    return $gateway?->$method();
}

// ✅ Literal method name behind an explicit interface
public function run(CommittableGateway $gateway): string
{
    return $gateway->commit();
}
```

## Configuration

```php
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Architecture\NoDynamicInvocation;

return Soda::configure()
    ->withPaths(['src/'])
    ->with([
        new NoDynamicInvocation(),
    ]);
```

## Related rules

- [no_runtime_contract_probes](no_runtime_contract_probes.md) — flags `is_callable` and sibling capability probes that often pair with dynamic dispatch.
- [no_reflection](no_reflection.md) — flags reflective construction, the class-level variant of hidden invocation.
