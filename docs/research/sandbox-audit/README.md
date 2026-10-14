# Sandbox corpus fixtures

These records pin the Sandbox source files and reviewed diagnostics used by
`SandboxAuditCorpusTest`. They describe that source version and rule selection,
not the latest upstream project.

- `sources.json`: file paths, SHA-256 hashes and the parent Eloquent contract.
- `findings.json`: diagnostic records and source evidence.
- `resolved.json`: corrected false positives and their regression coverage.
- `policy-changes.json`: findings removed by a change of standard policy.
- `module-edges.json`: type references supporting namespace dependency cycles.

## Reproduce

Provide the exact Sandbox checkout described by `sources.json`. From the
repository root:

```sh
SODA_SANDBOX_ROOT=/absolute/sandbox \
  vendor/bin/phpunit --filter SandboxAuditCorpusTest
```

The test verifies the file set, source hashes and diagnostic records. Without the
checkout, only the external-corpus comparison is explicitly skipped; record
integrity and standalone regression cases still run. Findings in this corpus
are expected policy violations, so a nonzero quality result is not itself a test
failure.

See [Laravel corpus fixtures](../laravel-audit/README.md) for package mappings and
[Analysis evidence](../../ANALYSIS_EVIDENCE.md) for evidence requirements.
