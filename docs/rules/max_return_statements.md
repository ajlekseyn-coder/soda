# max_return_statements

📝 Limits the number of `return` statements a single method may contain.

💼 This rule is optional and is not enabled in the `standard` config.

## Why

Every return is an exit point a reader must keep in mind: the method may stop right there, so everything below it is conditional on the earlier exits. Many returns usually mean many scenarios packed into one body. The rule counts all returns, including guard clauses, and reports methods exceeding the limit at their source location. Review the whole scenario alongside nesting; do not add intermediary classes solely to move exits elsewhere.

## Examples

```php
// ❌ five returns
function grade(int $score): string
{
    if ($score < 0) {
        return 'invalid';
    }
    if ($score >= 90) {
        return 'excellent';
    }
    if ($score >= 75) {
        return 'good';
    }
    if ($score >= 60) {
        return 'pass';
    }

    return 'fail';
}

// ✅ one exit from a named mapping
function grade(int $score): string
{
    return match (true) {
        $score < 0  => 'invalid',
        $score >= 90 => 'excellent',
        $score >= 75 => 'good',
        $score >= 60 => 'pass',
        default     => 'fail',
    };
}
```

## Options

- `limit` (default `4`): maximum accepted number of return statements per method. A non-positive limit disables the check. Only returns belonging to the method itself are counted; returns inside nested closures or arrow functions belong to those callables.

## Configuration

```php
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Complexity\MaxReturnStatements;

return Soda::configure()
    ->withPaths(['src/'])
    ->with([
        new MaxReturnStatements(4),
    ]);
```

## Related rules

- [max_cyclomatic_complexity](max_cyclomatic_complexity.md) — counts the decisions that usually generate the extra exits.
- [max_control_nesting](max_control_nesting.md) — guard-style early returns flatten nesting but still count toward this limit.
- [max_statements_per_callable](max_statements_per_callable.md) — bounds the overall statement load of the same method.
