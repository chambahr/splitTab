<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Trip;
use App\Models\TripReview;
use App\Services\InAppNotifier;
use App\Services\AuditLogger;
use Illuminate\Http\Request;

final class TripReviewController
{
    public function index(Trip $trip)
    {
        abort_unless($trip->status === 'completed' && $trip->visibility === 'public', 404);
        return response()->json($trip->reviews()->with(['reviewer:id,name,username,avatar_url','reviewee:id,name,username,avatar_url'])->latest()->paginate(30));
    }

    public function store(Request $request, Trip $trip, InAppNotifier $notifier, AuditLogger $audit)
    {
        abort_unless($trip->status === 'completed', 409, 'Reviews are available after a trip is completed.');
        $data = $request->validate(['reviewee_id'=>'required|integer|exists:users,id','rating'=>'required|integer|min:1|max:5','title'=>'nullable|string|max:100','body'=>'nullable|string|max:2000']);
        $actor = $request->user();
        abort_unless($trip->members()->where('user_id',$actor->id)->whereIn('status',['active','left'])->exists(),403);
        abort_unless($trip->members()->where('user_id',$data['reviewee_id'])->whereIn('status',['active','left'])->exists(),422,'The person must have participated in this trip.');
        abort_if((int)$data['reviewee_id'] === (int)$actor->id,422,'You cannot review yourself.');
        $review = TripReview::firstOrCreate(['trip_id'=>$trip->id,'reviewer_id'=>$actor->id,'reviewee_id'=>$data['reviewee_id']],['rating'=>$data['rating'],'title'=>$data['title']??null,'body'=>$data['body']??null]);
        if (!$review->wasRecentlyCreated) return response()->json(['message'=>'You have already reviewed this participant for this trip.'],409);
        $audit->record($trip, $actor, 'trip.review_created', 'trip_review', (int) $review->id, ['reviewee_id'=>$review->reviewee_id,'rating'=>$review->rating]);
        $notifier->send($review->reviewee, 'trip.review_received', ['trip_id'=>$trip->id,'trip_name'=>$trip->name,'rating'=>$review->rating]);
        return response()->json($review,201);
    }
}
