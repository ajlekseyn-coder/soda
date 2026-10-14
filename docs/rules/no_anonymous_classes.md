# no_anonymous_classes

📝 Promote anonymous classes to named, independently testable types.

💼 This rule is enabled in the ✅ `standard` config.

## Why

An anonymous class hides its responsibility: it has no name to search for, no namespace to belong to, and cannot be reused or tested in isolation. Over time these one-off definitions accumulate hidden behavior that the project structure does not reflect. The rule reports every anonymous class declaration and advises giving the behavior a named type instead.

Anonymous classes that follow the Laravel migration convention are excluded: they must extend `Illuminate\Database\Migrations\Migration` and declare public, parameterless `up()` and `down()` hooks. A migration missing one of the hooks is still reported.

## Examples

```php
// ❌
$handler = new class implements Handler
{
    public function apply(string $value): string
    {
        return strtoupper($value);
    }
};

// ✅
final class UppercaseHandler implements Handler
{
    public function apply(string $value): string
    {
        return strtoupper($value);
    }
}

$handler = new UppercaseHandler();
```

```php
// ✅ Accepted: Laravel-style anonymous migrations declare both lifecycle hooks
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', fn (Blueprint $table) => $table->id());
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
```

## Configuration

```php
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Architecture\NoAnonymousClasses;

return Soda::configure()
    ->withPaths(['src/'])
    ->with([
        new NoAnonymousClasses(),
    ]);
```

## Related rules

- [max_classes_per_file](max_classes_per_file.md) — keeps named types from piling up in one file when responsibilities are promoted out of anonymous classes.
- [no_dynamic_properties](no_dynamic_properties.md) — flags the other common way of hiding an object's true shape.
