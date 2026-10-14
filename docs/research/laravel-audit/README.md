# Laravel corpus fixtures

The JSON files pin six Laravel packages and the diagnostics reviewed for their
recorded versions. They support `LaravelAuditCorpusTest` and the standalone
regression tests; they are not a report on current upstream branches.

- `packages.json`: source versions, commits, file paths and SHA-256 hashes.
- `findings.json`: diagnostic records and source evidence.
- `resolved.json`: corrected false positives and their regression coverage.
- `policy-changes.json`: findings removed by a change of standard policy.
- `sandbox-recheck.json`: additional Sandbox review records.

## Reproduce

Prepare a JSON object mapping each package name in `packages.json` to its local
source root. Use the exact recorded versions; tests verify source hashes before
comparing diagnostic results. External source code is read, not executed.

From the repository root:

```sh
SODA_LARAVEL_CORPUS=/absolute/corpus-roots.json \
  vendor/bin/phpunit --filter LaravelAuditCorpusTest
```

Without the mapping, the external-corpus comparison is explicitly skipped.
Record-integrity checks and standalone regression tests still run normally.
Nonzero quality results are expected for fixtures that violate enabled policies.

See [Analysis evidence](../../ANALYSIS_EVIDENCE.md) for evidence requirements and
[Sandbox corpus fixtures](../sandbox-audit/README.md) for the other source set.
