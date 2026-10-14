--TEST--
soda list shows all commands
--FILE--
<?php declare(strict_types=1);
// Vendor packages resolved by --prefer-lowest emit PHP 8.4+ implicitly-nullable
// deprecations while loading; they are noise for CLI output comparison.
error_reporting(E_ALL & ~E_DEPRECATED);
require __DIR__ . '/../../vendor/autoload.php';

$_SERVER['argv'] = ['soda', 'list'];

require __DIR__ . '/../../soda';
--EXPECTF--
Soda %s

Usage:
  command [options] [arguments]

Options:
%aAvailable commands:
  completion    Dump the shell completion script
  help          Display help for a command
  init          Create soda.php with default quality rules
  list          List commands
  quality       Analyze code quality and check against configured thresholds
 list
  list:metrics  List expression metrics, their meaning and calculation source
  list:rules    List built-in quality rules
