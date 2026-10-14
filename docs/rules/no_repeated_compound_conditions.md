# no_repeated_compound_conditions

📝 Reports the same compound condition repeated across several methods of a class.

💼 This rule is enabled in the ✅ `standard` config.

## Why

When three or more methods of a class test the same compound condition over its properties — for example `$this->active && ! $this->suspended` — the decision has no name, so every future change must find and update all copies. The rule fingerprints compound conditions (with boolean operators, reading class properties) inside plain classes and reports each group repeated in enough distinct methods, listing every location. Extract a named predicate when the repeated conditions express the same decision; reevaluate it at each use and do not cache mutable state.

## Examples

```php
// ❌ one decision copied into three methods
final class Subscription
{
    private bool $active;
    private bool $suspended;

    public function invoice(): void
    {
        if ($this->active && ! $this->suspended) { /* ... */ }
    }

    public function renew(): void
    {
        if ($this->active && ! $this->suspended) { /* ... */ }
    }

    public function cancel(): void
    {
        if ($this->active && ! $this->suspended) { /* ... */ }
    }
}

// ✅ the decision gets one named owner
final class Subscription
{
    private bool $active;
    private bool $suspended;

    private function usable(): bool
    {
        return $this->active && ! $this->suspended;
    }

    public function invoice(): void
    {
        if ($this->usable()) { /* ... */ }
    }

    public function renew(): void
    {
        if ($this->usable()) { /* ... */ }
    }

    public function cancel(): void
    {
        if ($this->usable()) { /* ... */ }
    }
}
```

## Options

- `minMethods` (default `3`): minimum number of distinct methods that must share one condition fingerprint for a finding. Must be at least `2`.

## Configuration

```php
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Complexity\NoRepeatedCompoundConditions;

return Soda::configure()
    ->withPaths(['src/'])
    ->with([
        new NoRepeatedCompoundConditions(3),
    ]);
```

## Related rules

- [max_boolean_conditions](max_boolean_conditions.md) — bounds the operand count of a single condition.
- [no_complex_control_conditions](no_complex_control_conditions.md) — keeps each individual condition readable.
- [repeated_owner_concern](repeated_owner_concern.md) — detects the broader pattern of duplicated owner behavior across methods.
