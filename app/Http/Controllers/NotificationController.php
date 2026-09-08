<?php

namespace App\Http\Controllers;

use App\Support\FeedMoment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Inertia\Inertia;
use Inertia\Response;

class NotificationController extends Controller
{
    /**
     * How far back the full list goes.
     *
     * Named rather than inline now that the page groups by day: the last band
     * is "Earlier", and a reader needs to know it is the last fifty rather
     * than everything that ever happened. The page says so.
     */
    private const LIMIT = 50;

    /**
     * How many rows the header's popover shows before handing off to the full
     * list.
     *
     * A preview, not a second history — enough to answer "did anything just
     * happen" without turning the header into the page it exists to save a
     * trip to.
     */
    private const RECENT_LIMIT = 6;

    public function index(Request $request): Response
    {
        $notifications = $request->user()
            ->notifications()
            ->latest()
            ->limit(self::LIMIT)
            ->get();

        return Inertia::render('Notifications/Index', [
            'notifications' => $notifications->map($this->present(...))->all(),
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

    /**
     * The header bell's popover: the newest handful, as JSON — and the
     * unread count, which the badge on the bell reads from here rather than
     * from the shared `unreadNotifications` Inertia prop it might look like
     * it should share instead.
     *
     * That prop is what the badge used to read exclusively, on the
     * reasoning that a second count from a second endpoint is a number
     * nothing keeps in step with the first. That held for an ordinary
     * navigation, where the shared prop is recomputed on every request — but
     * not for the browser's Back button, which Inertia serves entirely from
     * a client-side snapshot in `history.state` without asking the server
     * anything (see the popstate handling this comment would otherwise
     * duplicate: Notifications/Index.vue carries the fuller explanation).
     * Clicking a notification marks it read through a plain fetch, outside
     * that snapshot; landing back on a page via Back then shows the shared
     * prop exactly as it was before the click, badge included.
     *
     * The popover already has to hit the network to open at all, so it is
     * the one part of the header that can reliably outrun that cache — a
     * plain fetch, unlike a `popstate` restoration, always reaches this
     * controller. AppNotificationsPopover fetches on mount rather than only
     * on first open specifically so the badge is corrected before anyone
     * has clicked the bell to see why it might be wrong.
     */
    public function recent(Request $request): JsonResponse
    {
        $notifications = $request->user()
            ->notifications()
            ->latest()
            ->limit(self::RECENT_LIMIT)
            ->get();

        return response()->json([
            'notifications' => $notifications->map($this->present(...))->all(),
            'unread' => $request->user()->unreadNotifications()->count(),
        ]);
    }

    /**
     * One notification, in the shape both the popover and the full list
     * render — the same rule FeedMoment itself follows: written once so the
     * two screens cannot quietly start describing an event differently.
     */
    private function present(DatabaseNotification $notification): array
    {
        return [
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
        ];
    }

    /**
     * One notification, read the moment it is opened.
     *
     * Fired from a plain `fetch()` alongside the click that navigates to the
     * notification's own URL — not an Inertia visit, which would replace
     * whatever page the click is about to leave rather than the page it is
     * heading to. `whereKey()` against the caller's own relation is what
     * keeps this from being an oracle for guessing other people's
     * notification ids: a mismatched id 404s exactly as an absent one would.
     */
    public function markRead(Request $request, string $notification): JsonResponse
    {
        $request->user()->notifications()->whereKey($notification)->firstOrFail()->markAsRead();

        return response()->json(['read' => true]);
    }

    public function markAllRead(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return back()->with('success', 'All notifications marked as read.');
    }
}
