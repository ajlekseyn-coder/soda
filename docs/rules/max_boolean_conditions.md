# max_boolean_conditions

📝 Limits the number of boolean operands a single condition inside a method may combine.

💼 This rule is enabled in the ✅ `standard` config.

## Why

A condition that chains many boolean operands hides several distinct decisions behind one expression. Readers must mentally evaluate every combination to know when the branch runs, and each new flag added to the chain makes that harder. The rule reports each condition whose boolean operand count exceeds the limit and advises giving a repeated or complex decision a name — usually a local value or a private predicate — instead of growing the chain.

## Examples

```php
// ❌ 6 boolean operands in one condition
final class AccessControl
{
    public function canAccess(User $user, Acl $acl): bool
    {
        if ($user->active
            && $user->verified
            && $user->hasSubscription()
            && $acl->allows('read')
            && $acl->allows('write')
            && ! $user->blocked) {
            return true;
        }

        return false;
    }
}

// ✅ name the sub-decisions
final class AccessControl
{
    public function canAccess(User $user, Acl $acl): bool
    {
        $permitted = $acl->allows('read') || $acl->allows('write');

        if ($user->active && $user->verified && $permitted) {
            return true;
        }

        return false;
    }
}
```

## Options

- `limit` (default `4`): maximum number of boolean operands accepted in one condition. The metric measures the conditions of `if`, `elseif`, `while`, `for` and ternary expressions and counts the operands combined by `&&`, `||` and negations; `$a && $b && ($c || $d) && ! $e` counts as 5. A non-positive limit disables the check.

## Configuration

```php
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Complexity\MaxBooleanConditions;

return Soda::configure()
    ->withPaths(['src/'])
    ->with([
        new MaxBooleanConditions(4),
    ]);
```

## Related rules

- [no_complex_control_conditions](no_complex_control_conditions.md) — flags conditions mixing logical operators or nesting computation, a sibling signal of hard-to-read predicates.
- [max_cyclomatic_complexity](max_cyclomatic_complexity.md) — bounds the total branch count of a method, of which boolean chains are one source.
