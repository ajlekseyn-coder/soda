--TEST--
soda quality --report-type=teamcity
--FILE--
<?php declare(strict_types=1);
require __DIR__ . '/../../vendor/autoload.php';

$_SERVER['argv'] = ['soda', 'quality', '--report-type=teamcity', __DIR__ . '/../quality-fixture'];

define('SODA_ENTRY_NO_EXIT', true);

require __DIR__ . '/../../soda';
--EXPECTF--
%A##teamcity[testSuiteStarted name='soda quality']
##teamcity[testSuiteFinished name='soda quality']
