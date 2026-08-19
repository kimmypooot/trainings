<?php

namespace App\Models;

use App\Enums\Role;
use App\Notifications\ResetPassword;
use App\Notifications\VerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail as MustVerifyEmailContract;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

#[Fillable(['name', 'email', 'password', 'google_id', 'google_email'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmailContract
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Database defaults are not reflected on a freshly created model instance,
     * so the role is defaulted here too.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'role' => Role::Participant->value,
        // Same reason as the role, and it bites harder: a brand-new Google
        // sign-up is checked for `is_active` in the same request it is created
        // in, and an unset attribute reads as false — turning every first-time
        // Google sign-up away as "deactivated".
        'is_active' => true,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'profile_completed_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'role' => Role::class,
            'is_active' => 'boolean',
            'is_collecting_officer' => 'boolean',
        ];
    }

    /**
     * Whether this staff member is designated to collect money.
     *
     * A designation, not a role — which is how v1 has it, and the only shape
     * that fits the office: a field office's collecting officer is a
     * field-office user who has been designated, and must keep their office
     * scoping while doing it. Admins and superadmins carry the ability by
     * virtue of their role, as they do everywhere else.
     *
     * Nothing checks the retired collecting-officer role any more; accounts
     * that still hold it were given this flag by migration and show up in the
     * Users screen for reassignment.
     */
    public function collectsPayments(): bool
    {
        return $this->is_collecting_officer || in_array($this->role, Role::financial(), true);
    }

    /**
     * Whether this user may read a refund payee's full bank account number.
     *
     * Narrower than collectsPayments(): everyone who reaches the money screens
     * can open the refund queue, but only the person actually cutting the
     * transfer needs the account number. HRD reviews whether a claim is valid,
     * which needs the amount and the reason and nothing else — so the number
     * reaches them masked. Worth keeping even though both are trusted: the
     * number sits on screen far longer than it is needed, usually in a shared
     * office.
     */
    public function seesBankDetails(): bool
    {
        return $this->is_collecting_officer || $this->role === Role::SuperAdmin;
    }

    /**
     * Whether this account is linked to a Google identity.
     */
    public function hasGoogleAccount(): bool
    {
        return filled($this->google_id);
    }

    /**
     * Whether this account can be signed in to without Google.
     *
     * `password` is nullable because an account created through Google never
     * set one. Anything that would remove the Google identity has to check
     * this first, or it locks the participant out.
     */
    public function hasPassword(): bool
    {
        return filled($this->password);
    }

    /**
     * The photo to render, or null to fall back to initials.
     *
     * There is one photo and one place it lives. A photo imported from a
     * linked Google account is stored here like any other, so nothing at
     * render time needs to know where it came from — see
     * App\Jobs\ImportGoogleAvatar for why the Google image is copied rather
     * than linked.
     *
     * The file is on the private disk, so its URL is the authorising stream
     * route, keyed by `updated_at` to bust the browser cache when the photo is
     * replaced.
     */
    public function avatarUrl(): ?string
    {
        if (blank($this->avatar_path)) {
            return null;
        }

        return route('profile.photo.show', [
            'user' => $this->getKey(),
            'v' => $this->updated_at?->timestamp,
        ]);
    }

    /**
     * The given name to greet this account by, or null if there is nothing to
     * greet — callers drop the name rather than address an empty string.
     *
     * Read off `users.name` rather than the profile on purpose. The column is
     * already composed from the profile as "First M. Last Suffix" (see
     * ProfileController), so its first word is the given name for participants
     * and works for staff and Google accounts too — and it is loaded with the
     * user, so a greeting shared on every request costs no extra query.
     *
     * Title-cased because most accounts store the name upper-cased, which reads
     * as shouting in a greeting.
     */
    public function firstName(): ?string
    {
        $given = Str::of($this->name)->trim()->explode(' ')->first();

        return filled($given) ? Str::title($given) : null;
    }

    public function isParticipant(): bool
    {
        return $this->role === Role::Participant;
    }

    public function fieldOffice(): BelongsTo
    {
        return $this->belongsTo(FieldOffice::class);
    }

    /**
     * Whether this user only sees the participants of one field office.
     *
     * Applies to the field-office role alone: HRD, management, and superadmin
     * see the whole region.
     */
    public function isScopedToFieldOffice(): bool
    {
        return $this->role === Role::FieldOffice;
    }

    /**
     * The office id this user is limited to, or null when unrestricted.
     *
     * A field-office user with no office assigned resolves to 0, which matches
     * nothing — failing closed rather than exposing every participant.
     */
    public function scopedFieldOfficeId(): ?int
    {
        if (! $this->isScopedToFieldOffice()) {
            return null;
        }

        return $this->field_office_id ?? 0;
    }

    /**
     * The participant's stable check-in token, minted on first use.
     */
    public function ensureQrToken(): string
    {
        if (! $this->qr_token) {
            $this->forceFill(['qr_token' => Str::random(32)])->save();
        }

        return $this->qr_token;
    }

    /**
     * Invalidate the current code and issue a new one.
     */
    public function regenerateQrToken(): string
    {
        $this->forceFill(['qr_token' => Str::random(32)])->save();

        return $this->qr_token;
    }

    public function profile(): HasOne
    {
        return $this->hasOne(Profile::class);
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(Registration::class);
    }

    public function hasCompletedProfile(): bool
    {
        return $this->profile_completed_at !== null;
    }

    /**
     * Send the password reset link through the app's branded notification.
     *
     * Called by the password broker during a reset request; the email address
     * rides along so the reset page can be pre-filled without the visitor
     * retyping it.
     */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPassword($token, $this->email));
    }

    /**
     * The verification email, through the app's branded notification.
     *
     * Overriding the trait method is what keeps the framework's auto-sent
     * verification link (fired by the Registered event when a model implements
     * MustVerifyEmail) looking like every other email this office sends.
     */
    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new VerifyEmail);
    }
}
