# no_redundant_boolean_comparisons

📝 Reports comparisons of an already boolean expression with `true` or `false`.

💼 This rule is enabled in the ✅ `standard` config.

## Why

An expression that already produces a boolean does not need another equality
check to ask whether it is true. Use the expression directly or negate it,
evaluating it once. Parentheses preserve the original operator precedence, and
any explicit boolean cast belongs to the expression being retained.

The check recognizes PHP constructs whose result is always bool: negation,
boolean casts, `isset`, `empty`, `instanceof`, equality and ordering comparisons,
and logical `&&`, `||`, `and`, `or`, `xor` expressions. It covers `==`, `!=`, `===`
and `!==` against a boolean literal on either side, including `TRUE` and `FALSE`.

Variables, properties and calls remain accepted because their types are not
proved by syntax alone. In particular, `$value === true`, `strpos(...) === false`
and `fopen(...) === false` can be meaningful strict checks. The spaceship operator
returns an integer, bitwise operations are not logical operations, and neither
is treated as a boolean expression. Ternaries, match expressions and literal-only
comparisons are also outside this check.

Each finding points to the comparison's source line, with `value: 1` and
`threshold: 0`. Nested expressions and callable bodies are inspected independently.
The check does not infer application types or generate automatic source edits.

## Examples

```php
// ❌ isset already produces bool
$present = isset($value) === true;
$missing = isset($value) === false;

// ✅ ask the predicate directly
$present = isset($value);
$missing = ! isset($value);
```

```php
// ❌ the comparison repeats a boolean result
$same = ($left === $right) !== false;
$different = false === ($left === $right);

// ✅ preserve the result or negate it
$same = $left === $right;
$different = ! ($left === $right);
```

```php
// ✅ accepted: these strict checks carry information about the result type
$enabled = $value === true;
$failed = strpos($message, 'needle') === false;
$failed = fopen($path, 'r') === false;
```

## Configuration

```php
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Complexity\NoRedundantBooleanComparisons;

return Soda::configure()
    ->withPaths(['src/'])
    ->with([
        new NoRedundantBooleanComparisons,
    ]);
```

## Related rules

- [no_unneeded_ternary](no_unneeded_ternary.md) — removes full ternaries with opposite literal boolean branches.
- [no_redundant_boolean_returns](no_redundant_boolean_returns.md) — expresses a predicate without separate literal return branches.
- [no_complex_control_conditions](no_complex_control_conditions.md) — identifies mixed logic and nested computations inside conditions.
