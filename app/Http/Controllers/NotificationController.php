<?php

namespace App\Http\Controllers;

use App\Support\FeedMoment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NotificationController extends Controller
{
    /**
     * How far back the list goes.
     *
     * Named rather than inline now that the page groups by day: the last band
     * is "Earlier", and a reader needs to know it is the last fifty rather
     * than everything that ever happened. The page says so.
     */
    private const LIMIT = 50;

    public function index(Request $request): Response
    {
        $notifications = $request->user()
            ->notifications()
            ->latest()
            ->limit(self::LIMIT)
            ->get();

        return Inertia::render('Notifications/Index', [
            'notifications' => $notifications->map(fn ($notification) => [
                'id' => $notification->id,
                'title' => $notification->data['title'] ?? 'Notification',
                'body' => $notification->data['body'] ?? null,
                'url' => $notification->data['url'] ?? null,
                /*
                 * Which glyph and tone the row is drawn with — see
                 * ParticipantNotification::kind() and activityTone.ts.
                 *
                 * Read out of the stored payload rather than resolved from the
                 * notification class, because the payload is all this table
                 * keeps: the class is a name in a column, and instantiating it
                 * would mean reconstructing the registration or payment it was
                 * built from, on every one of fifty rows.
                 *
                 * The consequence is that rows written before kinds existed
                 * carry none, and the page draws them with the neutral tile.
                 * That is the same fallback an unrecognised kind gets, and it
                 * ages out on its own as the list moves on.
                 */
                'kind' => $notification->data['kind'] ?? null,
                'read' => $notification->read_at !== null,
                'created_at' => $notification->created_at->format('d M Y, g:i A'),
                ...FeedMoment::for($notification->created_at),
            ])->all(),
            /*
             * Counted over the whole table, not over the fifty rows above.
             * "3 unread" beside a list that holds only some of them would be
             * an undercount on exactly the figure the shell's bell is already
             * showing, and the two disagreeing is worse than either being
             * absent.
             */
            'unread' => $request->user()->unreadNotifications()->count(),
            'limit' => self::LIMIT,
        ]);
    }

    public function markAllRead(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return back()->with('success', 'All notifications marked as read.');
    }
}
