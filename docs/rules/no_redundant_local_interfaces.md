# no_redundant_local_interfaces

📝 Require a type consumer for local interfaces, or declare their external consumers explicitly in contracts.

💼 This rule is enabled in the ✅ `standard` config.

## Why

An `implements` clause alone does not constitute a consumer of a local abstraction: a class can implement an interface no other code ever references as a type, leaving a decorative contract that must be kept in sync with its implementations. The rule scans the whole project for interface declarations and reports those with no reachable type consumer — a parameter type, return type, property, registration call, or PHPDoc type reference. Interfaces consumed by code outside the analyzed source can be listed via the `contracts` constructor argument instead of being deleted, so an exported package contract is not flagged just because its consumers live elsewhere.

A `@mixin` on a class or trait also declares a type consumer through
[documented delegation](https://phpstan.org/writing-php-code/phpdocs-basics#mixins).
Return and parameter types in a class or trait's
[`@method` signature](https://phpstan.org/writing-php-code/phpdocs-basics#magic-methods)
also consume the declared contract. Descriptions after the signature and parameter
default values do not declare types. PHPDoc names resolve through the declaration's
namespace and preceding class imports, including grouped imports. Function and
constant imports do not supply class aliases; template variables shadow class names
only in their lexical declaration scope.
Balanced [PHPDoc types](https://phpstan.org/writing-php-code/phpdoc-types), including
multiline generics, array shapes and callable signatures, retain their type
references. Shape keys, literal values, unfinished types and malformed `class-string`
bounds do not declare a type consumer. Conditional types and external PHPDoc type
aliases remain outside this bounded analysis.
Prose and tags on unrelated declaration kinds do not count. References owned by an
interface remain dependencies; a self-reference alone does not consume it.

A trait's [`@phpstan-require-implements` tag](https://phpstan.org/writing-php-code/phpdocs-basics#enforcing-implementing-an-interface-for-traits)
also consumes its declared interface: classes using the trait must implement that
contract. The existing PHPDoc type and import rules apply. The tag on a class,
function or property does not declare a trait requirement; prose and descriptions
do not supply additional consumers.

The type position in a class-like declaration's
[`@property`, `@property-read`, or `@property-write` tag](https://docs.phpdoc.org/guide/references/phpdoc/tags/property.html)
also consumes a contract. This follows phpDocumentor's retained type envelope,
including tags without a `$`-prefixed property name; it does not prove a runtime
property binding or validate the tag with PHPStan. Property names, descriptions,
defaults and tags attached to functions or native properties do not consume types.

## Examples

```php
// ❌ Implementation and import are not consumption
namespace App;

interface Work
{
    public function run(): void;
}

class Worker implements Work
{
    public function run(): void {}
}
```

```php
// ✅ A parameter or registration reference is a real consumer
namespace App;

interface Work
{
    public function run(): void;
}

function execute(Work $work): void {}
register(Work::class);
```

```php
// ❌ Declared but never consumed outside the analyzed project
namespace Package;

interface Printer
{
    public function print(string $message): void;
}

// ✅ Exported contracts can be declared instead of removed
new NoRedundantLocalInterfaces(contracts: ['Package\Printer']);
```

## Options

| Option | Default | Description |
| --- | --- | --- |
| `contracts` | `[]` | Qualified interface names whose consumers reside outside the analyzed source. Listed interfaces are exempt from reporting. |

## Configuration

```php
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Architecture\NoRedundantLocalInterfaces;

return Soda::configure()
    ->withPaths(['src/'])
    ->with([
        new NoRedundantLocalInterfaces(contracts: ['App\Contracts\External']),
    ]);
```

## Related rules

- [no_empty_local_interfaces](no_empty_local_interfaces.md) — flags decorative marker interfaces, the degenerate case of an unconsumed contract.
- [no_dependency_cycles](no_dependency_cycles.md) — enforces explicit namespace boundaries between the modules that consume contracts.
