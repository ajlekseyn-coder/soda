# fragmented_owner_behavior

📝 Finds several stateless operation classes that all interpret or change the same local owner, and suggests moving that behavior onto the owner.

💼 This rule is enabled in the ✅ `standard` config.

## Why

Several small classes that each take an owner object as their single parameter can look clean in isolation, but understanding one owner's behavior requires navigating all of them. The rule reports this fragmentation as a review signal and advises keeping operations with the state they act on, optionally organizing a theme with a concern (trait). Classes with their own contract, context, or lifetime — policies, services with dependencies — should stay separate.

The detector only reports when it can prove each participant: an eligible local owner, stateless operation classes with exactly one typed owner parameter, bodies that write to or decide over public members of that owner, and at least `minClasses` distinct operation classes actually invoked with that owner at direct, fully typed call sites. Mere forwarding and any extra context are excluded.

## Examples

```php
// ❌ three used stateless operation classes over one owner
final class Post
{
    public bool $published = false;
    public bool $deleted = false;
}

final class Publisher
{
    public function publish(Post $post): void
    {
        $post->published = true;
    }
}

final class Unpublisher
{
    public function unpublish(Post $post): void
    {
        $post->published = false;
    }
}

final class PublicationChecker
{
    public function check(Post $post): bool
    {
        return $post->published && ! $post->deleted;
    }
}

// Each operation must really be invoked with the owner at a direct, fully typed call site:
function run(Post $post, Publisher $publisher, Unpublisher $unpublisher, PublicationChecker $checker): void
{
    $publisher->publish($post);
    $unpublisher->unpublish($post);
    $checker->check($post);
}

// ✅ the behavior lives on the owner
final class Post
{
    public function publish(): void
    {
        $this->published = true;
    }

    public function unpublish(): void
    {
        $this->published = false;
    }

    public function isPubliclyVisible(): bool
    {
        return $this->published && ! $this->deleted;
    }
}
```

Operation classes with state, constructor effects, extra parameters, or unknown member accesses are never reported. The rule also requires the owner type itself to be declared in the analyzed paths, and it fires only from `minClasses` (default `3`) distinct operation classes that are actually invoked with that owner — the operation classes alone, without the declaration and the call sites, produce no report.

## Options

| Option | Default | Description |
| --- | --- | --- |
| `minClasses` | `3` | Minimum number of used stateless operation classes over one owner before the rule reports. Must be at least `2`. |

## Configuration

```php
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Design\FragmentedOwnerBehavior;

return Soda::configure()
    ->withPaths(['src/'])
    ->with([
        new FragmentedOwnerBehavior(minClasses: 3),
    ]);
```

## Related rules

- [repeated_foreign_state_decision](repeated_foreign_state_decision.md) — the same kind of owner logic repeated as an expression instead of a named predicate.
- [repeated_foreign_state_update](repeated_foreign_state_update.md) — the same kind of owner logic repeated as an arithmetic update instead of a named operation.
- [no_trivial_delegating_classes](no_trivial_delegating_classes.md) — flags classes that only forward calls, a milder form of behavior living outside its owner.
