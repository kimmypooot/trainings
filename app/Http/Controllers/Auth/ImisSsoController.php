<?php

namespace App\Http\Controllers\Auth;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Jobs\ImportGoogleAvatar;
use App\Models\FieldOffice;
use App\Models\User;
use App\Support\GoogleAvatarFetcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Signs staff in from the IMIS portal. IMIS sends the browser to /sso with a
 * one-time token; the page shows the splash and posts it back here, and this
 * redeems it server-to-server at IMIS, which says who it was issued for, what
 * role they hold in TIMS (`role_code`, a staff Role value) and which
 * organizational unit they belong to.
 *
 * IMIS is the only place staff are managed. The account here is linked by
 * `imis_user_id` (else once by email), created on first sign-in, and has its
 * name, email, role and field office rewritten from IMIS on every sign-in. It
 * has no password to use. Participants are external, are not in IMIS, and
 * never sign in this way: only the staff roles can be granted from IMIS.
 *
 * Staff don't complete a profile (see EnsureProfileIsComplete), so none is made.
 */
class ImisSsoController extends Controller
{
    /**
     * Where IMIS sends the browser. Shows the splash straight away; the page
     * posts the token back to store() while it stays up.
     */
    public function show(Request $request): Response|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('admin.dashboard');
        }

        $token = $request->query('sso_token');

        if (! config('services.imis.sso_url') || ! is_string($token) || $token === '') {
            return $this->refuse('Single sign-on is not available. Please sign in below.');
        }

        return Inertia::render('Auth/ImisSso', ['token' => $token]);
    }

    public function store(Request $request): RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('admin.dashboard');
        }

        $url = config('services.imis.sso_url');
        $token = $request->input('sso_token');

        if (! $url || ! is_string($token) || $token === '') {
            return $this->refuse('Single sign-on is not available. Please sign in below.');
        }

        try {
            $response = Http::asForm()->timeout(10)->post($url, [
                'token' => $token,
                'audience' => config('services.imis.audience'),
            ]);
        } catch (Throwable $e) {
            Log::warning('IMIS sign-in failed', ['message' => $e->getMessage()]);

            return $this->refuse('IMIS could not be reached. Please sign in below.');
        }

        $imis = $response->successful() && $response->json('success') === true ? $response->json('user') : null;

        if (! is_array($imis) || ! is_int($imis['id'] ?? null) || ! is_string($imis['email'] ?? null) || $imis['email'] === '') {
            return $this->refuse('Your IMIS sign-in could not be used here. Please sign in below.');
        }

        $role = Role::tryFrom((string) ($imis['role_code'] ?? ''));

        if ($role === null || ! $role->isStaff()) {
            return $this->refuse('You have not been given a role in the Training Information Management System in IMIS. Please contact your administrator.');
        }

        $office = $this->fieldOffice($imis);

        if ($role === Role::FieldOffice && $office === null) {
            return $this->refuse('Your IMIS office does not match a TIMS field office. Please contact your administrator.');
        }

        $user = $this->account($imis);

        if ($user === false) {
            return $this->refuse('Your account clashes with an existing TIMS account. Please contact your administrator.');
        }

        if ($user && ! $user->is_active) {
            return $this->refuse('This account has been deactivated. Contact the CSC administrator.');
        }

        $user ??= new User;

        $user->forceFill([
            'imis_user_id' => $imis['id'],
            'name' => $this->fullName($imis),
            'email' => $imis['email'],
            'role' => $role,
            // Region-wide roles can have no office; keep what is known if IMIS has none.
            'field_office_id' => $office->id ?? ($role === Role::FieldOffice ? null : $user->field_office_id),
            'email_verified_at' => $user->email_verified_at ?? now(),
            'is_active' => true,
            'last_login_at' => now(),
        ]);

        // No password: staff sign in through IMIS, so there is nothing to type.
        $user->save();

        $this->importPhoto($user, $imis);

        Auth::login($user);
        $request->session()->regenerate();

        // So signing out of IMIS can end this session (ImisSsoLogoutController).
        if (is_string($imis['sid'] ?? null) && strlen($imis['sid']) === 64) {
            try {
                DB::table('imis_sessions')->insert([
                    'sid_hash' => hash('sha256', $imis['sid']),
                    'session_id' => $request->session()->getId(),
                    'created_at' => now(),
                ]);
            } catch (Throwable $e) {
                // Only the sign-out link is lost; the person still signs in.
                Log::warning('IMIS session could not be recorded', ['message' => $e->getMessage()]);
            }
        }

        return redirect()->intended(route('admin.dashboard'));
    }

    /**
     * The staff account for this IMIS user: by its IMIS id, else an unlinked
     * staff account with the same email. Null when there is none (one is
     * created); false when the email belongs to a participant, who must never
     * be promoted by a matching address, or to an account linked to someone else.
     */
    private function account(array $imis): User|false|null
    {
        if ($linked = User::where('imis_user_id', $imis['id'])->first()) {
            return $linked;
        }

        $match = User::where('email', $imis['email'])->first();

        if ($match === null) {
            return null;
        }

        return $match->imis_user_id === null && $match->role->isStaff() ? $match : false;
    }

    /** The TIMS field office for the person's IMIS unit. */
    private function fieldOffice(array $imis): ?FieldOffice
    {
        return in_array($imis['office_type'] ?? null, ['field_office', 'satellite_office'], true)
            ? FieldOffice::where('imis_code', $imis['office_code'] ?? '')->first()
            : null;
    }

    /** "First M. Last", the way ProfileController composes `users.name`. */
    private function fullName(array $imis): string
    {
        $middle = trim((string) ($imis['mname'] ?? ''));

        return collect([
            trim((string) ($imis['fname'] ?? '')),
            $middle === '' ? null : Str::upper(Str::substr($middle, 0, 1)).'.',
            trim((string) ($imis['lname'] ?? '')),
        ])->filter()->implode(' ') ?: (string) ($imis['username'] ?? 'Staff');
    }

    /**
     * Copy the person's IMIS photo once, like a Google photo (see
     * ImportGoogleAvatar): TIMS keeps its own copy rather than linking to the
     * IMIS host. Run inside the sign-in rather than queued, unlike a Google
     * photo: IMIS has just answered the token request so it is up, the fetch is
     * capped (see GoogleAvatarFetcher), the person is watching the splash, and
     * the header should show the photo on the first page rather than whenever a
     * queue worker next runs. A failure just leaves initials. It never replaces
     * a photo the person already has.
     */
    private function importPhoto(User $user, array $imis): void
    {
        $url = $imis['photo_url'] ?? null;

        if (is_string($url) && blank($user->avatar_path) && GoogleAvatarFetcher::isImisUrl($url)) {
            ImportGoogleAvatar::dispatchSync($user->id, $url, 'imis');
        }
    }

    private function refuse(string $message): RedirectResponse
    {
        return redirect()->route('login')->withErrors(['form' => $message]);
    }
}
