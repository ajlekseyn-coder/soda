# repeated_configuration_sequence

📝 Finds the same fresh-object setup sequence repeated across consumers and suggests a fluent object or named construction that expresses the scenario.

💼 This rule is enabled in the ✅ `standard` config.

## Why

Mechanical setter chains such as construct, `setPeriod()`, `setFormat()`, `download()` hide a scenario behind repeated boilerplate, and every new consumer must copy the exact call order to stay correct. The rule reports a setup sequence as a review signal and advises a scenario-oriented fluent API or named constructor, while preserving the argument evaluation order and an explicit execution boundary.

A sequence only qualifies when the same ordered method sequence appears in `minOccurrences` distinct consumers, with at least `minConfigurationCalls` configuration steps followed by one public non-configuration, non-getter operation. Configuration methods must write only their own inputs or simple literals to declared owner fields, arguments must be simple values with exact declared counts, and construction plus calls must occur in one contiguous statement list. Aliases, escaping receivers, computed or unpacked arguments, unknown calls, and branch boundaries all exclude the sequence — and an already fluent chain is accepted as compliant.

## Examples

```php
// ❌
function daily(string $period): string
{
    $report = new Report;
    $report->setPeriod($period);
    $report->setFormat('csv');

    return $report->download();
}

// ✅
function daily(string $period): string
{
    return Report::for($period)->asCsv()->download();
}
```

A single consumer, a one-call setup, or a sequence where the receiver escapes mid-way (`consume($r)` between setters) is not reported; the detector cannot prove a scenario from one occurrence. The ❌ shape is reported only when the same ordered sequence repeats in `minOccurrences` (default `3`) distinct consumers across the analyzed paths, and the `Report` type itself is declared there — the single consumer shown above is the repeating unit, not a complete triggering example.

## Options

| Option | Default | Description |
| --- | --- | --- |
| `minOccurrences` | `3` | Minimum distinct consumers repeating the same ordered setup scenario. Must be at least `2`. |
| `minConfigurationCalls` | `2` | Minimum state-only setup calls before the final execution operation. Must be at least `2`. |

## Configuration

```php
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Design\RepeatedConfigurationSequence;

return Soda::configure()
    ->withPaths(['src/'])
    ->with([
        new RepeatedConfigurationSequence(minOccurrences: 3, minConfigurationCalls: 2),
    ]);
```

## Related rules

- [methods_follow_call_order](methods_follow_call_order.md) — keeps declared method order aligned with the execution order a fluent API would encode.
- [no_trivial_factories](no_trivial_factories.md) — flags factories that add no value over direct construction, the inverse side of construction design.
- [max_ask_then_tell_patterns](max_ask_then_tell_patterns.md) — bounds ask-then-tell usage, another shape of driving an object from the outside.
