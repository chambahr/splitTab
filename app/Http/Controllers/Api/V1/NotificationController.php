<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class NotificationController
{
    public function index(Request $request)
    {
        $items = DB::table('notifications')->where('notifiable_type', get_class($request->user()))
            ->where('notifiable_id', $request->user()->id)->latest()->paginate(30);
        $items->getCollection()->transform(function ($item) {
            $item->data = json_decode($item->data, true);
            return $item;
        });
        return response()->json($items);
    }

    public function markRead(Request $request, string $notification)
    {
        $updated = DB::table('notifications')->where('id', $notification)
            ->where('notifiable_type', get_class($request->user()))
            ->where('notifiable_id', $request->user()->id)->whereNull('read_at')
            ->update(['read_at' => now(), 'updated_at' => now()]);
        abort_unless($updated === 1 || DB::table('notifications')->where('id',$notification)->where('notifiable_id',$request->user()->id)->whereNotNull('read_at')->exists(),404);
        return response()->noContent();
    }
}
