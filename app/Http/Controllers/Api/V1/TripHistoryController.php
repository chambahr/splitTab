<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\Request;

final class TripHistoryController
{
    public function index(Request $request)
    {
        return response()->json($request->user()->trips()
            ->wherePivotIn('status',['active','left'])->where('trips.status','completed')
            ->withCount(['members'=>fn($q)=>$q->where('status','active')])
            ->with(['city:id,name,region','owner:id,name,username'])
            ->orderByDesc('trips.end_at')->paginate(20));
    }
}
