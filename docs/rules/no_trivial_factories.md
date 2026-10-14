# no_trivial_factories

📝 Reports methods whose only work is constructing an object and forwarding all parameters unchanged.

💼 This rule is enabled in the ✅ `standard` config.

## Why

A factory method that only maps its parameters onto a `new` expression adds a name between the caller and the constructor without adding creation logic: callers must learn the wrapper while losing direct access to named arguments and the constructor's real signature. The rule reports methods that return a freshly constructed concrete class with every parameter forwarded unchanged and no other statements. Consider direct construction; preserve parameter and return type contracts, named arguments and useful creation boundaries.

## Examples

```php
// ❌ the method adds nothing to the constructor call
final class ReceiptFactory
{
    public function create(int $amount, string $currency): Receipt
    {
        return new Receipt($amount, $currency);
    }
}

$receipt = $factory->create(1000, 'EUR');
```

```php
// ✅ the caller constructs the object directly
final class Checkout
{
    public function complete(Cart $cart): Receipt
    {
        $receipt = new Receipt($cart->amount(), $cart->currency());
        $this->repository->save($receipt);

        return $receipt;
    }
}
```

```php
// ✅ accepted: the method transforms input before construction
final class ReceiptFactory
{
    public function create(int $amount, string $currency): Receipt
    {
        return new Receipt($amount * 100, strtoupper($currency));
    }
}
```

## Options

- `contracts` (default `[]`): list of qualified `Class::method` names whose published factory API must remain available; matching methods are exempt regardless of case. Framework-recognized creation hooks are exempt automatically.

## Configuration

```php
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Structure\NoTrivialFactories;

return Soda::configure()
    ->withPaths(['src/'])
    ->with([
        new NoTrivialFactories(),
    ]);
```

## Related rules

- [no_trivial_delegating_classes](no_trivial_delegating_classes.md) — flags whole classes that only forward operations to a single dependency.
- [max_arguments](max_arguments.md) — bounds the parameter lists that transparent factories mirror.

