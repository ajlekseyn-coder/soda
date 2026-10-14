# Frozen rule fixtures

This directory contains detector snapshots and pinned corpus records used by
`ExplicitBehaviorCandidatesTest`. Use the production
[NoTrivialFactories](../../rules/no_trivial_factories.md) and
[NoRepeatedCompoundConditions](../../rules/no_repeated_compound_conditions.md)
checks for current configuration, examples and boundaries.

The snapshots are outside production autoload and discovery. `manifest.json`
pins their hashes so recorded measurements remain reproducible. Corpus counts
apply to those snapshots and do not establish precision of today's checks.

## Run the tests

From the repository root:

```sh
vendor/bin/phpunit tests/unit/ExplicitBehaviorCandidatesTest.php
```

## Reproduce the corpus

Provide `monolog`, `symfony-console` and `guzzle` source checkouts under a donor
directory at the commits in `manifest.json`. The Soda input is this checkout's
`src`; `results.json` records its source tree hash. Donor roots and exclusions
are defined in the manifest and harness. No donor PHP code is executed.

```sh
php docs/research/explicit-behavior/corpus.php discovery /tmp/soda-corpus /tmp/soda-explicit-results
php docs/research/explicit-behavior/corpus.php held-out /tmp/soda-corpus /tmp/soda-explicit-results
python3 docs/research/explicit-behavior/select_review.py /tmp/soda-explicit-results /tmp/selected.json
```

The harness rejects changed detector hashes. Compare tree hashes before treating
a rerun as the same corpus. Selection uses deterministic SHA-256 ordering.
`nonfinding-review.json` stores source hashes and brief excerpts from the pinned
Soda, Monolog, Symfony Console and Guzzle versions; upstream licenses continue
to apply. Synthetic regression cases establish detector behavior, not overall
precision or usefulness on application code.
