<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Trip;
use Illuminate\Http\Request;

final class PublicTripController
{
    public function index(Request $request)
    {
        $query = Trip::query()->where('visibility', 'public')->whereIn('status', ['planning', 'active'])
            ->with(['owner:id,name,username,avatar_url', 'city:id,name,region,country_name'])
            ->withCount(['members' => fn ($q) => $q->where('status', 'active')]);

        if ($request->filled('city_id')) $query->where('city_id', $request->integer('city_id'));
        if ($request->filled('city')) $query->whereHas('city', fn ($q) => $q->where('slug', $request->string('city')));
        if ($request->filled('type')) $query->where('trip_type', $request->string('type'));
        if ($request->filled('q')) $query->where(fn ($q) => $q->where('name', 'ilike', '%'.$request->string('q').'%')->orWhere('description', 'ilike', '%'.$request->string('q').'%'));

        return response()->json($query->orderByRaw('start_at IS NULL')->orderBy('start_at')->paginate(min(50, max(1, $request->integer('per_page', 20)))));
    }

    public function show(Trip $trip)
    {
        abort_unless($trip->visibility === 'public' && !$trip->trashed(), 404);
        $trip->load(['owner:id,name,username,avatar_url,bio', 'city:id,name,region,country_name'])
            ->loadCount(['members' => fn ($q) => $q->where('status', 'active')]);

        return response()->json([
            'id' => $trip->id, 'name' => $trip->name, 'description' => $trip->description,
            'trip_type' => $trip->trip_type, 'visibility' => 'public', 'destination' => $trip->destination,
            'city' => $trip->city, 'photo_url' => $trip->photo_path ? asset('storage/'.$trip->photo_path) : null,
            'start_at' => $trip->start_at, 'end_at' => $trip->end_at, 'currency' => $trip->currency,
            'budget_minor' => $trip->budget_minor, 'participant_limit' => $trip->participant_limit,
            'members_count' => $trip->members_count, 'status' => $trip->status, 'host' => $trip->owner,
        ]);
    }

    public function expenses(Trip $trip)
    {
        abort_unless($trip->visibility === 'public' && !$trip->trashed(), 404);
        $items = $trip->expenses()->whereNull('deleted_at')->with(['payer:id,name,username','splits.user:id,name,username'])->orderByDesc('spent_at')->paginate(30);
        $items->getCollection()->transform(fn ($expense) => [
            'id' => $expense->id, 'description' => $expense->description, 'category' => $expense->category,
            'amount_minor' => $expense->amount_minor, 'currency' => $expense->currency,
            'spent_at' => $expense->spent_at, 'payer' => $expense->payer ? ['name' => $expense->payer->name, 'username' => $expense->payer->username] : null,
            'splits' => $expense->splits->map(fn ($split) => ['user'=>$split->user ? ['name'=>$split->user->name,'username'=>$split->user->username] : null,'share_minor'=>$split->share_minor]),
        ]);
        return response()->json($items);
    }

    public function settlements(Trip $trip)
    {
        abort_unless($trip->visibility === 'public' && !$trip->trashed(), 404);
        $settlements = $trip->settlements()->whereIn('status', ['confirmed', 'completed'])->with(['transfers.fromUser:id,name,username','transfers.toUser:id,name,username'])->latest()->paginate(20);
        $settlements->getCollection()->transform(fn ($settlement) => [
            'id' => $settlement->id, 'currency' => $settlement->currency, 'status' => $settlement->status,
            'confirmed_at' => $settlement->confirmed_at,
            'transfer_count' => $settlement->transfers->count(),
            'total_minor' => $settlement->transfers->sum('amount_minor'),
            'transfers' => $settlement->transfers->map(fn ($transfer) => [
                'from' => $transfer->fromUser ? ['name'=>$transfer->fromUser->name,'username'=>$transfer->fromUser->username] : null,
                'to' => $transfer->toUser ? ['name'=>$transfer->toUser->name,'username'=>$transfer->toUser->username] : null,
                'amount_minor' => $transfer->amount_minor, 'status' => $transfer->status, 'paid_at' => $transfer->paid_at,
            ]),
        ]);
        return response()->json($settlements);
    }
}
