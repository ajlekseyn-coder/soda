# max_ask_then_tell_patterns

📝 Reports code that queries an object's state and then commands the same object based on the answer.

💼 This rule is enabled in the ✅ `standard` config.

## Why

Asking an object for a fact and then deciding what to do with it moves the object's policy into its caller. The decision lives in the wrong place, every new caller repeats it, and the object cannot protect its own invariants. The rule reports a query on a receiver followed by a command on the same receiver — in an `if`, a ternary, a short-circuit or a loop — and advises telling the object what outcome you want instead of querying its state and deciding for it.

The pattern is only reported when the question and the command target the same receiver; branching on one object to act on another is a different concern. Objects deciding on their own state (`$this->isStale()` then `$this->destroy()`) are accepted.

## Examples

```php
// ❌ the caller owns the Customer's activation policy
final class Order
{
    public function finalize(): void
    {
        if ($this->customer->isActive()) {
            $this->customer->notify();
        }
    }
}

// ✅ the object applies its own policy
final class Order
{
    public function finalize(): void
    {
        $this->customer->notifyIfActive();
    }
}
```

```php
// ❌ query-then-command through a ternary
function settle(Account $account): void
{
    $account->isVerified() ? $account->debit(100) : $account->notifyUnverified();
}
```

```php
// ✅ accepted: the command targets a different object
if ($customer->isActive()) {
    $logger->info('active customer');
}
```

## Configuration

```php
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Structure\NoAskThenTellPatterns;

return Soda::configure()
    ->withPaths(['src/'])
    ->with([
        new NoAskThenTellPatterns(),
    ]);
```

## Related rules

- [repeated_foreign_state_decision](repeated_foreign_state_decision.md) — flags a decision made repeatedly on state owned by another object.
- [fragmented_owner_behavior](fragmented_owner_behavior.md) — detects behavior scattered away from the owner of the state it uses.
- [no_trivial_delegating_classes](no_trivial_delegating_classes.md) — flags the opposite shape: wrappers that add no decision at all.
