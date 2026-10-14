# no_trivial_delegating_classes

📝 Reports behavior-free classes that only pass their operations through to one dependency.

💼 This rule is enabled in the ✅ `standard` config.

## Why

A class whose complete surface forwards unchanged arguments to a single collaborator adds a name to the call path without adding a concept: every new operation must be mirrored, and the indirection hides where the work happens. The rule reports concrete composed classes (extending a parent or using traits) whose every operation delegates to one property and whose constructor only wires that dependency. Call the collaborator directly when the wrapper adds no concept; keep genuine adapters and boundaries, and remember that adding an interface alone does not simplify the call path.

## Examples

```php
// ❌ the class only forwards to one dependency
final class OrderSaver
{
    public function __construct(private OrderRepository $repository) {}

    public function save(Order $order): void
    {
        $this->repository->save($order);
    }
}
```

```php
// ✅ the method adds a policy decision of its own
final class OrderSaver
{
    public function __construct(private OrderRepository $repository) {}

    public function save(Order $order): void
    {
        if ($order->isValid()) {
            $this->repository->save($order);
        }
    }
}
```

```php
// ✅ an adapter transforms an argument, so it owns behavior
final class OrderSaver
{
    public function __construct(private OrderRepository $repository) {}

    public function save(Order $order): void
    {
        $this->repository->save($order->normalized());
    }
}
```

## Options

- `contracts` (default `[]`): list of qualified boundary class or interface names that must remain available unchanged; matching classes are exempt regardless of case. Source markers alone never exempt a class.

## Configuration

```php
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Structure\NoTrivialDelegatingClasses;

return Soda::configure()
    ->withPaths(['src/'])
    ->with([
        new NoTrivialDelegatingClasses(),
    ]);
```

## Related rules

- [max_delegation_depth](max_delegation_depth.md) — bounds chains of transparent forwarding methods, including cross-class ones.
- [no_trivial_factories](no_trivial_factories.md) — flags methods that only forward their parameters into a `new` expression.
- [max_dependencies](max_dependencies.md) — bounds the constructor wiring that trivial wrappers add to.

