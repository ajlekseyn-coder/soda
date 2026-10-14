# no_global_state

📝 Reports `global` statements and `$GLOBALS` access.

💼 This rule is enabled in the ✅ `standard` config.

## Why

Global variables hide their inputs and outputs from every signature: any function can read or mutate them, behavior depends on what ran before, and tests must reset process state to stay independent. The rule reports `global` declarations and `$GLOBALS` dimension fetches and advises passing state explicitly through arguments or an injected state owner instead.

## Examples

```php
// ❌
$events = [];

function rememberDomainEvent(string $event): void
{
    global $events;
    $events[] = $event;
}

// ✅ State owned by an injected object
final class EventRecorder
{
    /** @var list<string> */
    private array $events = [];

    public function remember(string $event): void
    {
        $this->events[] = $event;
    }
}
```

```php
// ❌
$GLOBALS['settings']['cache'] = true;

// ✅ Pass the state explicitly
public function apply(array $settings): void
{
    $this->settings = array_replace($this->settings, $settings);
}
```

## Configuration

```php
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Architecture\NoGlobalState;

return Soda::configure()
    ->withPaths(['src/'])
    ->with([
        new NoGlobalState(),
    ]);
```

## Related rules

- [no_static_mutable_properties](no_static_mutable_properties.md) — flags static storage, the class-scoped variant of shared mutable state.
- [no_environment_reads](no_environment_reads.md) — flags `$_ENV`/`$_SERVER`, another process-wide input channel.
