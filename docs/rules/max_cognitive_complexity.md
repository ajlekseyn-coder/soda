# max_cognitive_complexity

📝 Caps Soda's PHP cognitive complexity score per method, reporting every contributing line as evidence.

💼 This rule is enabled in the ✅ `standard` config.

## Why

Cognitive complexity estimates how hard a method is to follow: nesting increases the weight of each decision, boolean operator runs, jumps and recursion add points. When the score exceeds the limit, the violation message lists each contributing line with its increment and reason, so the report points at concrete control flow rather than an opaque severity. The rule is documented as a research measurement: review the reported evidence before changing the code, and do not extract objects, traits or value objects solely to lower the score.

## Examples

```php
// ❌ cognitive complexity 16 (nested loops and ifs, boolean run)
function run(array $items): void
{
    for ($i = 0; $i < 3; $i++) {
        foreach ($items as $item) {
            if ($item->ready() && $item->valid()) {
                if ($item->priority()) {
                    if ($item->fresh()) {
                        work($item);
                    }
                }
            }
        }
    }
}

// ✅ cognitive complexity 3 (flat decisions only)
function run(array $items): void
{
    foreach ($items as $item) {
        if ($item->ready()) {
            work($item);
        }
    }
}
```

## Options

- `limit` (default `15`): maximum accepted cognitive complexity per method, including anonymous scopes. The value must be non-negative; `0` permits only linear code. Each point of the score is attributed to a source line and reason in the diagnostic message.

## Configuration

```php
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Complexity\MaxCognitiveComplexity;

return Soda::configure()
    ->withPaths(['src/'])
    ->with([
        new MaxCognitiveComplexity(15),
    ]);
```

## Related rules

- [max_cyclomatic_complexity](max_cyclomatic_complexity.md) — a structural branch count that ignores nesting, complementary to this nesting-aware score.
- [max_control_nesting](max_control_nesting.md) — bounds nesting depth directly, the main driver of cognitive complexity.
