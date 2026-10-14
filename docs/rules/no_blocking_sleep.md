# no_blocking_sleep

📝 Replace blocking sleep calls with injected time, scheduler, retry, or backoff coordination.

💼 This rule is enabled in the ✅ `standard` config.

## Why

`sleep`, `usleep`, `time_nanosleep`, and `time_sleep_until` block the current process inside application behavior. They hide timing coordination — waiting for a replica, backing off between retries, delaying a job — in a hard-coded wall-clock pause that cannot be observed, tested, or tuned. The rule reports each sleep call and advises injecting a scheduler, clock, retry policy, or backoff port instead.

## Examples

```php
// ❌
final class CheckoutWorkflow
{
    public function checkout(string $order): string
    {
        $receipt = 'payment-'.$order;
        $this->waitForReplica();

        return $receipt;
    }

    private function waitForReplica(): void
    {
        usleep(1);
    }
}

// ✅
final class CheckoutWorkflow
{
    public function __construct(private readonly Backoff $backoff) {}

    public function checkout(string $order): string
    {
        $receipt = 'payment-'.$order;
        $this->backoff->untilReplicaCaughtUp($receipt);

        return $receipt;
    }
}
```

```php
// ❌
public function retry(callable $operation): mixed
{
    try {
        return $operation();
    } catch (RuntimeException) {
        sleep(5);

        return $operation();
    }
}

// ✅
public function retry(callable $operation): mixed
{
    try {
        return $operation();
    } catch (RuntimeException $failure) {
        return $this->retryPolicy->delay($failure)->then($operation);
    }
}
```

## Configuration

```php
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Architecture\NoBlockingSleep;

return Soda::configure()
    ->withPaths(['src/'])
    ->with([
        new NoBlockingSleep(),
    ]);
```

## Related rules

- [no_subprocess_execution](no_subprocess_execution.md) — flags other external effects hidden behind raw PHP calls.
- [no_error_suppression](no_error_suppression.md) — flags failures hidden inside control flow rather than handled explicitly.
