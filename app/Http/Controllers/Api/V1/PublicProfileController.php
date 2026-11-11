<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class PublicProfileController
{
    public function show(string $username)
    {
        $user = User::with('homeCity:id,name,region,country_name')->where('username', $username)->firstOrFail();
        return response()->json([
            'id' => $user->id, 'name' => $user->name, 'username' => $user->username,
            'bio' => $user->bio, 'avatar_url' => $user->avatar_url, 'home_city' => $user->homeCity,
            'hosted_trips_count' => $user->ownedTrips()->where('visibility', 'public')->whereIn('status', ['completed','active'])->count(),
            'reviews_received' => $user->reviewsReceived()->count(),
            'average_rating' => round((float) $user->reviewsReceived()->avg('rating'), 2),
        ]);
    }

    public function update(Request $request, AuditLogger $audit)
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => 'sometimes|required|string|max:120',
            'username' => ['sometimes','required','string','min:3','max:32','regex:/^[A-Za-z0-9_]+$/',Rule::unique('users','username')->ignore($user->id)],
            'phone' => 'sometimes|nullable|string|max:32',
            'bio' => 'nullable|string|max:500', 'avatar_url' => 'nullable|url|max:500', 'home_city_id' => 'nullable|integer|exists:cities,id',
        ]);
        $user->fill($data)->save();
        $audit->record(null, $user, 'auth.profile_updated', 'user', (int) $user->id, ['changed_fields' => array_keys($data)]);
        return response()->json(['user' => $user->fresh()->load('homeCity:id,name,region,country_name')]);
    }
}
