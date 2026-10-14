# no_useless_return

📝 Reports a bare `return;` at the end of a function, method or closure.

💼 This rule is enabled in the ✅ `standard` config.

## Why

Reaching the closing brace already completes the callable, so a final bare
`return;` adds no behavior. The rule checks only the final direct statement of
each callable, including generators and nested callables independently.

Early guards and returns nested in branches, loops, try/catch or finally remain
accepted. Removing a return from finally can replace a pending result or expose
an exception. Explicit values (including `null`), file scope returns, arrow
functions and callables returning by reference are outside this rule. Abstract
signatures have no body to inspect.

Each finding uses the return's source line, `value: 1`, and `threshold: 0`.
The check does not prove arbitrary branches redundant or rewrite source.

## Examples

```php
// ❌ the final return adds no behavior
function save(): void
{
    persist();
    return;
}

// ✅ the callable completes after persistence
function save(): void
{
    persist();
}
```

```php
// ✅ accepted: the guard exits before the remaining work
function save(): void
{
    if (! isReady()) {
        return;
    }

    persist();
}
```

## Configuration

```php
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Complexity\NoUselessReturn;

return Soda::configure()
    ->withPaths(['src/'])
    ->with([
        new NoUselessReturn,
    ]);
```

## Related rules

- [no_return_in_finally](no_return_in_finally.md) — flags returns that replace pending outcomes during cleanup.
- [max_return_statements](max_return_statements.md) — bounds the total return count, including meaningful guards.
- [no_redundant_boolean_returns](no_redundant_boolean_returns.md) — removes branches that merely restate a predicate's result.
