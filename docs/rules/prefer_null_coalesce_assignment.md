# prefer_null_coalesce_assignment

📝 Reports fallback assignments that repeat the target on both sides.

💼 This rule is enabled in the ✅ `standard` config.

## Why

`$value = $value ?? $fallback` names the target twice and asks the reader to confirm both spellings agree. The `??=` operator assigns the fallback in one step and evaluates the target once. The rule compares assignments whose right side is a null coalesce whose left operand prints exactly like the assignment target.

Targets evaluated through calls, assignments or increments stay outside the comparison: the merged operator would evaluate them once instead of twice, changing behavior.

A property qualifies only when it is an ordinary declared mutable field on `$this`
inside a method of a final class with no parent, traits or magic accessors. Hooked,
readonly and unknown properties stay outside the recommendation: `??=` can skip
an observable write when the current value is non-null. Rebound closures do not
inherit their enclosing method's property proof.

## Examples

```php
// ❌ the target is spelled twice
$scope = $scope ?? 'web';
$cache['limits'] = $cache['limits'] ?? [];

// ✅ one operator carries the fallback
$scope ??= 'web';
$cache['limits'] ??= [];
```

```php
// ✅ a call on the left side is evaluated for a reason and stays
$value = resolve($value) ?? 'draft';

// ✅ the model's setter may need to observe every assignment
$subscription->type = $subscription->type ?? 'default';
```

## Configuration

```php
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Complexity\PreferNullCoalesceAssignment;

return Soda::configure()
    ->withPaths(['src/'])
    ->with([
        new PreferNullCoalesceAssignment(),
    ]);
```

## Related rules

- [no_chained_assignments](no_chained_assignments.md) — keeps one write per statement.
- [max_consecutive_local_assignments](max_consecutive_local_assignments.md) — caps the preparation runs these fallbacks often pad.
- [useless_variable](useless_variable.md) — removes variables whose fallback is never read.
