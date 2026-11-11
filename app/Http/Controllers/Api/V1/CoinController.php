<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TripVisibility;
use App\Models\CoinAccount;
use App\Models\CoinTransaction;
use App\Models\Trip;
use App\Services\InAppNotifier;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class CoinController
{
    public function balance(Request $request)
    {
        $account = CoinAccount::firstOrCreate([
            'user_id' => $request->user()->id,
        ]);

        return response()->json([
            'balance' => $account->balance,
            'unit' => 'appreciation_points',
            'cash_value' => null,
            'withdrawals_enabled' => false,
        ]);
    }

    public function history(Request $request)
    {
        return response()->json(
            CoinTransaction::query()
                ->where(function ($q) use ($request) {
                    $q->where('sender_id', $request->user()->id)
                        ->orWhere('recipient_id', $request->user()->id);
                })
                ->latest()
                ->paginate(30)
        );
    }

    public function gift(
        Request $request,
        Trip $trip,
        InAppNotifier $notifier,
        AuditLogger $audit
    ) {
        $data = $request->validate([
            'amount' => 'required|integer|min:1|max:100',
            'note' => 'nullable|string|max:180',
            'idempotency_key' => 'required|uuid',
        ]);

        abort_unless(
            $trip->visibility === TripVisibility::PUBLIC
                && $trip->owner_id !== $request->user()->id,
            404
        );

        return DB::transaction(function () use (
            $request,
            $trip,
            $data,
            $notifier,
            $audit
        ) {
            $existing = CoinTransaction::where(
                'idempotency_key',
                $data['idempotency_key']
            )->first();

            if ($existing) {
                abort_unless(
                    (int) $existing->sender_id === (int) $request->user()->id
                    && (int) $existing->recipient_id === (int) $trip->owner_id
                    && (int) $existing->amount === (int) $data['amount'],
                    409,
                    'Idempotency key was already used for another gift.'
                );

                return response()->json($existing, 200);
            }

            CoinAccount::insertOrIgnore([
                [
                    'user_id' => $request->user()->id,
                    'balance' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'user_id' => $trip->owner_id,
                    'balance' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ]);

            $ids = CoinAccount::whereIn('user_id', [
                $request->user()->id,
                $trip->owner_id,
            ])
                ->orderBy('id')
                ->lockForUpdate()
                ->pluck('id', 'user_id');

            $sender = CoinAccount::whereKey(
                $ids[$request->user()->id]
            )->firstOrFail();

            $recipient = CoinAccount::whereKey(
                $ids[$trip->owner_id]
            )->firstOrFail();

            $existing = CoinTransaction::where(
                'idempotency_key',
                $data['idempotency_key']
            )->first();

            if ($existing) {
                abort_unless(
                    (int) $existing->sender_id === (int) $request->user()->id
                    && (int) $existing->recipient_id === (int) $trip->owner_id
                    && (int) $existing->amount === (int) $data['amount'],
                    409,
                    'Idempotency key was already used for another gift.'
                );

                return response()->json($existing, 200);
            }

            $dailySent = CoinTransaction::where(
                'sender_id',
                $request->user()->id
            )
                ->where('created_at', '>=', now()->startOfDay())
                ->sum('amount');

            abort_if(
                $dailySent + $data['amount'] > 500,
                429,
                'Daily appreciation gifting limit reached.'
            );

            abort_if(
                $sender->balance < $data['amount'],
                422,
                'Insufficient appreciation points.'
            );

            $sender->decrement('balance', $data['amount']);
            $recipient->increment('balance', $data['amount']);

            $transaction = CoinTransaction::create([
                'sender_id' => $request->user()->id,
                'recipient_id' => $trip->owner_id,
                'amount' => $data['amount'],
                'type' => 'gift',
                'note' => $data['note'] ?? null,
                'idempotency_key' => $data['idempotency_key'],
            ]);

            $audit->record(
                $trip,
                $request->user(),
                'coins.gifted',
                'coin_transaction',
                (int) $transaction->id,
                [
                    'amount' => $data['amount'],
                    'recipient_id' => $trip->owner_id,
                ]
            );

            $notifier->send(
                $trip->owner,
                'coins.received',
                [
                    'trip_id' => $trip->id,
                    'trip_name' => $trip->name,
                    'amount' => $data['amount'],
                ]
            );

            return response()->json($transaction, 201);
        });
    }
}
