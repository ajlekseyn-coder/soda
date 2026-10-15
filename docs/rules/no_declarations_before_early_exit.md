# no_declarations_before_early_exit

📝 Reports declarations before a guard clause when only the code behind the guard reads them.

💼 This rule is enabled in the ✅ `standard` config.

## Why

A variable declared before `return`, `throw`, `break`, or `continue` pays its cost on every path, including the early-exit path that never reads it. Moving the declaration below the guard keeps the exceptional path trivial and the variable scope tight. The rule reports statement-level assignments to plain local variables whose name is unread between the declaration and the guard and unread inside the guard itself, but read somewhere after it.

The guard must exit every branch: an `if` without `else` counts when its body ends with an exit, and an `if` with `else` or `elseif` counts when every branch does. Reads inside the guard condition or body keep the declaration in place, because the early-exit path genuinely needs the value. Closure captures count as reads. Loops take part through `continue` and `break` guards in their bodies.

JSON processing stays before guards that read its error status through
`json_last_error()` or `json_last_error_msg()`. Reference bindings are excluded.
Callables containing `goto` are skipped because lexical order does not establish
their execution order. Switch cases are inspected as separate statement lists.

## Examples

```php
// ❌ the report is computed even when the user is gone
public function handle(Request $request): Report
{
    $report = $this->compile($request);

    if (! $request->user()->canSeeReports()) {
        return abort(403);
    }

    return $report;
}

// ✅ the guard comes first, the work follows
public function handle(Request $request): Report
{
    if (! $request->user()->canSeeReports()) {
        return abort(403);
    }

    return $this->compile($request);
}
```

```php
// ✅ the guard reads the value, the declaration stays
public function price(?Item $item): int
{
    $base = $this->basePrice();

    if ($base === null) {
        return 0;
    }

    return $base + $item->surcharge();
}
```

## Configuration

```php
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Complexity\NoDeclarationsBeforeEarlyExit;

return Soda::configure()
    ->withPaths(['src/'])
    ->with([
        new NoDeclarationsBeforeEarlyExit(),
    ]);
```

## Related rules

- [no_else_branches](no_else_branches.md) — keeps the happy path guard-shaped; this rule keeps the pre-guard code empty.
- [no_assignment_in_return](no_assignment_in_return.md) — keeps hidden writes out of the exit statements themselves.
- [useless_variable](useless_variable.md) — removes the variable entirely when no reader remains.
