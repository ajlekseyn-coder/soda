# no_dependency_cycles

📝 Detects mutual dependencies between namespace modules and reports the concrete cycle path with file evidence.

💼 This rule is enabled in the ✅ `standard` config.

## Why

Two modules that depend on each other cannot be evolved, tested, or extracted independently: every change inside one can force changes in the other, and the coupling is invisible until you trace it by hand. The rule builds a module graph from actual resolved type dependencies between local classes, finds strongly connected components, and reports each cycle with the specific classes and files that prove every edge. It advises removing the mutual dependency or configuring namespace prefixes that make the module boundaries explicit.

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
