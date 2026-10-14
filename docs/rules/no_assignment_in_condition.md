# no_assignment_in_condition

📝 Forbids assignments inside control-flow conditions.

💼 This rule is enabled in the ✅ `standard` config.

## Why

An assignment inside a condition hides a state change inside a question. Readers can easily mistake `=` for `==`, and the condition stops being a pure test because evaluating it mutates the program. The rule reports assignments (including compound and reference assignments) in `if`, `elseif`, `while`, `do-while`, `for` conditions and ternary conditions, and advises assigning first, then making the condition a pure question with no hidden state change. Assignments in the initialization and loop expressions of a `for` statement are the accepted idiom and are not reported; assignments inside nested callable bodies also stay outside this rule.

## Examples

```php
// ❌ hidden state change in the condition
if ($value = nextValue()) {
    echo $value;
}

// ✅ assign first, then test
$value = nextValue();
if ($value !== null) {
    echo $value;
}
```

```php
// ❌ compound assignment inside a loop condition
while ($offset += step()) {
    echo $offset;
}

// ✅ the for loop expressions are the accepted place for it
for ($offset = 0; $offset < $limit; $offset += step()) {
    echo $offset;
}
```

## Configuration

```php
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Complexity\NoAssignmentInCondition;

return Soda::configure()
    ->withPaths(['src/'])
    ->with([
        new NoAssignmentInCondition,
    ]);
```

## Related rules

- [no_assignment_in_return](no_assignment_in_return.md) — the same hidden-write concern applied to return expressions.
- [no_chained_assignments](no_chained_assignments.md) — targets `a = b = value` chains that also bury writes inside expressions.
- [no_complex_control_conditions](no_complex_control_conditions.md) — keeps conditions readable once the assignments are gone.
