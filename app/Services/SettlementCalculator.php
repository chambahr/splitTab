<?php
namespace App\Services;
use InvalidArgumentException;
/** Pure integer-minor-unit ledger. Balances are paid minus allocated shares. */
final class SettlementCalculator
{
    /** @param array<int,int> $paidByUser @param array<int,int> $sharesByUser */
    public function balances(array $paidByUser, array $sharesByUser): array
    {
        $ids = array_unique([...array_keys($paidByUser), ...array_keys($sharesByUser)]);
        $out=[];
        foreach($ids as $id){$out[(int)$id]=(int)($paidByUser[$id]??0)-(int)($sharesByUser[$id]??0);}
        ksort($out); return $out;
    }
    /** @return array<int,array{from:int,to:int,amount_minor:int}> */
    public function transfers(array $balances): array
    {
        $creditors=[];$debtors=[];$sum=0;
        foreach($balances as $id=>$balance){$sum+=(int)$balance;if($balance>0)$creditors[]=['id'=>(int)$id,'left'=>(int)$balance];elseif($balance<0)$debtors[]=['id'=>(int)$id,'left'=>(int)-$balance];}
        if($sum!==0) throw new InvalidArgumentException('Ledger does not balance. Expense shares must equal total paid.');
        $out=[];$i=0;$j=0;
        while(isset($debtors[$i],$creditors[$j])){$amount=min($debtors[$i]['left'],$creditors[$j]['left']);if($amount>0)$out[]=['from'=>$debtors[$i]['id'],'to'=>$creditors[$j]['id'],'amount_minor'=>$amount];$debtors[$i]['left']-=$amount;$creditors[$j]['left']-=$amount;if($debtors[$i]['left']===0)$i++;if($creditors[$j]['left']===0)$j++;}
        return $out;
    }
    /** Split an amount evenly. Remaining minor units go to the first ids in sorted order. */
    public function equalShares(int $amountMinor, array $userIds): array
    {
        $ids=array_values(array_unique(array_map('intval',$userIds)));sort($ids);
        if($amountMinor<0 || count($ids)===0) throw new InvalidArgumentException('A positive amount and at least one participant are required.');
        $base=intdiv($amountMinor,count($ids));$remainder=$amountMinor%count($ids);$out=[];
        foreach($ids as $index=>$id)$out[$id]=$base+($index<$remainder?1:0);
        return $out;
    }
    /** Manual shares must add up exactly to the expense total. */
    public function validateShares(int $amountMinor,array $shares): void
    {
        if($amountMinor<=0 || count($shares)===0 || min($shares)<0 || array_sum($shares)!==$amountMinor) throw new InvalidArgumentException('Manual allocations must be non-negative and add up exactly to the expense amount.');
    }
}
