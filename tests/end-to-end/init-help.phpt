--TEST--
soda init --help
--FILE--
<?php declare(strict_types=1);
// Vendor packages resolved by --prefer-lowest emit PHP 8.4+ implicitly-nullable
// deprecations while loading; they are noise for CLI output comparison.
error_reporting(E_ALL & ~E_DEPRECATED);
require __DIR__ . '/../../vendor/autoload.php';

$_SERVER['argv'] = ['soda', 'init', '--help'];

require __DIR__ . '/../../soda';
--EXPECTF--
%ADescription:
  Create soda.php with default quality rules

Usage:
  init

Options:
%A--help%A
