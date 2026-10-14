# max_statements_per_callable

📝 Limits how many executable statements a single method, function, closure or arrow function may contain.

💼 This rule is enabled in the ✅ `standard` config.

## Why

A long callable mixes many responsibilities in one body and forces readers to reconstruct the order of operations by themselves. The rule counts executable syntax directly, independent of formatting: spreading an expression over several lines does not lower the count, while comments and empty statements do not raise it. Every callable is evaluated separately — methods, named functions, closures and arrow functions — so a nested closure is not charged to its enclosing method. Name a cohesive step when it clarifies the operation; do not extract forwarding methods solely to lower the count.

## Examples

```php
// ❌ 21 executable statements in one callable
function import(iterable $rows): void
{
    $count = 0;
    $failed = 0;
    $cache = [];
    $errors = [];
    $start = microtime(true);
    foreach ($rows as $row) {
        $id = $row['id'];
        $name = $row['name'];
        $total = $row['total'];
        $tax = $row['tax'];
        $net = $total - $tax;
        $cache[$id] = $net;
        if ($net < 0) {
            $errors[] = $id;
            $failed++;
        }
        if ($name === '') {
            $failed++;
        }
        $count++;
        log($id, $net);
        flush();
    }
    report($count, $failed, microtime(true) - $start);
}

// ✅ a named step carries the loop
function import(iterable $rows): void
{
    $ledger = new Ledger;
    foreach ($rows as $row) {
        $ledger->record($row);
    }
    $ledger->report();
}
```

## Options

- `limit` (default `20`): maximum number of executable statements accepted within one callable. Arrow functions always count as a single statement. A negative limit is rejected at construction time.

## Configuration

```php
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Complexity\MaxStatementsPerCallable;

return Soda::configure()
    ->withPaths(['src/'])
    ->with([
        new MaxStatementsPerCallable(20),
    ]);
```

## Related rules

- [max_method_length](max_method_length.md) — bounds the same problem by source lines instead of statement count.
- [max_cyclomatic_complexity](max_cyclomatic_complexity.md) — bounds the branching load that long bodies usually accumulate.
- [max_consecutive_local_assignments](max_consecutive_local_assignments.md) — targets long preparation sequences inside the same body.
