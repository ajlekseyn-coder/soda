--TEST--
soda quality --help
--FILE--
<?php declare(strict_types=1);
// Vendor packages resolved by --prefer-lowest emit PHP 8.4+ implicitly-nullable
// deprecations while loading; they are noise for CLI output comparison.
error_reporting(E_ALL & ~E_DEPRECATED);
require __DIR__ . '/../../vendor/autoload.php';

$_SERVER['argv'] = ['soda', 'quality', '--help'];

require __DIR__ . '/../../soda';
--EXPECTF--
%ADescription:
  Analyse code quality and check against configured thresholds

Usage:
  quality [options] [--] [<path>...]

Arguments:
%Apath%A

Options:
%A--config%A
%A--report-json%AWrite quality report to JSON file%A
