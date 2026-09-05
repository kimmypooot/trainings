<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\FieldOffice;
use App\Models\User;
use App\Support\AccountAccess;
use App\Support\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(Request $request): Response
    {
        $search = $request->string('search')->toString();
        $role = $request->string('role')->toString();

        $users = User::query()
            ->with('fieldOffice')
            ->whereIn('role', array_map(fn (Role $role) => $role->value, Role::staff()))
            ->when($role, fn ($query) => $query->where('role', $role))
            ->when($search, fn ($query) => $query->where(fn ($inner) => $inner
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
            ))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        // HRD reads this screen as a directory; only a superadmin administers
        // the accounts on it. The routes are the actual guard — this only stops
        // the page offering controls that would 403 on click.
        $canManage = $request->user()->role === Role::SuperAdmin;

        return Inertia::render('Admin/Users/Index', [
            'canManage' => $canManage,
            'summary' => $this->summary(),
            'users' => $users->through(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role->value,
                'role_label' => $user->role->label(),
                'field_office' => $user->fieldOffice?->name,
                'is_active' => $user->is_active,
                // The single best "is this account stale or compromised" signal,
                // recorded on every successful sign-in.
                'last_login_at' => $user->last_login_at?->format('d M Y, g:i A'),
                'is_collecting_officer' => $user->is_collecting_officer,
                // Accounts left on the retired collecting-officer role. The
                // migration gave them the designation so nothing broke, but it
                // could not know which office they belong to — so they are
                // called out here for a superadmin to give a real role and, if
                // they are field office, an office to be scoped to.
                'needs_reassignment' => $user->role === Role::CollectingOfficer,
                'is_self' => $user->id === $request->user()->id,
                // Absent rather than empty for an admin: the edit screen is a
                // superadmin route, so there is no URL to offer.
                'edit_url' => $canManage ? route('admin.users.edit', $user) : null,
            ]),
            'filters' => ['search' => $search, 'role' => $role],
            'roles' => self::roleOptions(),
        ]);
    }

    /**
     * The directory at a glance, over the whole of it.
     *
     * Deliberately not narrowed by the search box or the role filter: this is
     * the shape of the staff roll, and a header figure that moved with the
     * filters would be answering a different question every keystroke — and
     * `useFilters` does not reload it, so it would go stale rather than
     * follow. The list below is what narrows.
     *
     * One conditional-aggregate query rather than four counts. "Never signed
     * in" is the figure worth having that nothing else on the page shows: an
     * account created and never used is either an onboarding that stalled or a
     * credential sitting unclaimed, and both want chasing.
     *
     * @return array<string, int>
     */
    private function summary(): array
    {
        $row = (array) User::query()
            ->whereIn('role', array_map(fn (Role $role) => $role->value, Role::staff()))
            ->toBase()
            ->selectRaw(
                'COUNT(*) as total,'
                .' SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active,'
                .' SUM(CASE WHEN is_collecting_officer = 1 THEN 1 ELSE 0 END) as collectors,'
                .' SUM(CASE WHEN last_login_at IS NULL THEN 1 ELSE 0 END) as never_signed_in'
            )
            ->first();

        return [
            'total' => (int) ($row['total'] ?? 0),
            'active' => (int) ($row['active'] ?? 0),
            'collectors' => (int) ($row['collectors'] ?? 0),
            'never_signed_in' => (int) ($row['never_signed_in'] ?? 0),
        ];
    }

    public function create(): Response
    {
        return Inertia::render('Admin/Users/Form', [
            'staffUser' => null,
            'roles' => self::roleOptions(),
            'fieldOffices' => FieldOffice::options(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'role' => ['required', Rule::in(self::assignableRoles())],
            'field_office_id' => [
                'nullable',
                Rule::requiredIf($request->input('role') === Role::FieldOffice->value),
                Rule::exists('field_offices', 'id'),
            ],
            'is_collecting_officer' => ['boolean'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ], [
            'field_office_id.required' => 'A field office account must be assigned to an office.',
        ]);

        // Role, office, and active flag are set explicitly rather than being
        // added to the model's fillable list — they decide access, and should
        // never be settable by a stray mass assignment elsewhere.
        $user = new User([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
        ]);

        $user->role = Role::from($validated['role']);
        $user->field_office_id = $this->officeFor($validated);
        $user->is_active = true;
        $user->is_collecting_officer = $validated['is_collecting_officer'] ?? false;
        $user->email_verified_at = now();
        // Staff do not fill in a participant profile.
        $user->profile_completed_at = now();
        $user->save();

        /*
         * Logged from the controller rather than a service, following
         * SubjectMatterExpertController and MaintenanceController: account
         * administration has no domain service to hang this off, and inventing
         * one to hold a single log call would be an abstraction with nothing
         * else in it.
         *
         * Creating a staff account is granting access, which makes it the same
         * class of event as the role change below — and neither of them was
         * recorded anywhere. "Who made this person an administrator" is the
         * first question an auditor asks and was the one the trail could not
         * answer.
         */
        ActivityLogger::record(
            'user.created',
            $user,
            sprintf('%s added as %s.', $user->name, $user->role->label()),
            [
                'role' => $user->role->value,
                'field_office_id' => $user->field_office_id,
                'is_collecting_officer' => $user->is_collecting_officer,
            ],
            $request->user(),
        );

        return redirect()
            ->route('admin.users.index')
            ->with('success', "{$user->name} has been added as {$user->role->label()}.");
    }

    public function edit(User $user): Response
    {
        abort_unless($user->role->isStaff(), 404);

        return Inertia::render('Admin/Users/Form', [
            'staffUser' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role->value,
                'field_office_id' => $user->field_office_id,
                'is_active' => $user->is_active,
                'is_collecting_officer' => $user->is_collecting_officer,
            ],
            'roles' => self::roleOptions(),
            'fieldOffices' => FieldOffice::options(),
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        abort_unless($user->role->isStaff(), 404);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'role' => ['required', Rule::in(self::assignableRoles())],
            'field_office_id' => [
                'nullable',
                Rule::requiredIf($request->input('role') === Role::FieldOffice->value),
                Rule::exists('field_offices', 'id'),
            ],
            'is_active' => ['boolean'],
            // The collecting-officer designation, assignable by a superadmin
            // on top of whatever role the person holds.
            'is_collecting_officer' => ['boolean'],
            // Optional: only set when the administrator wants to reset it.
            'password' => ['nullable', 'confirmed', Password::defaults()],
        ], [
            'field_office_id.required' => 'A field office account must be assigned to an office.',
        ]);

        if ($error = $this->guardSelfChanges($request, $user, $validated)) {
            return back()->withErrors(['role' => $error]);
        }

        if ($error = $this->guardLastSuperAdmin($user, $validated)) {
            return back()->withErrors(['role' => $error]);
        }

        // Read before the write, because the trail's whole value here is the
        // *previous* state: "who changed this" is answerable from causer_id
        // alone, but "changed it from what" is not recoverable afterwards.
        $before = [
            'role' => $user->role->value,
            'field_office_id' => $user->field_office_id,
            'is_collecting_officer' => $user->is_collecting_officer,
            'is_active' => $user->is_active,
            'email' => $user->email,
        ];

        $user->fill([
            'name' => $validated['name'],
            'email' => $validated['email'],
        ]);

        $user->role = Role::from($validated['role']);
        $user->field_office_id = $this->officeFor($validated);
        $user->is_active = $validated['is_active'] ?? true;
        $user->is_collecting_officer = $validated['is_collecting_officer'] ?? false;

        if (filled($validated['password'] ?? null)) {
            $user->password = $validated['password'];
        }

        $user->save();

        // Switching an account off here has to end its access, exactly as the
        // toggle below does — the same decision reached through a different
        // form. A role *downgrade* deliberately does not revoke: the account is
        // still the same person's, still theirs to keep working in, and the new
        // role is read from the record on every request anyway.
        if (! $user->is_active) {
            AccountAccess::revoke($user);
        }

        $after = [
            'role' => $user->role->value,
            'field_office_id' => $user->field_office_id,
            'is_collecting_officer' => $user->is_collecting_officer,
            'is_active' => $user->is_active,
            'email' => $user->email,
        ];

        /*
         * Only what actually moved.
         *
         * A form post carries every field whether or not it changed, so logging
         * the whole payload would bury a role change under four fields that
         * were re-submitted unchanged — and a trail nobody can scan is a trail
         * nobody reads, which is the failure mode LoginController's comment
         * describes for the login rows v1 kept.
         *
         * The password is deliberately absent from both sides: that it changed
         * is worth recording, what it changed to is not, and a hash in an audit
         * row is an offline cracking target sitting in a table more people can
         * read than can read `users`.
         */
        $changes = array_keys(array_diff_assoc($after, $before));

        if (filled($validated['password'] ?? null)) {
            $changes[] = 'password';
        }

        if ($changes !== []) {
            ActivityLogger::record(
                'user.updated',
                $user,
                sprintf('%s: %s changed.', $user->name, implode(', ', $changes)),
                [
                    'changed' => $changes,
                    'from' => array_intersect_key($before, array_flip($changes)),
                    'to' => array_intersect_key($after, array_flip($changes)),
                ],
                $request->user(),
            );
        }

        return redirect()
            ->route('admin.users.index')
            ->with('success', "{$user->name} has been updated.");
    }

    public function toggle(Request $request, User $user): RedirectResponse
    {
        abort_unless($user->role->isStaff(), 404);

        if ($user->id === $request->user()->id) {
            return back()->withErrors(['user' => 'You cannot deactivate your own account.']);
        }

        if ($user->is_active && $this->isLastActiveSuperAdmin($user)) {
            return back()->withErrors(['user' => 'At least one active super administrator must remain.']);
        }

        $user->is_active = ! $user->is_active;
        $user->save();

        // Deactivating is what the office reaches for when a staff member
        // leaves or an account is compromised, and in both of those the holder
        // is already signed in. Flipping the column alone left that session
        // working, so the button that reads "deactivate" has to actually end
        // the access rather than only forbid the next sign-in.
        if (! $user->is_active) {
            AccountAccess::revoke($user);
        }

        ActivityLogger::recordTransition(
            $user->is_active ? 'user.activated' : 'user.deactivated',
            $user,
            $user->is_active ? 'deactivated' : 'active',
            $user->is_active ? 'active' : 'deactivated',
            sprintf('%s is now %s.', $user->name, $user->is_active ? 'active' : 'deactivated'),
            ['role' => $user->role->value],
            $request->user(),
        );

        return back()->with(
            'success',
            "{$user->name} is now ".($user->is_active ? 'active' : 'deactivated').'.'
        );
    }

    /**
     * Only a field-office account carries an office; clear it otherwise so a
     * role change does not leave a stale assignment behind.
     *
     * @param  array<string, mixed>  $validated
     */
    private function officeFor(array $validated): ?int
    {
        return $validated['role'] === Role::FieldOffice->value
            ? (int) $validated['field_office_id']
            : null;
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function guardSelfChanges(Request $request, User $user, array $validated): ?string
    {
        if ($user->id !== $request->user()->id) {
            return null;
        }

        if ($validated['role'] !== $user->role->value) {
            return 'You cannot change your own role.';
        }

        if (($validated['is_active'] ?? true) === false) {
            return 'You cannot deactivate your own account.';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function guardLastSuperAdmin(User $user, array $validated): ?string
    {
        $losingSuperAdmin = $user->role === Role::SuperAdmin
            && ($validated['role'] !== Role::SuperAdmin->value || ($validated['is_active'] ?? true) === false);

        if ($losingSuperAdmin && $this->isLastActiveSuperAdmin($user)) {
            return 'At least one active super administrator must remain.';
        }

        return null;
    }

    private function isLastActiveSuperAdmin(User $user): bool
    {
        return User::where('role', Role::SuperAdmin)
            ->where('is_active', true)
            ->whereKeyNot($user->getKey())
            ->doesntExist();
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private static function roleOptions(): array
    {
        return array_map(
            fn (Role $role) => ['value' => $role->value, 'label' => $role->label()],
            self::assignable()
        );
    }

    /**
     * The staff roles a superadmin may actually hand out.
     *
     * Collecting officer is retired as a role: it is a designation now,
     * assignable alongside whatever job the person actually holds. The enum
     * case survives so accounts still carrying it keep casting — and so the
     * Users screen can flag them for reassignment — but it can no longer be
     * chosen. Enforced in validation as well as in the dropdown, or the
     * dropdown is just a suggestion.
     *
     * @return array<int, Role>
     */
    private static function assignable(): array
    {
        return array_values(array_filter(
            Role::staff(),
            fn (Role $role) => $role !== Role::CollectingOfficer
        ));
    }

    /** @return array<int, string> */
    private static function assignableRoles(): array
    {
        return array_map(fn (Role $role) => $role->value, self::assignable());
    }
}
