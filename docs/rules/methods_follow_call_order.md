# methods_follow_call_order

📝 Flags private helpers declared in an order that contradicts the order their sole caller uses them.

💼 This rule is enabled in the ✅ `standard` config.

## Why

When a method calls two private helpers in sequence, readers expect the declarations to appear in that same order, top-down. When a helper is declared after a later step, the reading flow jumps back and forth. The rule compares each consecutive pair of local calls with the declaration order of the called private methods and reports helpers used exclusively by one direct local sequence that appear out of order. Preserve public API grouping, shared helpers and extension points: public and protected methods are never reported, contradictory or cyclic orderings are ignored, and helpers used by several callers are left alone.

## Examples

```php
// ❌ method1 is called first but declared after method2
final class SomeClass
{
    public function someMethod(): void
    {
        $this->method1();
        $this->method2();
    }

    private function method2(): void {}

    private function method1(): void {}
}
```

```php
// ✅ declarations follow the call sequence
final class SomeClass
{
    public function someMethod(): void
    {
        $this->method1();
        $this->method2();
    }

    private function method1(): void {}

    private function method2(): void {}
}
```

```php
// ✅ shared helpers used by several callers keep any order
final class Workflow
{
    public function first(): void
    {
        $this->prepare();
        $this->commit();
    }

    public function second(): void
    {
        $this->prepare();
        $this->commit();
    }

    private function commit(): void {}

    private function prepare(): void {}
}
```

## Configuration

```php
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Structure\MethodsFollowCallOrder;

return Soda::configure()
    ->withPaths(['src/'])
    ->with([
        new MethodsFollowCallOrder(),
    ]);
```

## Related rules

- [max_method_length](max_method_length.md) — encourages extracting named steps, which this rule then orders for reading.
- [max_cognitive_complexity](max_cognitive_complexity.md) — bounds the control flow that makes long methods hard to read top-down.

