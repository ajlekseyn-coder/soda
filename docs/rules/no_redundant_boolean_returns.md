# no_redundant_boolean_returns

📝 Reports `if` branches that only return opposite boolean literals.

💼 This rule is enabled in the ✅ `standard` config.

## Why

Returning `true` for a condition and `false` otherwise restates the predicate.
Express it directly with `return (bool) (condition)`, or use
`return ! (condition)` for the inverse. Evaluate the condition once and remove
the positive cast only when its result is known to be bool: PHP conditions do
not necessarily produce boolean values, and even the string `'0'` is falsey.

The check accepts an explicit `else` or an immediate fallback return in the same
statement list. Each branch must contain exactly one return of a boolean literal;
case-insensitive spellings such as `TRUE` are accepted. Additional work, `elseif`
chains, yield statements, matching results and non-literal results remain accepted.
An outer return cannot complete a nested branch or closure. Nested callables are
checked independently.

Each finding points to the `if` line, with `value: 1` and `threshold: 0`.
There are no automatic source edits; preserve comments that explain an invariant.

## Examples

```php
// ❌ the branches restate the condition
function isReady(mixed $value): bool
{
    if ($value) {
        return true;
    }

    return false;
}

// ✅ conversion preserves PHP's boolean result
function isReady(mixed $value): bool
{
    return (bool) $value;
}
```

```php
// ❌ the branches negate the condition
function isBlocked(): bool
{
    if (isReady()) {
        return false;
    } else {
        return true;
    }
}

// ✅ negation says it directly
function isBlocked(): bool
{
    return ! isReady();
}
```

```php
// ✅ accepted: the branch performs meaningful work
function canProceed(): bool
{
    if (isReady()) {
        recordAttempt();

        return true;
    }

    return false;
}
```

## Configuration

```php
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Complexity\NoRedundantBooleanReturns;

return Soda::configure()
    ->withPaths(['src/'])
    ->with([
        new NoRedundantBooleanReturns,
    ]);
```

## Related rules

- [no_unneeded_ternary](no_unneeded_ternary.md) — applies the same simplification to boolean ternary expressions.
- [no_else_branches](no_else_branches.md) — keeps control flow flat with guards and early exits.
- [boolean_methods_without_prefix](boolean_methods_without_prefix.md) — names the predicates feeding these conditions clearly.
