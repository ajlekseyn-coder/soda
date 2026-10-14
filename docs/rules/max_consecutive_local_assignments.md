# max_consecutive_local_assignments

📝 Limits the number of consecutive first assignments to new local variables at the start of a callable body.

💼 This rule is enabled in the ✅ `standard` config.

## Why

A long opening sequence of local assignments is implicit preparation: the reader has to hold many unnamed values in mind before any behavior happens. The rule measures maximal runs of consecutive initial local bindings (assignments, not reads or re-assignments) inside methods, functions, closures and arrow functions, and reports each excessive run once at its first assignment. It advises naming a meaningful calculation when that clarifies the scenario — not introducing wrapper methods solely to lower the count.

## Examples

```php
// ❌ nine initial local bindings in a row
function report(): array
{
    $width = measure();
    $height = measure();
    $depth = measure();
    $area = $width * $height;
    $volume = $area * $depth;
    $weight = estimate($volume);
    $cost = price($weight);
    $tax = tax($cost);
    $total = $cost + $tax;

    return compact('total');
}

// ✅ name the preparation as one calculation
function report(): array
{
    $invoice = buildInvoice();

    return ['total' => $invoice->total()];
}
```

## Options

- `limit` (default `8`): inclusive maximum run length of consecutive initial local assignments. Parameter reads, re-assignments of existing variables, compound updates, destructuring, references and assignments inside nested blocks break the run. `0` rejects any initial local binding.

## Configuration

```php
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Complexity\MaxConsecutiveLocalAssignments;

return Soda::configure()
    ->withPaths(['src/'])
    ->with([
        new MaxConsecutiveLocalAssignments(8),
    ]);
```

## Related rules

- [max_statements_per_callable](max_statements_per_callable.md) — bounds the total statement count of a callable, of which preparation sequences are one part.
- [no_chained_assignments](no_chained_assignments.md) — forbids packing several assignments into one expression instead of a readable sequence.
