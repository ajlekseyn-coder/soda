# Rule challenge fixtures

This application intentionally contains code that violates Soda's rules. It is
used by the regression suite to check standard-rule selection, individual
findings and legitimate counterexamples.

## Run the application

From the repository root with Composer dependencies installed:

```sh
php demo-app/run.php
```

## Compare rule selections

```sh
php soda quality demo-app --exclude=iterations --config=demo-app/baseline-soda.php
php soda quality demo-app --exclude=iterations --config=demo-app/soda.php
```

The application should execute successfully; quality checks can exit with status
1 because the violations are intentional. `rule-challenges.php` maps rules to
challenge cases. The numbered PHP files in `iterations/` are executable fixtures,
not examples of recommended application design.
