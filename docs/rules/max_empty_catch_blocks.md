# max_empty_catch_blocks

📝 Reports catch blocks that silently discard the caught exception.

💼 This rule is enabled in the ✅ `standard` config.

## Why

An empty catch block swallows a failure without translating it into anything the caller can observe: errors vanish, state becomes inconsistent, and debugging turns into guesswork. The rule reports each catch block whose body discards the exception. Handle the failure, translate it to a domain outcome, or document explicitly why ignoring it is safe.

## Examples

```php
// ❌ the failure disappears without a trace
public function sync(Account $account): void
{
    try {
        $this->gateway->sync($account);
    } catch (GatewayUnavailable $exception) {
    }
}
```

```php
// ✅ the failure is handled or explicitly accepted
public function sync(Account $account): void
{
    try {
        $this->gateway->sync($account);
    } catch (GatewayUnavailable $exception) {
        $this->logger->warning('Sync deferred', ['account' => $account->id()]);
        $this->scheduleRetry($account);
    }
}
```

## Configuration

```php
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Structure\NoEmptyCatchBlocks;

return Soda::configure()
    ->withPaths(['src/'])
    ->with([
        new NoEmptyCatchBlocks(),
    ]);
```

## Related rules

- [no_catch_all_exceptions](no_catch_all_exceptions.md) — flags catching the exception hierarchy root, which hides failures the same way.
- [no_empty_finally_blocks](no_empty_finally_blocks.md) — flags the other finally/catch construct that adds noise without behavior.
- [max_try_catch_blocks](max_try_catch_blocks.md) — bounds the number of try/catch blocks per callable.

