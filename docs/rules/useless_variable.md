# useless_variable

📝 Detects variables that are direct copies of another variable and adds nothing.

💼 This rule is enabled in the ✅ `standard` config.

## Why

A variable assigned directly from another variable (`$a = $b`) introduces a second name for the same value. If the name adds nothing, every reader has to keep two bindings in sync for no benefit. The rule flags such alias assignments that can be safely removed by replacing all later uses of the copy with the source variable. The advice is to inline the value when the name adds nothing, or to use the variable where its name clarifies intent. The analysis is limited to function and method bodies; top-level code is not analyzed.

A variable is *not* reported when:

- it is mutated or reassigned after the copy (`$a++`, `$a += 1`, `$a = 10`);
- it is passed by reference (`foo(&$a)`);
- it is captured by a closure or arrow function;
- its local name may be observed by included code, `eval`, variable variables or symbol-table operations such as `compact()`;
- it holds an object that is later mutated (`$a->x = 1`);
- the source variable is changed or `unset` before the copy is used;
- a `foreach` key/value binding, catch variable or destructuring assignment replaces the copy or its source;
- the assigned value is a transformation (`$a = trim($b)`), not a direct copy.

## Examples

```php
// ❌
function render($row): string
{
    $data = $row;

    return $data['name'];
}

// ✅
function render($row): string
{
    return $row['name'];
}
```

```php
// ❌
function process($payload): void
{
    $input = $payload;
    persist($input);
}

// ✅
function process($payload): void
{
    persist($payload);
}
```

```php
// ✅ Accepted: the copy is mutated, so inlining is not safe
function counter($start): int
{
    $value = $start;
    $value++;

    return $value;
}
```

## Configuration

```php
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Usage\UselessVariableRule;

return Soda::configure()
    ->withPaths(['src/'])
    ->with([
        new UselessVariableRule(),
    ]);
```

## Related rules

- [no_temporary_return_variable](no_temporary_return_variable.md) — the return-specific case: a variable assigned only to be returned immediately.
- [no_reassigned_parameters](no_reassigned_parameters.md) — keeps input parameter bindings separate from transformed values.
