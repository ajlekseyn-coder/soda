# no_temporary_return_variable

📝 Forbids a local variable created only to return its value immediately.

💼 This rule is enabled in the ✅ `standard` config.

## Why

A variable assigned on one line and returned on the next — `$result = load(); return $result;` — duplicates the expression without adding a name that carries meaning. It lengthens the method and forces the reader to track a binding that exists for a single statement. The rule reports the assignment and advises returning the expression directly. Variables with any other use (logged, transformed, captured by a closure, or part of a larger expression) are not reported, nor are reference returns, `global`/`static` bindings, or assignments where the right-hand side already uses the same variable.

## Examples

```php
// ❌
function version(): int
{
    $result = $this->invoke('GetVersion');

    return $result;
}

// ✅
function version(): int
{
    return $this->invoke('GetVersion');
}
```

```php
// ✅ Accepted: the variable is also used elsewhere
function status(): int
{
    $result = load();
    logValue($result);

    return $result;
}
```

```php
// ✅ Accepted: the returned expression is not the variable itself
function total(): int
{
    $result = load();

    return $result + 1;
}
```

## Configuration

```php
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Usage\NoTemporaryReturnVariable;

return Soda::configure()
    ->withPaths(['src/'])
    ->with([
        new NoTemporaryReturnVariable(),
    ]);
```

## Related rules

- [useless_variable](useless_variable.md) — flags the broader case of variables that are direct copies of another variable.
- [no_assignment_in_return](no_assignment_in_return.md) — the inverse pattern: an assignment embedded in the `return` statement itself.
