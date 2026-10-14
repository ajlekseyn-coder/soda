# no_else_branches

📝 Forbids `else` and `elseif` in favor of guard clauses and early exits.

💼 This rule is enabled in the ✅ `standard` config.

## Why

An `else` extends the life of a branch: the reader must hold the preceding condition in mind while walking through the alternative. Guard clauses end each exceptional path immediately, so the remaining happy path stays flat and explicit. The rule reports every `else` and `elseif` at its exact line and advises ending the preceding case early — then using a separate `if` in place of an `elseif`.

## Examples

```php
// ❌ nested alternatives
function hasAccess(User $user): bool
{
    if (! $user->isBanned()) {
        if ($user->isAdmin()) {
            return true;
        } else {
            return $user->isGranted(Grant::Edit);
        }
    } else {
        return false;
    }
}

// ✅ guards keep the happy path flat
function hasAccess(User $user): bool
{
    if ($user->isBanned()) {
        return false;
    }

    if ($user->isAdmin()) {
        return true;
    }

    return $user->isGranted(Grant::Edit);
}
```

## Configuration

```php
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Complexity\NoElseBranches;

return Soda::configure()
    ->withPaths(['src/'])
    ->with([
        new NoElseBranches,
    ]);
```

## Related rules

- [max_control_nesting](max_control_nesting.md) — guard clauses are the primary way to reduce nesting depth.
- [max_cyclomatic_complexity](max_cyclomatic_complexity.md) — counts the extra branch each `elseif` contributes.
- [no_identical_branches](no_identical_branches.md) — flags when a split with `else` produces branches that do the same thing.
