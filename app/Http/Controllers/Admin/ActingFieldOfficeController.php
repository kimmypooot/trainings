<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FieldOffice;
use App\Support\ActingFieldOffice;
use App\Support\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Switches the office a concurrent field-office user is working as. Only the
 * offices IMIS named at sign-in are accepted; anything else is refused.
 */
class ActingFieldOfficeController extends Controller
{
    /**
     * The screen right after sign-in: which office to work as. Nothing to
     * choose for someone with a single office, who goes straight on.
     */
    public function show(Request $request): Response|RedirectResponse
    {
        $options = ActingFieldOffice::options($request->user());

        if ($options === null) {
            return redirect()->route('admin.dashboard');
        }

        return Inertia::render('Admin/ChooseOffice', [
            'offices' => $options['offices'],
            'active' => $options['active'],
            'homeId' => $request->user()->field_office_id,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $officeId = $request->integer('field_office_id');

        abort_unless(ActingFieldOffice::switchTo($request->user(), $officeId), 403);

        $office = FieldOffice::findOrFail($officeId);

        ActivityLogger::record('field_office.switched', $office, "Now working as {$office->name}.");

        // Where they were headed when the choice interrupted them; otherwise the
        // dashboard, not the previous page: that page may be a record that
        // belongs to the office just left.
        return redirect()->intended(route('admin.dashboard'))->with('success', "Now working as {$office->name}.");
    }
}
