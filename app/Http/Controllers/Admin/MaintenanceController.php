<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SiteSetting;
use App\Support\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The maintenance switch.
 *
 * Superadmin only — deliberately. It is the one role trusted with the switch
 * itself, and the maintenance gate lets every staff role keep working while the
 * site is down (see EnsureSiteIsAvailable), so this screen stays reachable
 * whoever is on duty. The setting is a singleton row that the middleware reads
 * on every request, so saving here is in force on the very next page load.
 */
class MaintenanceController extends Controller
{
    public function index(): Response
    {
        $setting = SiteSetting::current();

        return Inertia::render('Admin/Maintenance', [
            'maintenance' => [
                'enabled' => $setting->maintenance_mode,
                'message' => $setting->maintenance_message,
                'updated_by' => $setting->updatedBy?->name,
                'updated_at' => $setting->updated_at?->format('d M Y, g:i A'),
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'message' => ['nullable', 'string', 'max:500'],
        ]);

        $setting = SiteSetting::current();

        $setting->forceFill([
            'maintenance_mode' => $validated['enabled'],
            // Kept from the previous toggle when a new message is not supplied,
            // so the superadmin can write the message first and switch on after.
            'maintenance_message' => $validated['message'] ?? null,
            'updated_by' => $request->user()->getKey(),
        ])->save();

        ActivityLogger::record(
            'system.maintenance',
            null,
            $validated['enabled']
                ? 'Maintenance mode enabled. Participants and the public are now held on the maintenance notice; CSC staff are unaffected.'
                : 'Maintenance mode disabled. The site is live again.',
            ['enabled' => $validated['enabled']],
        );

        return back()->with(
            'success',
            $validated['enabled']
                ? 'The site is now in maintenance. Visitors and participants will see a maintenance notice; CSC staff keep working.'
                : 'Maintenance mode is off. The site is live again.'
        );
    }
}
