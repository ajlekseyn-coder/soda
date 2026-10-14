# no_empty_local_interfaces

📝 Reports local interfaces that declare no methods and inherit no known method contract.

💼 This rule is enabled in the ✅ `standard` config.

## Why

A marker interface that carries no methods and inherits none adds a name to the codebase without a contract: consumers cannot rely on it for anything except `instanceof` checks, and the intent ("attachable", "exportable") stays implicit. The rule reports every local interface with no declared or inherited method contract and advises removing the marker or declaring the required protocol explicitly. Interfaces extending external protocols can be exempted by name.

## Examples

```php
// ❌ Decorative marker: only a constant, no method contract
namespace App;

#[Boundary]
interface Marker
{
    public const LABEL = 'marker';
}

// ✅ Declare the required protocol explicitly
interface Marker
{
    public function attach(Listener $listener): void;
}
```

```php
// ❌ The parent is not part of the analyzed project, so no
// method contract is known
interface Marker extends ExternalProtocol {}

// ✅ Accepted once the external protocol is declared explicitly
// in configuration: new NoEmptyLocalInterfaces(['ExternalProtocol'])
interface Marker extends ExternalProtocol {}
```

## Options

- `protocols` (default `[]`) — qualified marker interface names required by an explicitly named external protocol. These are exempt from the local method-contract requirement, both when declared locally and when referenced as an unknown parent.

## Configuration

```php
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Architecture\NoEmptyLocalInterfaces;

return Soda::configure()
    ->withPaths(['src/'])
    ->with([
        new NoEmptyLocalInterfaces(['ExternalProtocol']),
    ]);
```

## Related rules

- [no_redundant_local_interfaces](no_redundant_local_interfaces.md) — flags local interfaces that have no consumer at all.
- [forbidden_dependencies](forbidden_dependencies.md) — enforces dependency direction between the modules an interface connects.
