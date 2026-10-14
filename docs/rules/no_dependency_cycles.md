# no_dependency_cycles

📝 Detects module dependency cycles and required constructor cycles, with concrete paths and file evidence.

💼 This rule is enabled in the ✅ `standard` config.

## Why

Two modules that depend on each other cannot be evolved, tested, or extracted independently: every change inside one can force changes in the other, and the coupling is invisible until you trace it by hand. The rule builds a module graph from actual resolved type dependencies between local classes, finds strongly connected components, and reports each cycle with the specific classes and files that prove every edge. It advises removing the mutual dependency or configuring namespace prefixes that make the module boundaries explicit.

A second project graph checks mandatory constructor dependencies inside a module.
`Checkout` may require an existing `Stock`, while `Stock` requires an existing
`Checkout`: neither can be the first normally constructed object. A long cycle
can span several files even though each constructor looks reasonable alone.
The diagnostic names every parameter and source location on a real closed path;
`value` is the number of types in the cyclic component and `threshold` is zero.
Self-dependencies also qualify.

Construction edges require named local final classes, no parent or trait
composition, and an explicitly declared public constructor. Only mandatory
non-reference, non-variadic parameters with a single non-nullable class type
qualify; `self` resolves to its declaring type. Both ends must have exact
construction facts. Interfaces, non-final or abstract types, inheritance,
nullable/union types, defaults, factories and unresolved external types are
outside this proof. This boundary does not recommend adding `final` to classes.

Constructor components spanning several modules are covered by the module-cycle
finding and do not produce a duplicate finding. Configured module prefixes also
limit the construction graph. Facts are scalars retained across files; no AST or
application class is loaded during project analysis. Duplicate declarations
across files are excluded from construction evidence.

Review whether the two collaborators are really one responsibility or whether
the reverse dependency can be removed. Do not create an interface, service
locator or nullable parameter merely to conceal the cycle. Reflection-based
construction, serialization and custom container bindings are not simulated;
the finding describes declared ordinary construction, not every runtime path.

## Examples

```php
// ❌ Sales depends on Billing and Billing depends on Sales
namespace Sales;

use Billing\Invoice as Bill;

class Order
{
    public function pay(Bill $bill): void {}
}

namespace Billing;

class Invoice
{
    public function order(\Sales\Order $order): void {}
}
```

```php
// ✅ One-way dependency
namespace Sales;

class Order
{
    public function pay(\Billing\Invoice $bill): void {}
}

namespace Billing;

class Invoice {}
```

```php
// ❌ Nested namespaces refer to each other; without configured
// prefixes each exact namespace is its own module
namespace App\Sales\Orders;

class Order
{
    public function run(\App\Sales\Items\Item $item): void {}
}

namespace App\Sales\Items;

class Item
{
    public function run(\App\Sales\Orders\Order $order): void {}
}
```

```php
// ✅ Accepted: both namespaces belong to the App\Sales module
// when the rule is constructed with ['App\Sales']
```

```php
// ❌ same-module constructor cycle: neither object can be created first
namespace App;

final class Checkout
{
    public function __construct(Stock $stock) {}
}

final class Stock
{
    public function __construct(Checkout $checkout) {}
}
```

```php
// ✅ the collaboration has one construction direction
namespace App;

final class Checkout
{
    public function __construct(Stock $stock) {}
}

final class Stock
{
    public function __construct() {}
}
```

## Options

- `modules` (default `[]`) — list of namespace prefixes that group classes into modules. The longest matching prefix wins. Without prefixes, every exact namespace is treated as its own module and classes in the global namespace belong to `<global>`.

## Configuration

```php
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Architecture\NoDependencyCycles;

return Soda::configure()
    ->withPaths(['src/'])
    ->with([
        new NoDependencyCycles(['App\Sales', 'App\Billing']),
    ]);
```

## Related rules

- [forbidden_dependencies](forbidden_dependencies.md) — enforces one-way dependency direction between configured modules.
- [max_dependencies](max_dependencies.md) — caps the number of dependencies a single class may have.
