# max_cyclomatic_complexity

📝 Limits the number of independent branches a single method may contain.

💼 This rule is enabled in the ✅ `standard` config.

## Why

Cyclomatic complexity counts the decision points in a method: every `if`, `elseif`, loop, `case`, `catch`, boolean operator and ternary adds one path. A high score means many distinct scenarios share one body, so each change risks breaking paths the author no longer remembers. The rule reports methods whose complexity exceeds the limit and advises reviewing the branches and duplicate decisions; use a private method for a named step or an object for an independent responsibility. Note that guard clauses reduce nesting but do not reduce this score.

## Examples

```php
// ❌ complexity 9 (1 + eight decisions)
final class OrderPricer
{
    public function price(Order $order): int
    {
        $total = $order->total();

        if ($order->isPriority()) {                       // +1
            $total += 10;
        }

        if ($order->isInternational()) {                  // +1
            $total += 25;
        }

        foreach ($order->items() as $item) {              // +1
            if ($item->fragile()) {                       // +1
                $total += 5;
            }

            if ($item->oversized()) {                     // +1
                $total += 15;
            }

            if ($item->hazmat()) {                        // +1
                $total += 30;
            }

            if ($item->perishable()) {                    // +1
                $total += 20;
            }

            if ($item->requiresSignature()) {             // +1
                $total += 12;
            }

            $total += $item->tax();
        }

        return $total;
    }
}

// ✅ each named step keeps its own small score
final class OrderPricer
{
    public function price(Order $order): int
    {
        return $order->total() + prioritySurcharge($order) + internationalSurcharge($order);
    }
}
```

## Options

- `limit` (default `8`): maximum accepted cyclomatic complexity per method. A non-positive limit disables the check.

## Configuration

```php
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Complexity\MaxCyclomaticComplexity;

return Soda::configure()
    ->withPaths(['src/'])
    ->with([
        new MaxCyclomaticComplexity(8),
    ]);
```

## Related rules

- [max_cognitive_complexity](max_cognitive_complexity.md) — measures how hard the same branches are to read, weighted by nesting.
- [max_control_nesting](max_control_nesting.md) — bounds the depth of the branches that inflate this score.
- [max_statements_per_callable](max_statements_per_callable.md) — bounds total size independently of branching.
