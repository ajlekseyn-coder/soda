# no_else_branches

📝 Reports redundant alternatives after a proven unconditional exit.

💼 This rule is enabled in the ✅ `standard` config.

## Why

After `return`, `throw`, `exit`, `break` or `continue`, the alternative can become
the following path without adding another exit. Exhaustive `if` branches whose
paths all exit are recognized too. The rule does not prohibit meaningful
non-exiting alternatives or require artificial returns, helper methods or `match`.

An `elseif` is reported only when every preceding branch in that chain exits.
A return in a later branch does not justify moving the final `else` across an
earlier non-exiting branch. Loops and try/catch statements do not prove an exit.

## Examples

```php
// ❌ the first path already exits
if ($user->isBanned()) {
    return false;
} else {
    return $user->hasAccess();
}

// ✅ the same paths without a redundant alternative
if ($user->isBanned()) {
    return false;
}

return $user->hasAccess();
```

```php
// ✅ meaningful mutually exclusive operations
if ($driver === 'pgsql') {
    createPostgresIndex();
} elseif ($driver === 'mysql') {
    createMysqlIndex();
}

// ✅ the last alternative still belongs to this chain
if ($first) {
    work();
} elseif ($second) {
    return;
} else {
    rest();
}
```

## Configuration

```php
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Complexity\NoElseBranches;

return Soda::configure()
    ->withPaths(['src/'])
    ->with([new NoElseBranches]);
```

## Related rules

- [max_control_nesting](max_control_nesting.md) measures nesting.
- [max_return_statements](max_return_statements.md) is an optional count of all returns.
