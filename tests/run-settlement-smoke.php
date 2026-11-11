<?php

require __DIR__.'/../app/Services/SettlementCalculator.php';
use App\Services\SettlementCalculator;

$c = new SettlementCalculator;
$checks = [];
$checks['equal split remainder'] = $c->equalShares(100, [9, 2, 5]) === [2 => 34, 5 => 33, 9 => 33];
$balances = $c->balances([1 => 18000], [1 => 4500, 2 => 4500, 3 => 4500, 4 => 4500]);
$checks['net balances'] = $balances === [1 => 13500, 2 => -4500, 3 => -4500, 4 => -4500];
$checks['three creditor transfers'] = count($c->transfers($balances)) === 3;
$c->validateShares(100, [1 => 40, 2 => 60]);
try {
    $c->transfers([1 => 100, 2 => -90]);
    $checks['unbalanced ledger rejection'] = false;
} catch (InvalidArgumentException) {
    $checks['unbalanced ledger rejection'] = true;
}
foreach ($checks as $label => $passed) {
    echo ($passed ? 'PASS' : 'FAIL')." {$label}\n";
}
if (in_array(false, $checks, true)) {
    exit(1);
}
