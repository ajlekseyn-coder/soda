# no_declarations_before_early_exit

📝 Reports declarations before a guard clause when only the code behind the guard reads them.

💼 This rule is enabled in the ✅ `standard` config.

## Why

A variable declared before `return`, `throw`, `break`, or `continue` pays its cost on every path, including the early-exit path that never reads it. Moving the declaration below the guard keeps the exceptional path trivial and the variable scope tight. The rule reports statement-level assignments to plain local variables whose name is unread between the declaration and the guard and unread inside the guard itself, but read somewhere after it.

The guard must exit every branch: an `if` without `else` counts when its body ends with an exit, and an `if` with `else` or `elseif` counts when every branch does. Reads inside the guard condition or body keep the declaration in place, because the early-exit path genuinely needs the value. Closure captures count as reads. Loops take part through `continue` and `break` guards in their bodies.

Function and method calls stay in place because their effects may be required on
the exiting path. This includes JSON processing, whose error status is observable.
Construction, cloning and shell execution also stay in place: they can invoke
user code or external processes. Reference bindings are excluded.
Callables containing `goto` are skipped because lexical order does not establish
their execution order. Switch cases are inspected as separate statement lists.

Input snapshots stay before intervening writes to their source variables,
including array-key removal, increments, destructuring and loop bindings. A guard
condition that changes an input also prevents moving its earlier snapshot.
Writes confined to the exiting branch do not change the surviving path's inputs.

An initializer with an explicit write, increment or decrement stays in place:
its state change also runs on the exiting path. Output, included or evaluated
code, generator yields and explicit termination likewise prevent moving the
initializer. Closure and arrow function bodies are deferred. Object construction
is retained even when its constructor arguments are simple.

## Examples

```php
// ❌ the snapshot is only read after the guard
public function handle(array $request, bool $allowed): array
{
    $report = ['name' => $request['name'], 'email' => $request['email']];

    if (! $allowed) {
        throw new RuntimeException('Forbidden');
    }

    return $report;
}

// ✅ the guard comes first
public function handle(array $request, bool $allowed): array
{
    if (! $allowed) {
        throw new RuntimeException('Forbidden');
    }

    $report = ['name' => $request['name'], 'email' => $request['email']];

    return $report;
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
