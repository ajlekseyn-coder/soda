# Explicit behavior candidates

This directory contains frozen detector snapshots and corpus records used by
`ExplicitBehaviorCandidatesTest`. The production checks are enabled by default;
use [NoTrivialFactories](../../rules/no_trivial_factories.md) and
[NoRepeatedCompoundConditions](../../rules/no_repeated_compound_conditions.md)
for current configuration, examples and boundaries.

The snapshots are outside production autoload and discovery. Their hashes are
pinned in `manifest.json` so corpus measurements remain reproducible. Historical
counts describe those snapshots and do not establish precision of today's checks.

## Run the tests

```sh
vendor/bin/phpunit tests/unit/ExplicitBehaviorCandidatesTest.php
```

## Frozen experimental snapshots

From a Soda checkout, a PHP configuration can load the candidates manually:

```php
require_once __DIR__.'/docs/research/explicit-behavior/NoTrivialFactories.php';
require_once __DIR__.'/docs/research/explicit-behavior/NoRepeatedCompoundConditions.php';

return \Cosmira\Soda\Config\Soda::configure()->withPaths(['src/'])->with([
    new \Cosmira\Soda\Research\ExplicitBehavior\NoTrivialFactories(),
    new \Cosmira\Soda\Research\ExplicitBehavior\NoRepeatedCompoundConditions(minMethods: 3),
]);
```

Save as a separate experimental config and run `php soda --config=experimental.php`.
These classes are intentionally outside production autoload and discovery.
An enabled finding uses the usual exit status 1 and JSON schema 4. No autofix.

## Reproduce the corpus

Provide `monolog`, `symfony-console` and `guzzle` checkouts under a donor directory
at the commits in `manifest.json`. The Soda input is this checkout's `src`;
`results.json` records its working-tree hash as well as its HEAD. Donor roots and
excluded directories are in the manifest and harness. No donor code is executed.

```sh
php docs/research/explicit-behavior/corpus.php discovery /tmp/soda-corpus /tmp/soda-explicit-results
php docs/research/explicit-behavior/corpus.php held-out /tmp/soda-corpus /tmp/soda-explicit-results
python3 docs/research/explicit-behavior/select_review.py /tmp/soda-explicit-results /tmp/selected.json
```

The harness refuses changed detector hashes. Compare project tree hashes in its
output against `results.json` before treating a rerun as the same corpus.
Selection takes five classes per project and candidate by deterministic SHA-256
order, filling any shortage from the remaining project/hash order. The full
lookalike methods go to temporary output; the committed review stores brief
excerpts and source hashes. The excerpts are from the identified Soda, Monolog,
Symfony Console and Guzzle versions; upstream licenses continue to apply.

The committed review is one assistant's inspection through DHH and Otwell's
published principles, not their participation or an independent expert panel.
Zero findings means no before/after corpus finding could be reviewed; executable
synthetic examples cover detector behavior only. Many selected nonfindings are
broad lookalikes rather than near misses. More application code is needed before
considering admission, and the original frozen run must remain distinguishable
from any later algorithm revision.
