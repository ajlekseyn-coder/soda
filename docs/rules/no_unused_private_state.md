# no_unused_private_state

📝 Removes private properties and constants that are never read, including composed trait state.

💼 This rule is enabled in the ✅ `standard` config.

## Why

A private declaration or a write does not establish a state responsibility. A property that is assigned and incremented but never read, or a constant defined only as decoration, is dead weight: it widens the class's internal surface without contributing to its behavior. The rule composes a class with its traits — a trait member counts as part of every class that uses the trait — and reports any private property or constant that has no read anywhere in that composed surface. Public and protected state is exempt because external code may read it. Ordinary writes, including array element writes, do not protect a field. Reads and explicit releases do: assigning `null` or unsetting a slot releases its previous value and can end a resource or allocation lifetime.

## Examples

```php
// ❌
class Worker
{
    private const MARKER = 1;
    private int $count;

    public function __construct(private string $unused) {}

    public function reset(): void
    {
        $this->count = 0;
    }
}

// ✅
class Worker
{
    private int $count;

    public function __construct(string $name)
    {
        $this->count = strlen($name);
    }

    public function total(): int
    {
        return $this->count;
    }
}
```

```php
// ✅ Accepted: the field is read in the composed trait surface
trait Values
{
    private int $stored;

    public function value(): int
    {
        return $this->stored;
    }
}

class Record
{
    use Values;

    private int $value;

    public function stored(): int
    {
        return $this->value;
    }
}
```

Reads through a `$this` alias are tracked, but a nested or anonymous class cannot read the outer class's private state, and a read from an unrelated receiver (e.g. `$other->value` on a foreign type) does not protect the field.

Storage released by a later callback remains meaningful even without a value read.
For example, an emergency memory reserve can hold a generated string until a
shutdown callback assigns `null` before reporting an allocation failure. This
uses the storage lifetime; a scalar overwrite such as `$this->count = 0` remains
a write without a read.

## Configuration

```php
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Usage\NoUnusedPrivateState;

return Soda::configure()
    ->withPaths(['src/'])
    ->with([
        new NoUnusedPrivateState(),
    ]);
```

## Related rules

- [unused_methods](unused_methods.md) — the method-level counterpart: private and protected methods that are never called.
- [no_unused_parameters](no_unused_parameters.md) — flags inputs of a callable that are never read, the parameter-level sibling of unread state.
