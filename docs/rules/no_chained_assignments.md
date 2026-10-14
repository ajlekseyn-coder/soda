# no_chained_assignments

📝 Forbids chained assignments such as `$a = $b = calculate();`.

💼 This rule is enabled in the ✅ `standard` config.

## Why

A chain assigns one value to several targets in a single expression, so readers must mentally split it back into separate statements and remember the right-to-left evaluation order. The rule reports the outermost assignment of each direct chain — covering ordinary, reference, compound and coalescing assignments — once per chain, wherever it appears, including inside returns, closures and arrow functions. Write separate assignments instead, without duplicating evaluation or changing reference semantics.

## Examples

```php
// ❌ one value fanned out to three targets
$a = $b = $c = calculate();

// ✅ separate assignments
$a = calculate();
$b = $a;
$c = $a;
```

```php
// ❌ the chain can hide inside a return
function run(): int
{
    return $a = $b = calculate();
}

// ✅ each target gets its own statement
function run(): int
{
    $a = calculate();
    $b = $a;

    return $a;
}
```

## Configuration

```php
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Complexity\NoChainedAssignments;

return Soda::configure()
    ->withPaths(['src/'])
    ->with([
        new NoChainedAssignments,
    ]);
```

## Related rules

- [no_assignment_in_return](no_assignment_in_return.md) — flags the return form of the same hidden-write pattern.
- [no_assignment_in_condition](no_assignment_in_condition.md) — forbids writes hidden in control-flow conditions.
- [max_consecutive_local_assignments](max_consecutive_local_assignments.md) — bounds long sequences of separate local assignments.
