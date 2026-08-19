<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class LoginController extends Controller
{
    /**
     * Show the login screen.
     */
    public function create(Request $request): Response
    {
        return Inertia::render('Auth/Login', [
            'status' => $request->session()->get('status'),
            'googleEnabled' => (bool) config('services.google.client_id'),
        ]);
    }

    /**
     * Handle a login attempt.
     */
    public function store(Request $request): Response|RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $throttleKey = Str::transliterate(Str::lower($credentials['email']).'|'.$request->ip());

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            throw ValidationException::withMessages([
                'form' => 'Too many login attempts. Please try again in '
                    .ceil(RateLimiter::availableIn($throttleKey) / 60).' minute(s).',
            ]);
        }

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            RateLimiter::hit($throttleKey);

            return back()->withErrors([
                'form' => 'Those credentials do not match our records.',
            ])->onlyInput('email');
        }

        // A deactivated account must not get a session, even with the right
        // password. Checked after the attempt so the message cannot be used to
        // probe which addresses exist.
        if (! $request->user()->is_active) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return back()->withErrors([
                'form' => 'This account has been deactivated. Contact the CSC administrator.',
            ])->onlyInput('email');
        }

        // An unverified email blocks the sign-in — but only once the profile
        // exists. Right after registration the participant has no profile yet
        // and is sent to the gate form instead, so the verification deadline is
        // "before you can use the system", not "before you can finish signing
        // up". Rendered directly (not a redirect + flash) because the browser
        // drops the X-Inertia header when following the 302 back to this same
        // page, which would consume the one-shot flash and leave the "Email Not
        // Verified" card invisible. The POST's own response carries the email.
        if (! $request->user()->hasVerifiedEmail() && $request->user()->hasCompletedProfile()) {
            $unverifiedEmail = $request->user()->email;

            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return Inertia::render('Auth/Login', [
                'status' => null,
                'googleEnabled' => (bool) config('services.google.client_id'),
                'unverified_email' => $unverifiedEmail,
            ]);
        }

        RateLimiter::clear($throttleKey);
        $request->session()->regenerate();

        // One-shot flag read by app.js on boot. It only matters for a sign-in
        // that arrives on a fresh document — the Google round trip — where the
        // login page's splash died with the old JS context and the welcome beat
        // has to be replayed from scratch. See resources/js/authSplash.js.
        $request->session()->flash('just_logged_in', true);

        // A single column rather than a login row per sign-in. v1's activity
        // log was mostly login/logout pairs, and that volume is exactly what
        // buried the decisions worth auditing — this keeps the one part of it
        // staff actually read, which is how a dormant account gets noticed.
        $request->user()->forceFill(['last_login_at' => now()])->save();

        if ($request->user()->role->isStaff()) {
            return redirect()->intended(route('admin.dashboard'));
        }

        if (! $request->user()->hasCompletedProfile()) {
            return redirect()->route('profile.complete');
        }

        return redirect()->intended(route('dashboard'));
    }

    /**
     * Log the user out.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}
