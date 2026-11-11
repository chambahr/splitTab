<?php

namespace App\Services;

use InvalidArgumentException;

/**
 * Deterministic allocation of integer minor units.
 * Percentage weights are basis points (10,000 = 100%). Share weights are
 * positive integer units. Any rounding remainder is assigned by ascending user ID.
 */
final class ExpenseAllocationCalculator
{
    /**
     * @param  array<int>|array<int,int>  $participants  Equal allocation accepts a list of IDs;
     *                                                   other methods accept user ID => manual amount / basis points / weight.
     * @return array<int,int> user ID => allocated minor units
     */
    public function calculate(string $method, int $amountMinor, array $participants): array
    {
        if ($amountMinor <= 0 || $participants === []) {
            throw new InvalidArgumentException('A positive amount and at least one participant are required.');
        }

        if ($method === 'equal') {
            if (array_is_list($participants)) {
                $ids = array_map('intval', $participants);
                if (count($ids) !== count(array_unique($ids)) || min($ids) <= 0) {
                    throw new InvalidArgumentException('Participants must have unique positive user IDs.');
                }
                $weights = array_fill_keys($ids, 1);
            } else {
                $weights = array_fill_keys(array_map('intval', array_keys($participants)), 1);
            }

            return $this->proportional($amountMinor, $weights);
        }

        $weights = $this->normalizeWeights($participants);

        return match ($method) {
            'manual' => $this->manual($amountMinor, $weights),
            'percentage' => $this->percentage($amountMinor, $weights),
            'shares' => $this->proportional($amountMinor, $weights),
            default => throw new InvalidArgumentException('Unsupported allocation method.'),
        };
    }

    /** @param array<int,int> $shares @return array<int,int> */
    private function manual(int $amountMinor, array $shares): array
    {
        $sum = 0;
        foreach ($shares as $share) {
            if ($share < 0 || $share > $amountMinor || $sum > $amountMinor - $share) {
                throw new InvalidArgumentException('Manual shares must be non-negative and cannot exceed the expense total.');
            }
            $sum += $share;
        }
        if ($sum !== $amountMinor) {
            throw new InvalidArgumentException('Manual allocations must equal the expense amount.');
        }

        return $shares;
    }

    /** @param array<int,int> $basisPoints @return array<int,int> */
    private function percentage(int $amountMinor, array $basisPoints): array
    {
        if (array_sum($basisPoints) !== 10000) {
            throw new InvalidArgumentException('Percentage allocations must total 10,000 basis points.');
        }

        return $this->proportional($amountMinor, $basisPoints, true);
    }

    /** @param array<int,int> $weights @return array<int,int> */
    private function proportional(int $amountMinor, array $weights, bool $allowZero = false): array
    {
        if ($amountMinor <= 0 || $weights === []) {
            throw new InvalidArgumentException('A positive amount and at least one participant are required.');
        }

        ksort($weights, SORT_NUMERIC);
        $totalWeight = 0;
        foreach ($weights as $weight) {
            if (($allowZero ? $weight < 0 : $weight <= 0) || $weight > 1_000_000) {
                throw new InvalidArgumentException('Allocation weights must be between 1 and 1,000,000.');
            }
            $totalWeight += $weight;
        }
        if ($totalWeight <= 0) {
            throw new InvalidArgumentException('The combined allocation weight must be positive.');
        }
        if ($totalWeight > 500_000_000) {
            throw new InvalidArgumentException('The combined allocation weight is too large.');
        }

        $result = [];
        $allocated = 0;
        foreach ($weights as $userId => $weight) {
            // Decompose amount * weight / total to avoid overflowing amount * weight.
            $whole = intdiv($amountMinor, $totalWeight);
            $remainder = $amountMinor % $totalWeight;
            $share = ($whole * $weight) + intdiv($remainder * $weight, $totalWeight);
            $result[(int) $userId] = $share;
            $allocated += $share;
        }

        // Deterministic remainder: ascending user ID receives one minor unit at a time.
        $remainder = $amountMinor - $allocated;
        foreach ($weights as $userId => $weight) {
            if ($remainder === 0) {
                break;
            }
            if ($weight === 0) {
                continue;
            }
            $result[$userId]++;
            $remainder--;
        }

        return $result;
    }

    /** @param array<int,int> $participants @return array<int,int> */
    private function normalizeWeights(array $participants): array
    {
        $weights = [];
        foreach ($participants as $userId => $weight) {
            $id = filter_var($userId, FILTER_VALIDATE_INT);
            if ($id === false || $id <= 0 || isset($weights[$id]) || ! is_int($weight)) {
                throw new InvalidArgumentException('Participant IDs and allocation values must be valid integers.');
            }
            $weights[$id] = $weight;
        }
        if ($weights === []) {
            throw new InvalidArgumentException('At least one participant is required.');
        }
        ksort($weights, SORT_NUMERIC);

        return $weights;
    }
}
