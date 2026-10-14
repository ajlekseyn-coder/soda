# prefer_combined_guards

📝 Reports consecutive guard clauses that exit with the same statement.

💼 This rule is enabled in the ✅ `standard` config.

## Why

Two adjacent guards with an identical single exit describe one question asked in parts. Combining their conditions with `||` removes the duplicated exit while preserving condition evaluation order and short-circuit behavior. The rule reports each adjacent pair of `if` statements without `else` whose body is exactly one `return`, `throw`, `break`, or `continue`, and whose exits match: the same kind, and the same printed value, label, or break level.

Exits are compared by printed expression, ignoring surrounding formatting. Exits whose expressions assign while returning or throwing stay outside the comparison, because the merged condition would skip the assignment. Bodies with more than one statement, `elseif` chains, and non-adjacent guards are outside the policy.

## Examples

```php
// ❌ the same exit is written twice
public function check(Build $build): void
{
    if ($build->failed) {
        return;
    }

    if ($build->cancelled) {
        return;
    }

    $this->deploy($build);
}

// ✅ one guard answers one question
public function check(Build $build): void
{
    if ($build->failed || $build->cancelled) {
        return;
    }

    $this->deploy($build);
}
```

```php
// ❌ the same exception is thrown twice
function guard(int $value): void
{
    if ($value < 0) {
        throw new InvalidArgumentException('Unsupported value.');
    }

    if ($value > 100) {
        throw new InvalidArgumentException('Unsupported value.');
    }
}

// ✅ one throw behind one condition
function guard(int $value): void
{
    if ($value < 0 || $value > 100) {
        throw new InvalidArgumentException('Unsupported value.');
    }
}
```

## Configuration

```php
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Complexity\PreferCombinedGuards;

return Soda::configure()
    ->withPaths(['src/'])
    ->with([
        new PreferCombinedGuards(),
    ]);
```

## Related rules

- [no_identical_branches](no_identical_branches.md) — reports the same duplication inside ternary and match branches.
- [no_complex_control_conditions](no_complex_control_conditions.md) — caps how much the combined condition may carry.
- [prefer_continue](prefer_continue.md) — applies the same guard shape to loop iterations.
