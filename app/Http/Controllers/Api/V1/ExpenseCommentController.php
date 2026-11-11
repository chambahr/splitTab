<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TripVisibility;
use App\Models\Expense;
use App\Models\Trip;
use App\Services\AuditLogger;
use Illuminate\Http\Request;

class ExpenseCommentController
{
    public function index(Request $r, Trip $trip, Expense $expense)
    {
        $this->authorize($r, $trip, $expense);

        return $expense->comments()->with('user:id,name')->oldest()->get();
    }

    public function store(Request $r, Trip $trip, Expense $expense, AuditLogger $audit)
    {
        $this->authorize($r, $trip, $expense);
        $d = $r->validate(['body' => 'required|string|min:1|max:2000']);
        $comment = $expense->comments()->create(['user_id' => $r->user()->id, 'body' => $d['body']]);
        $audit->record($trip, $r->user(), 'expense.comment_added', 'expense_comment', $comment->id);

        return response()->json($comment->load('user:id,name'), 201);
    }

    private function authorize(Request $r, Trip $trip, Expense $expense): void
    {
        abort_unless($expense->trip_id === $trip->id, 404);
        $m = $trip->members()->where('user_id', $r->user()->id)->where('status', 'active')->first();
        abort_unless($m, 403);
        if ($r->isMethod('post')) {
            abort_if($m->role === 'viewer', 403, 'Viewers cannot comment.');
        }if ($trip->visibility === TripVisibility::PRIVATE && (! $m->last_private_verified_at || $m->last_private_verified_at->lt(now()->subMinutes((int) config('splittrip.private_access_minutes',30))))) {
            abort(423,'Private-trip OTP required.');
        }
    }
}
