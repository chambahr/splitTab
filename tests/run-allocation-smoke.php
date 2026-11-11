<?php
require dirname(__DIR__).'/vendor/autoload.php';
use App\Services\ExpenseAllocationCalculator;
$calculator = new ExpenseAllocationCalculator();
$cases = [
    ['equal remainder', [2 => 34, 5 => 33, 9 => 33], fn () => $calculator->calculate('equal', 100, [9, 2, 5])],
    ['manual allocation', [2 => 40, 7 => 60], fn () => $calculator->calculate('manual', 100, [7 => 60, 2 => 40])],
    ['percentage allocation', [10 => 2500, 20 => 7500], fn () => $calculator->calculate('percentage', 10000, [10 => 2500, 20 => 7500])],
    ['weighted allocation', [2 => 25, 7 => 75], fn () => $calculator->calculate('shares', 100, [7 => 3, 2 => 1])],
    ['zero percent remainder', [1 => 0, 2 => 1, 3 => 0], fn () => $calculator->calculate('percentage', 1, [1 => 0, 2 => 1, 3 => 9999])],
];
foreach ($cases as [$name, $expected, $run]) {
    if ($run() !== $expected) { fwrite(STDERR, "FAIL {$name}\n"); exit(1); }
    echo "PASS {$name}\n";
}
try { $calculator->calculate('percentage', 100, [1 => 4000, 2 => 5000]); fwrite(STDERR, "FAIL invalid percentage accepted\n"); exit(1); }
catch (\InvalidArgumentException) { echo "PASS invalid percentage rejected\n"; }
