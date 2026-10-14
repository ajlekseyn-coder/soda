# no_assignment_in_return

📝 Reports hidden assignments inside return expressions.

💼 This rule is enabled in the ✅ `standard` config.

## Why

A return that also writes state (`return $value = calculate();`) performs two jobs in one expression, and the write is easy to miss. The rule reports ordinary, compound and reference assignments anywhere inside a return expression, including nested calls, ternary branches, match arms and the returns of nested closures or anonymous classes. It deliberately preserves two idioms: direct lazy initialization (`return $this->cache ??= compute();`) and a single directly visible update of the returning owner's named state (`return $this->value = calculate();`). Make the state change explicit before returning, preserving evaluation order and reference semantics.

## Examples

```php
// ❌ assignment buried in the returned expression
function load(): int
{
    return $value = calculate();
}

// ✅ the write is a statement of its own
function load(): int
{
    $value = calculate();

    return $value;
}
```

```php
// ✅ accepted: lazy initialization and a direct owner update
function cached(): array
{
    return $this->items ??= buildItems();
}

function recorded(): int
{
    return $this->last = calculate();
}
```

## Options

- `allowLazyInitialization` (default `true`): accept a direct `??=` lazy initialization when returning its cached value.
- `allowOwnerStateReturn` (default `true`): accept one directly visible update of the returning owner's named state.
- `allowArrowStateUpdates` (default `true`): keep concise arrow callbacks whose only assignments update named owner state.

## Configuration

```php
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Complexity\NoAssignmentInReturn;

return Soda::configure()
    ->withPaths(['src/'])
    ->with([
        new NoAssignmentInReturn(
            allowLazyInitialization: true,
            allowOwnerStateReturn: true,
            allowArrowStateUpdates: true,
        ),
    ]);
```

## Related rules

- [no_chained_assignments](no_chained_assignments.md) — `return $a = $b = calculate();` is also a chain and is reported by both rules.
- [no_assignment_in_condition](no_assignment_in_condition.md) — the same hidden-write concern applied to control-flow conditions.
- [no_temporary_return_variable](no_temporary_return_variable.md) — the counterpart concern for needless local variables right before a return.
