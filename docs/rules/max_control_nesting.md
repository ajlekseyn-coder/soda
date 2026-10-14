# max_control_nesting

📝 Limits how deeply control structures may nest inside a method.

💼 This rule is enabled in the ✅ `standard` config.

## Why

Each nesting level adds another context the reader must track, and the deepest branch combines the conditions of every enclosing level. Deeply nested code also hides the happy path behind layers of exceptional handling. The rule reports methods whose control-structure nesting depth exceeds the limit, at the line of the deepest structure, and advises returning early for exceptional paths and extracting the nested happy-path behavior into a named method.

## Examples

```php
// ❌ nesting depth 4
function handle(array $rows): void
{
    foreach ($rows as $row) {                 // depth 1
        if ($row->valid()) {                  // depth 2
            foreach ($row->items() as $item) {  // depth 3
                if ($item->ready()) {           // depth 4 — exceeds the limit
                    consume($item);
                }
            }
        }
    }
}

// ✅ guards keep the happy path flat
function handle(array $rows): void
{
    foreach ($rows as $row) {
        if (! $row->valid()) {
            continue;
        }
        consumeReady($row);
    }
}
```

## Options

- `limit` (default `3`): maximum accepted nesting depth of control structures per method. A non-positive limit disables the check. Catch bodies start at the same depth as the enclosing `try`.

## Configuration

```php
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Complexity\MaxControlNesting;

return Soda::configure()
    ->withPaths(['src/'])
    ->with([
        new MaxControlNesting(3),
    ]);
```

## Related rules

- [no_else_branches](no_else_branches.md) — guard clauses and early exits are the primary way to reduce nesting.
- [max_cyclomatic_complexity](max_cyclomatic_complexity.md) — bounds the total number of branches, which deep nesting often inflates.
