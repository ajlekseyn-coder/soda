# max_delegation_depth

📝 Limits consecutive transparent forwarding methods, rather than the size of each individual method.

💼 This rule is enabled in the ✅ `standard` config.

## Why

A forwarding method adds a name to the call path without adding an operation: it neither decides, transforms, nor produces anything. Chains of such methods hide where the real work happens and force readers to hop through several files to understand one call. The rule reports each chain of transparent forwarding methods longer than the limit — and always reports forwarding cycles — and advises removing forwarding steps; moving a call into another method does not simplify the operation.

## Examples

```php
// ❌ four transparent hops before real work happens
final class First
{
    public function __construct(private Second $next) {}

    public function run($value)
    {
        // forwards, decides nothing
        return $this->next->send($value);
    }
}

final class Second
{
    public function __construct(private Third $next) {}

    public function send($value)
    {
        return $this->next->write($value);
    }
}

final class Third
{
    public function __construct(private Fourth $next) {}

    public function write($value)
    {
        return $this->next->apply($value);
    }
}

final class Fourth
{
    public function __construct(private Terminal $next) {}

    public function apply($value)
    {
        return $this->next->finish($value);
    }
}

final class Terminal
{
    public function finish($value)
    {
        return trim($value);
    }
}
```

```php
// ✅ an operation or transformation ends the transparent chain
final class Workflow
{
    public function __construct(private Terminal $terminal) {}

    public function run($value)
    {
        authorize($value);

        return $this->terminal->finish(trim($value));
    }
}
```

## Options

- `limit` (default `3`): maximum accepted number of consecutive forwarding methods. The limit must be positive; the check resolves calls through private methods, traits and inheritance, so local indirection cannot hide a chain.

## Configuration

```php
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Structure\MaxDelegationDepth;

return Soda::configure()
    ->withPaths(['src/'])
    ->with([
        new MaxDelegationDepth(3),
    ]);
```

## Related rules

- [no_trivial_delegating_classes](no_trivial_delegating_classes.md) — flags whole classes whose entire surface is forwarding to a single dependency.
- [max_method_length](max_method_length.md) — bounds the size of the methods that such chains split artificially.
- [max_dependencies](max_dependencies.md) — constructor dependency counts often grow alongside forwarding chains.
