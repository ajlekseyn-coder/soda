# Readability corpus fixtures

`results.json` records contextual and strict rule counts for the package sources
pinned in [packages.json](../laravel-audit/packages.json). `ReadabilityCorpusTest`
compares those counts after verifying versions, commits and source hashes.
These measurements apply to the recorded source and policy versions; they do not
measure overall readability or precision on arbitrary application code.

Current rule options and boundaries are described in
[Expressions and local state](../../EXPRESSION_AND_LOCAL_STATE_RULES.md).
Standalone examples live in `tests/fixtures/readability/`; their tests run without
external packages.

## Reproduce

Prepare a JSON object mapping all package names in `packages.json` to local roots
of their exact source versions. From the repository root:

```sh
SODA_READABILITY_CORPUS=/absolute/corpus-roots.json \
  vendor/bin/phpunit --filter ReadabilityCorpusTest
```

Without this mapping, the external-corpus test is explicitly skipped. Source
packages are only read and analyzed; their PHP files are not executed.
