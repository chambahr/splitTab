<?php
namespace Tests\Unit;
use App\Services\SettlementCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
class SettlementCalculatorTest extends TestCase
{
 private SettlementCalculator $calc;
 protected function setUp():void{$this->calc=new SettlementCalculator();}
 public function test_equal_split_distributes_remainder_without_losing_minor_units():void{$this->assertSame([2=>34,5=>33,9=>33],$this->calc->equalShares(100,[9,2,5]));}
 public function test_equal_split_rejects_empty_participant_list():void{$this->expectException(InvalidArgumentException::class);$this->calc->equalShares(100,[]);}
 public function test_balances_reconcile_paid_and_allocated_amounts():void{$this->assertSame([1=>13500,2=>-4500,3=>-4500,4=>-4500],$this->calc->balances([1=>18000],[1=>4500,2=>4500,3=>4500,4=>4500]));}
 public function test_minimizes_practical_settlement_transfers():void{$this->assertSame([['from'=>2,'to'=>1,'amount_minor'=>4500],['from'=>3,'to'=>1,'amount_minor'=>4500],['from'=>4,'to'=>1,'amount_minor'=>4500]],$this->calc->transfers([1=>13500,2=>-4500,3=>-4500,4=>-4500]));}
 public function test_ledger_must_balance_before_transfers_are_generated():void{$this->expectException(InvalidArgumentException::class);$this->calc->transfers([1=>100,2=>-90]);}
 public function test_manual_shares_must_equal_expense_total():void{$this->calc->validateShares(100,[1=>40,2=>60]);$this->expectException(InvalidArgumentException::class);$this->calc->validateShares(100,[1=>40,2=>50]);}
}
