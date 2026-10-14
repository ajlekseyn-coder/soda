# Soda Documentation

Requires PHP 8.2 or higher. Install and check a directory without creating a
configuration first:

```bash
composer require --dev cosmira/soda
vendor/bin/soda quality src/
```

For daily use, put paths and named rules in `soda.php`, then run `vendor/bin/soda`.
`vendor/bin/soda init` optionally generates an expanded starting configuration.
Use `vendor/bin/soda list:rules` to inspect rules and their defaults.
Inside this repository, use `php soda` instead of `vendor/bin/soda`.

## Getting started

| Page | What it covers |
|------|----------------|
| [Configuration](SODA_PHP_CONFIG.md) | Paths, named checks and the root `soda.php`. |
| [Rule catalog](rules/README.md) | Individual rules, examples and options. |
| [JSON reports](QUALITY_REPORT_JSON.md) | Machine-readable findings and exit status. |
| [Migration](MIGRATION.md) | Previous package names, namespaces and extension contracts. |

## Rule references

| Page | What it covers |
|------|----------------|
| [Structural metrics](STRUCTURAL_METRICS.md) | Size, coupling, namespace and structural checks. |
| [Complexity](COMPLEXITY_READABILITY_METRICS.md) | Branches, nesting, returns and exception handling. |
| [Expressions and local state](EXPRESSION_AND_LOCAL_STATE_RULES.md) | Ternaries, assignments, parameter rebinding and repeated strings. |
| [Owner behavior](OWNER_BEHAVIOR_RULES.md) | Concerns, owner methods and fluent API recommendations. |
| [Naming](NAMING_RULES.md) | Identifier lengths, redundant names and boolean method naming. |

## Extending Soda

| Page | What it covers |
|------|----------------|
| [Custom checks and rule sets](PLUGINS.md) | Named PHP checks and reusable arrays of checks. |
| [Rule expressions](RULE_EXPRESSIONS.md) | Compound conditions compiled by phplrt. |
| [Adding a quality rule](ADDING_A_QUALITY_RULE.md) | Implementation, catalog metadata and verification. |
| [Project flow](PROJECT_FLOW.md) | Parsing, fact collection, checks and reporting. |
| [Laravel-style architecture](LARAVEL_STYLE_ARCHITECTURE.md) | Public API and module boundaries. |

## Design and evidence

| Page | What it covers |
|------|----------------|
| [Practical design](DESIGN_PHILOSOPHY.md) | Concerns, fluent APIs, value objects and executable examples. |
| [Analysis evidence](ANALYSIS_EVIDENCE.md) | What supports a finding and how uncertainty is handled. |
| [Representation consistency](REPRESENTATION_CONSISTENCY.md) | Conversion boundaries and review of connected data flows. |
| [Research metrics](RESEARCH_METRICS.md) | Cognitive complexity, cohesion and their limits. |

The `research/` directory contains pinned corpus data, frozen detector snapshots
and reproduction tools used by tests. Its historical measurements describe the
recorded versions; current behavior is documented in the rule references above.
