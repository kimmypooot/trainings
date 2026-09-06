<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Providers\AppServiceProvider;
use App\Support\UndoService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The staff guide to running the system.
 *
 * The participant guide answers "how do I use this"; this one answers "what am
 * I allowed to do, and what happens when I do it" — which is the question a new
 * field officer or collecting officer actually arrives with, and the one the
 * office was answering by sitting somebody next to them for a week.
 *
 * The sections live in the Vue page, as the participant guide's do, and each
 * declares the roles it applies to. **That filtering is about relevance, not
 * security**: a guide is documentation, the whole file ships in the bundle
 * whoever is reading, and the thing that actually stops a field office
 * releasing a certificate is the middleware on the route. What it buys is that
 * a collecting officer is not handed thirteen sections of which four are
 * theirs, which is how a guide becomes something nobody opens twice.
 *
 * Numbers come from here rather than being typed into the prose, for the reason
 * the participant guide takes its file limits from the controllers that enforce
 * them: a guide quoting a figure the code no longer uses is confidently wrong
 * on the screen somebody opened *because* they were unsure.
 */
class HelpController extends Controller
{
    public function __invoke(Request $request): Response
    {
        return Inertia::render('Help/Admin', [
            // How long a roster decision stays reversible. The notification the
            // decision sends is delayed by the same window, which is why the
            // two must never be quoted as different numbers.
            'undoWindow' => UndoService::WINDOW_SECONDS,

            /*
             * The review queues show this many rows each, pending first.
             * Read off the controller so the guide cannot promise a different
             * bound from the one the screen applies.
             */
            'queueCap' => RequestQueueController::LIMIT,

            // Exports are rate limited per minute — see the `exports` limiter
            // in AppServiceProvider.
            'exportLimit' => AppServiceProvider::EXPORTS_PER_MINUTE,
        ]);
    }
}
