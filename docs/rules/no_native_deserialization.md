# no_native_deserialization

📝 Reports `unserialize()` calls.

💼 This rule is enabled in the ✅ `standard` config.

## Why

`unserialize` constructs arbitrary objects straight from raw bytes: the types instantiated, and the constructors run, are decided by the payload rather than by code, which is both a security hazard and a hidden construction path. The rule reports each call and advises decoding validated scalar data and constructing allowed types explicitly instead.

## Examples

```php
// ❌
final class LegacyEnvelopeCodec
{
    public function decode(string $raw): mixed
    {
        return unserialize($raw);
    }
}

// ✅ Decode validated data, construct allowed types explicitly
final class EnvelopeCodec
{
    public function decode(string $raw): Envelope
    {
        $payload = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);

        return new Envelope((string) $payload['message']);
    }
}
```

```php
// ❌
$cache = unserialize($cachedEntry);

// ✅
$cache = CacheEntry::fromArray(json_decode($cachedEntry, true, flags: JSON_THROW_ON_ERROR));
```

## Configuration

```php
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Architecture\NoNativeDeserialization;

return Soda::configure()
    ->withPaths(['src/'])
    ->with([
        new NoNativeDeserialization(),
    ]);
```

## Related rules

- [no_dynamic_properties](no_dynamic_properties.md) — flags undeclared object shapes, which deserialized objects typically have.
- [no_runtime_includes](no_runtime_includes.md) — flags the other mechanism that smuggles executable content into the process.
