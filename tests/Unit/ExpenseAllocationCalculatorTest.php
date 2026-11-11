<?php

namespace Tests\Unit;

use App\Services\ExpenseAllocationCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ExpenseAllocationCalculatorTest extends TestCase
{
    private ExpenseAllocationCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new ExpenseAllocationCalculator();
    }

    public function test_equal_allocation_distributes_every_minor_unit_deterministically(): void
    {
        self::assertSame([2 => 34, 5 => 33, 9 => 33], $this->calculator->calculate('equal', 100, [9, 2, 5]));
    }

    public function test_percentage_allocation_requires_exactly_ten_thousand_basis_points(): void
    {
        self::assertSame([10 => 2500, 20 => 7500], $this->calculator->calculate('percentage', 10000, [10 => 2500, 20 => 7500]));
        $this->expectException(InvalidArgumentException::class);
        $this->calculator->calculate('percentage', 10000, [10 => 2500, 20 => 7400]);
    }

    public function test_weighted_allocation_distributes_remainders_deterministically(): void
    {
        self::assertSame([2 => 25, 7 => 75], $this->calculator->calculate('shares', 100, [7 => 3, 2 => 1]));
    }

    public function test_manual_allocation_must_match_expense_total(): void
    {
        self::assertSame([2 => 40, 7 => 60], $this->calculator->calculate('manual', 100, [7 => 60, 2 => 40]));
        $this->expectException(InvalidArgumentException::class);
        $this->calculator->calculate('manual', 100, [2 => 40, 7 => 59]);
    }

    public function test_rejects_duplicate_or_invalid_participant_ids(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->calculator->calculate('equal', 100, [2, 2]);
    }

    public function test_rejects_zero_amount_and_unknown_method(): void
    {
        try {
            $this->calculator->calculate('equal', 0, [1]);
            self::fail('Zero amount should be rejected.');
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }

        $this->expectException(InvalidArgumentException::class);
        $this->calculator->calculate('random', 100, [1]);
    }

    public function test_percentage_allocation_handles_single_participant_and_full_amount(): void
    {
        self::assertSame([42 => 999], $this->calculator->calculate('percentage', 999, [42 => 10000]));
    }

    public function test_percentage_rounding_never_assigns_money_to_zero_percent_participant(): void
    {
        self::assertSame([1 => 0, 2 => 1, 3 => 0], $this->calculator->calculate('percentage', 1, [1 => 0, 2 => 1, 3 => 9999]));
    }
}
