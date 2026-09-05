<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Certificate;
use App\Models\OfficeSetting;
use App\Providers\OfficeSettingsProvider;
use App\Support\ActivityLogger;
use App\Support\PhilippineGeography;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Who this deployment says it is.
 *
 * Superadmin only, alongside the maintenance switch and the audit log: this is
 * the office's public identity, printed on documents that outlive the person
 * editing it, and it is not something a training officer should be able to
 * change between two registrations.
 *
 * The values were `.env` settings, which meant a change to the office
 * telephone number needed server access and a `config:cache` clear. That put a
 * clerical fact behind a developer, and it is why this screen exists.
 */
class OfficeSettingController extends Controller
{
    public function index(): Response
    {
        $setting = OfficeSetting::current();

        return Inertia::render('Admin/OfficeSettings', [
            /*
             * The effective values, not the row.
             *
             * The form shows what the site is actually using, which for an
             * office that has never saved anything is config/office.php. Show
             * the empty row instead and a superadmin opening the screen for the
             * first time sees blank boxes beside a footer that plainly has an
             * address in it, and cannot tell whether saving will change
             * anything.
             */
            'office' => collect(OfficeSetting::FIELDS)
                ->mapWithKeys(fn (string $field) => [$field => config("office.{$field}")])
                ->all(),

            'regions' => PhilippineGeography::regions(),

            /*
             * The certificate prefix locks once anything has been issued under
             * it. Changing it then would put a permanent seam in a numbered
             * series the office quotes in correspondence, and every number
             * already assigned has to keep matching the paper copy.
             */
            'certificatePrefixLocked' => Certificate::exists(),

            'updated' => $setting === null ? null : [
                'by' => $setting->updatedBy?->name,
                'at' => $setting->updated_at?->format('d M Y, g:i A'),
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $locked = Certificate::exists();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'short_name' => ['required', 'string', 'max:64'],
            'region' => ['nullable', 'string', 'max:128'],
            // Chosen from the PSA's own list rather than typed. This is matched
            // against participants' profiles to decide who the office serves,
            // and a misspelling matches nobody — which reads as "everyone is an
            // outsider" and offers the whole region courier delivery.
            'psgc_region' => ['required', 'string', Rule::in(PhilippineGeography::regions())],
            'address' => ['nullable', 'string', 'max:500'],
            'phone' => ['nullable', 'string', 'max:64'],
            'email' => ['nullable', 'email', 'max:128'],
            'certificate_prefix' => ['required', 'string', 'max:16', 'regex:/^[A-Za-z0-9-]+$/'],
        ], [
            'certificate_prefix.regex' => 'The prefix may use letters, numbers and hyphens only — it becomes part of a printed certificate number.',
            'psgc_region.in' => 'Choose the region from the list, so it matches the names used on participants\' profiles.',
        ]);

        $setting = OfficeSetting::current() ?? new OfficeSetting;

        /*
         * The prefix is not taken from the request once it is locked.
         *
         * The screen disables the field, but a disabled input is a courtesy to
         * the person and not a control: the form still posts, and a stale tab
         * opened before the first certificate was issued would post the old
         * value. Ignoring it here is what actually holds.
         */
        if ($locked) {
            unset($validated['certificate_prefix']);
        }

        $before = $this->effective();

        $setting->fill([...$validated, 'updated_by' => $request->user()->getKey()])->save();

        // OfficeSettingsProvider merged the *old* row into config when this
        // request booted, so config is a save behind. Re-apply through the same
        // path rather than second-guessing it here.
        OfficeSettingsProvider::apply();

        $after = $this->effective();

        /*
         * Only what moved, with from/to — the same shape as `user.updated`, and
         * for the same reason: the form posts every field whether or not it
         * changed, and a region change buried among seven unchanged ones is a
         * trail nobody scans.
         *
         * Values are recorded in full here, unlike a profile edit which records
         * field names only. Nothing on this screen is personal data — it is the
         * office's published identity, and what it changed *from* is exactly
         * what someone auditing a wrong address on a certificate needs.
         */
        $changed = array_keys(array_diff_assoc($after, $before));

        if ($changed !== []) {
            ActivityLogger::record(
                'office.settings_updated',
                null,
                sprintf('Office identity updated: %s changed.', implode(', ', $changed)),
                [
                    'changed' => $changed,
                    'from' => array_intersect_key($before, array_flip($changed)),
                    'to' => array_intersect_key($after, array_flip($changed)),
                ],
                $request->user(),
            );
        }

        return back()->with('success', $changed === []
            ? 'No changes to save.'
            : 'The office details have been updated. They apply everywhere immediately, including on certificates issued from now on.');
    }

    /**
     * The values the site is actually using right now.
     *
     * @return array<string, string|null>
     */
    private function effective(): array
    {
        return collect(OfficeSetting::FIELDS)
            ->mapWithKeys(fn (string $field) => [$field => config("office.{$field}")])
            ->all();
    }
}
