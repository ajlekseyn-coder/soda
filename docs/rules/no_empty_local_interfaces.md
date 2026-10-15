# no_empty_local_interfaces

📝 Reports local interfaces whose complete declared and inherited contract has no methods or properties.

💼 This rule is enabled in the ✅ `standard` config.

## Why

A marker interface that carries no methods or properties and inherits none adds a name without a behavioral contract. The rule reports it only when every reachable ancestor is known to be empty. Constants and attributes do not establish a behavioral contract; PHP 8.4 interface properties do. Interfaces required by external protocols can be exempted by name.

Inherited declarations are resolved across analyzed files, available Composer PSR-4 sources and installed dependencies. Dependency source and Composer JSON metadata are parsed without executing source files or autoloaders. Loaded internal PHP interfaces expose native contracts. Unknown ancestors, mismatched declarations and incomplete inheritance do not prove emptiness and produce no finding. An entirely known empty inheritance cycle still has no contract.

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
// ✅ Throwable supplies an inherited method contract
interface FailoverableException extends \Throwable {}

// ✅ An unresolved ancestor does not prove an empty contract
interface Response extends ExternalProtocol {}

// ✅ A property contract is behavioral even without methods (PHP 8.4)
interface Named
{
    public string $name { get; }
}
```

## Options

- `protocols` (default `[]`) — qualified marker interface names required by an explicitly named external protocol. These are exempt from the empty-contract restriction, both when declared locally and when referenced as an unknown parent.

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
