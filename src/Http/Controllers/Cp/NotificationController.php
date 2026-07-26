<?php

namespace Goldnead\Notifications\Http\Controllers\Cp;

use Goldnead\Notifications\Models\NotificationItem;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;

/**
 * Read-only inspector: what was written, to whom, read or not, digested or not.
 * No sending, no editing — the CP is for answering "did this person get it?",
 * which is the question support actually asks.
 */
class NotificationController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('view notifications');

        $notifications = NotificationItem::query()
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')->toString()))
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->string('user_id')->toString()))
            ->when($request->boolean('unread'), fn ($q) => $q->unread())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate((int) config('notifications.cp.per_page', 50))
            ->withQueryString();

        return view('notifications::cp.index', [
            'notifications' => $notifications,
            'filters' => $request->only(['type', 'user_id', 'unread']),
        ]);
    }

    public function show(int $id)
    {
        Gate::authorize('view notifications');

        // Brand scope still applies: an operator in brand A must not read a
        // brand B row by guessing its id.
        $notification = NotificationItem::query()->findOrFail($id);

        return view('notifications::cp.show', ['notification' => $notification]);
    }
}
