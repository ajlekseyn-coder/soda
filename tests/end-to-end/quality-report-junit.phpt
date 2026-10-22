--TEST--
soda quality --report-type=junit --report-file
--FILE--
<?php declare(strict_types=1);
require __DIR__ . '/../../vendor/autoload.php';

$reportPath = sys_get_temp_dir() . '/soda-junit-' . uniqid() . '.xml';
$_SERVER['argv'] = ['soda', 'quality', '--report-type=junit', '--report-file', $reportPath, __DIR__ . '/../quality-fixture'];

define('SODA_ENTRY_NO_EXIT', true);

require __DIR__ . '/../../soda';

$xml = file_get_contents($reportPath);
unlink($reportPath);

if (! str_contains($xml, '<testsuite name="soda quality" tests="0" failures="0" errors="0">')) {
    echo "Missing empty testsuite\n";
    exit(1);
}
if (str_contains($xml, '<failure')) {
    echo "Unexpected failure\n";
    exit(1);
}
echo "OK\n";
--EXPECTF--
%A
OK
