<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\FieldOffice;
use App\Models\User;
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

        return Inertia::render('Admin/Users/Index', [
            'users' => $users->through(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role->value,
                'role_label' => $user->role->label(),
                'field_office' => $user->fieldOffice?->name,
                'is_active' => $user->is_active,
                'is_collecting_officer' => $user->is_collecting_officer,
                // Accounts left on the retired collecting-officer role. The
                // migration gave them the designation so nothing broke, but it
                // could not know which office they belong to — so they are
                // called out here for a superadmin to give a real role and, if
                // they are field office, an office to be scoped to.
                'needs_reassignment' => $user->role === Role::CollectingOfficer,
                'is_self' => $user->id === $request->user()->id,
                'edit_url' => route('admin.users.edit', $user),
            ]),
            'filters' => ['search' => $search, 'role' => $role],
            'roles' => self::roleOptions(),
        ]);
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
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
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
            'password' => ['nullable', 'confirmed', Password::min(8)->letters()->numbers()],
        ], [
            'field_office_id.required' => 'A field office account must be assigned to an office.',
        ]);

        if ($error = $this->guardSelfChanges($request, $user, $validated)) {
            return back()->withErrors(['role' => $error]);
        }

        if ($error = $this->guardLastSuperAdmin($user, $validated)) {
            return back()->withErrors(['role' => $error]);
        }

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
