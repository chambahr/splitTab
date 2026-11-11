<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\City;
use Illuminate\Http\Request;

final class CityController
{
    public function index(Request $request)
    {
        $query = City::query()->where('is_active', true)->where('country_code', $request->input('country_code', 'CM'));
        if ($request->filled('region')) $query->where('region', $request->string('region'));
        return response()->json($query->orderBy('region')->orderBy('name')->get(['id','country_code','country_name','region','name','slug']));
    }
}
