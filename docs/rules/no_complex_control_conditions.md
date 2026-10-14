# no_complex_control_conditions

📝 Requires control-flow conditions to express one directly readable question.

💼 This rule is enabled in the ✅ `standard` config.

## Why

A condition that mixes different logical operators (`$a && ($b || $c)`, `$a xor $b`) or computes values inline (`Order::query()->pending()->count() > $limit`, `getUser()->isReady()`) forces readers to evaluate sub-expressions before they understand the question being asked. The rule reports two kinds of conditions: mixed logic, and nested computation buried in the test. Keep simple predicates, comparisons and homogeneous boolean chains inline; name mixed decisions or prepare nested computations locally.

## Examples

```php
// ❌ mixed operators inside one condition
if ($active && ($verified || $admin)) {
    grantAccess();
}

// ✅ the distinct decision gets a name
$mayGrant = $active && ($verified || $admin);
if ($mayGrant) {
    grantAccess();
}
```

```php
// ❌ a whole query is computed inside the test
while (query()->exists()) {
    consume();
}

// ✅ the value is prepared before the loop
$pending = query()->pending();
while ($pending->exists()) {
    consume();
}
```

## Configuration

```php
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Complexity\NoComplexControlConditions;

return Soda::configure()
    ->withPaths(['src/'])
    ->with([
        new NoComplexControlConditions,
    ]);
```

## Related rules

- [max_boolean_conditions](max_boolean_conditions.md) — bounds the number of boolean operands when a condition is long but homogeneous.
- [no_assignment_in_condition](no_assignment_in_condition.md) — removes hidden writes from the same conditions.
- [no_repeated_compound_conditions](no_repeated_compound_conditions.md) — detects the same compound condition repeated across methods.
