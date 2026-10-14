# prefer_null_coalesce_assignment

📝 Reports fallback assignments that repeat the target on both sides.

💼 This rule is enabled in the ✅ `standard` config.

## Why

`$value = $value ?? $fallback` names the target twice and asks the reader to confirm both spellings agree. The `??=` operator assigns the fallback in one step and evaluates the target once. The rule reports assignments whose right side is a null coalesce whose left operand prints exactly like the assignment target — plain variables, array dimensions, and property fetches all qualify.

Targets evaluated through calls stay outside the comparison: the merged operator would evaluate them once instead of twice, which is a behavior change, not a cleanup.

## Examples

```php
// ❌ the target is spelled twice
$this->scope = $this->scope ?? 'web';
$cache['limits'] = $cache['limits'] ?? [];

// ✅ one operator carries the fallback
$this->scope ??= 'web';
$cache['limits'] ??= [];
```

```php
// ✅ a call on the left side is evaluated for a reason and stays
$value = resolve($value) ?? 'draft';
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
- [no_consecutive_local_assignments](no_consecutive_local_assignments.md) — caps the preparation runs these fallbacks often pad.
- [useless_variable](useless_variable.md) — removes variables whose fallback is never read.
