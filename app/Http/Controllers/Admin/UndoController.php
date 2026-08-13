<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\UndoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Takes back the staff decision that was just made.
 *
 * The token names a snapshot held in the actor's own session — there is nothing
 * here for a client to choose but which of its own recent actions to reverse,
 * and only for as long as the window is open.
 */
class UndoController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
        ]);

        $result = UndoService::apply($validated['token'], $request->user());

        if ($result === null) {
            return back()->withErrors([
                'undo' => 'That can no longer be undone — the window has closed.',
            ]);
        }

        return back()->with('success', $result['label']);
    }
}
