# no_implicit_arguments

📝 Reports `func_get_args()`, `func_get_arg()`, and `func_num_args()` calls.

💼 This rule is enabled in the ✅ `standard` config.

## Why

Functions that read their arguments through the runtime argument list hide their contract: the declared signature no longer says which inputs the callable accepts, callers cannot see the requirements, and static analysis is blind to the data flow. The rule reports each implicit argument access and advises declaring every input as a named parameter, a variadic, or a typed command object.

## Examples

```php
// ❌
public function dispatch(): string
{
    $arguments = func_get_args();

    return array_shift($arguments);
}

// ✅
public function dispatch(string $command): string
{
    return $command;
}
```

```php
// ❌
public function sum(): int
{
    return array_sum(func_get_args());
}

// ✅
public function sum(int ...$values): int
{
    return array_sum($values);
}
```

## Configuration

```php
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Architecture\NoImplicitArguments;

return Soda::configure()
    ->withPaths(['src/'])
    ->with([
        new NoImplicitArguments(),
    ]);
```

## Related rules

- [no_untyped_parameters](no_untyped_parameters.md) — requires a declared type on every parameter that does appear in the signature.
- [max_arguments](max_arguments.md) — caps the number of declared parameters per callable.
