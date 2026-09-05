<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\FieldOffice;
use App\Models\Payment;
use App\Models\Registration;
use App\Support\ActivityLogger;
use App\Support\RevenueService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class FieldOfficeController extends Controller
{
    public function index(Request $request): Response
    {
        // Both counts, because the row renders both. `users_count` was read
        // below without being loaded, which Eloquent answers with null rather
        // than an error — so the Staff column was simply blank.
        $offices = FieldOffice::withCount(['profiles', 'users'])->orderBy('name')->get();

        return Inertia::render('Admin/FieldOffices/Index', [
            'offices' => $offices->map(fn (FieldOffice $office) => [
                'id' => $office->id,
                'code' => $office->code,
                'name' => $office->name,
                'type' => $office->type,
                'type_label' => $office->typeLabel(),
                'province' => $office->province,
                'jurisdiction' => $office->jurisdiction ?? [],
                'email' => $office->email,
                'contact_number' => $office->contact_number,
                'head_name' => $office->head_name,
                'head_position' => $office->head_position,
                'is_active' => $office->is_active,
                'participants' => $office->profiles_count,
                'staff' => $office->users_count,
                // An office nobody is attached to can be removed outright; one
                // with people on it can only be deactivated. Sent per row so
                // the screen can say which, and why, rather than leaving a
                // superadmin to discover it by pressing the button.
                'can_delete' => $office->profiles_count === 0 && $office->users_count === 0,
                'view_url' => route('admin.field-offices.show', $office),
                'edit_url' => route('admin.field-offices.edit', $office),
            ])->all(),
            // Deleting is not reversible, so it is held one role above the rest
            // of this screen — see the destroy() docblock.
            'canDelete' => $request->user()->role === Role::SuperAdmin,
            'types' => collect(FieldOffice::TYPES)
                ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                ->values()
                ->all(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/FieldOffices/Form', [
            'office' => null,
            'types' => collect(FieldOffice::TYPES)
                ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                ->values()
                ->all(),
        ]);
    }

    /**
     * The office's own dashboard. Everything is derived from the participant
     * profiles that point at it (and, for staff, the users assigned to it) —
     * the office is an administrative grouping, not a table of events.
     */
    public function show(FieldOffice $fieldOffice): Response
    {
        $fieldOffice->loadCount(['profiles', 'users']);

        // Counted in the database rather than by hydrating the office's whole
        // history to call ->count() on it. A mature office has thousands of
        // registrations and this page shows six numbers and eight rows; pulling
        // every model (with its training, payments and user) to produce that is
        // a cost that grows with the office for no gain.
        $total = $this->registrationsIn($fieldOffice)->count();

        // The SQL reading of Registration::hasSettledFee(): a free training is
        // settled by definition, otherwise the fee needs a verified payment.
        $settled = $this->registrationsIn($fieldOffice)
            ->where(fn ($query) => $query
                ->whereHas('training', fn ($training) => $training->where('payment_required', false))
                ->orWhereHas('payments', fn ($payment) => $payment->where('status', PaymentStatus::Verified))
            )
            ->count();

        $revenue = $this->revenueFor($fieldOffice);

        $recent = $this->registrationsIn($fieldOffice)
            ->with(['training', 'user'])
            // registered_at, not created_at: the row's insert time is the
            // import's clock, not the participant's. SampleActivitySeeder
            // backdates one and leaves the other, so ordering by created_at
            // put every seeded office's "recent" list in one undifferentiated
            // block dated today.
            ->orderByDesc('registered_at')
            ->limit(8)
            ->get();

        return Inertia::render('Admin/FieldOffices/Show', [
            'office' => [
                'id' => $fieldOffice->id,
                'code' => $fieldOffice->code,
                'name' => $fieldOffice->name,
                'type_label' => $fieldOffice->typeLabel(),
                'province' => $fieldOffice->province,
                'jurisdiction' => $fieldOffice->jurisdiction ?? [],
                'address' => $fieldOffice->address,
                'contact_number' => $fieldOffice->contact_number,
                'email' => $fieldOffice->email,
                'head_name' => $fieldOffice->head_name,
                'head_position' => $fieldOffice->head_position,
                'remarks' => $fieldOffice->remarks,
                'is_active' => $fieldOffice->is_active,
                'edit_url' => route('admin.field-offices.edit', $fieldOffice),
            ],
            'stats' => [
                'participants' => $fieldOffice->profiles_count,
                'staff' => $fieldOffice->users_count,
                'registrations' => $total,
                'settled' => $settled,
                'outstanding' => $total - $settled,
                'collected' => $revenue['collected'],
                'promissory' => $revenue['promissory'],
            ],
            'recent' => $recent
                ->map(fn (Registration $registration) => [
                    'id' => $registration->id,
                    'participant' => $registration->user->name,
                    'training' => $registration->training->title,
                    'status' => $registration->status->value,
                    'status_label' => $registration->status->label(),
                    'registered_at' => $registration->registered_at?->format('d M Y'),
                    'roster_url' => route('admin.trainings.roster', $registration->training),
                ])
                ->values()
                ->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $office = FieldOffice::create($this->validated($request));

        return redirect()
            ->route('admin.field-offices.index')
            ->with('success', "“{$office->name}” has been added.");
    }

    public function edit(FieldOffice $fieldOffice): Response
    {
        return Inertia::render('Admin/FieldOffices/Form', [
            'office' => [
                'id' => $fieldOffice->id,
                'code' => $fieldOffice->code,
                'name' => $fieldOffice->name,
                'type' => $fieldOffice->type,
                'province' => $fieldOffice->province,
                'jurisdiction' => implode(', ', $fieldOffice->jurisdiction ?? []),
                'address' => $fieldOffice->address,
                'contact_number' => $fieldOffice->contact_number,
                'email' => $fieldOffice->email,
                'head_name' => $fieldOffice->head_name,
                'head_position' => $fieldOffice->head_position,
                'is_active' => $fieldOffice->is_active,
                'remarks' => $fieldOffice->remarks,
            ],
            'types' => collect(FieldOffice::TYPES)
                ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                ->values()
                ->all(),
        ]);
    }

    public function update(Request $request, FieldOffice $fieldOffice): RedirectResponse
    {
        $fieldOffice->update($this->validated($request, $fieldOffice));

        return redirect()
            ->route('admin.field-offices.index')
            ->with('success', "“{$fieldOffice->name}” has been updated.");
    }

    /**
     * An office anyone is attached to is deactivated rather than deleted —
     * participant profiles point at them, and history must stay readable. An
     * office nobody is attached to can be removed outright; see destroy().
     */
    public function toggle(FieldOffice $fieldOffice): RedirectResponse
    {
        $fieldOffice->update(['is_active' => ! $fieldOffice->is_active]);

        return back()->with(
            'success',
            "“{$fieldOffice->name}” is now ".($fieldOffice->is_active ? 'active' : 'inactive').'.'
        );
    }

    /**
     * Remove an office nothing points at.
     *
     * Deactivate-never-delete is the right rule for a row with people attached,
     * and it was applied to every row — which left no way at all to remove one
     * created by mistake. A deployment being set up for a new region has a
     * whole list of offices belonging to somebody else, and an office typed in
     * wrongly five minutes ago has no history to protect; both could only ever
     * be deactivated, so they stayed in the database and in the audit trail for
     * good.
     *
     * The bound is attachment, not age. Both foreign keys are `nullOnDelete`,
     * so deleting an office anyone points at would silently blank the link
     * rather than refuse: participants would lose the office on their profile
     * with nothing recording it, and a field-office staff member would lose the
     * assignment their whole view is scoped by — `scopedFieldOfficeId()` then
     * resolves to 0 and matches nothing, so their account keeps working while
     * showing them an empty system. That is exactly the kind of quiet wrong
     * this codebase keeps having to dig out, so it is refused here instead.
     *
     * Superadmin only, unlike the rest of this screen: creating and editing an
     * office is administration, removing one is not reversible.
     */
    public function destroy(Request $request, FieldOffice $fieldOffice): RedirectResponse
    {
        $fieldOffice->loadCount(['profiles', 'users']);

        if ($fieldOffice->profiles_count > 0 || $fieldOffice->users_count > 0) {
            return back()->with('error', sprintf(
                '“%s” cannot be deleted — %s and %s are still assigned to it. Deactivate it instead, which stops it being chosen on new profiles while keeping existing records readable.',
                $fieldOffice->name,
                $this->countPhrase($fieldOffice->profiles_count, 'participant'),
                $this->countPhrase($fieldOffice->users_count, 'staff member'),
            ));
        }

        // Captured before the row goes: an audit entry naming an id nobody can
        // look up afterwards is not a record of anything.
        $office = [
            'code' => $fieldOffice->code,
            'name' => $fieldOffice->name,
            'type' => $fieldOffice->type,
            'province' => $fieldOffice->province,
        ];

        $fieldOffice->delete();

        ActivityLogger::record(
            'field-office.deleted',
            null,
            "Field office “{$office['name']}” was deleted. Nothing was assigned to it.",
            $office,
            $request->user(),
        );

        return redirect()
            ->route('admin.field-offices.index')
            ->with('success', "“{$office['name']}” has been deleted.");
    }

    /** "3 participants", "1 staff member", "no participants". */
    private function countPhrase(int $count, string $noun): string
    {
        return match (true) {
            $count === 0 => "no {$noun}s",
            $count === 1 => "1 {$noun}",
            default => "{$count} {$noun}s",
        };
    }

    /**
     * What this office's registrations actually brought in.
     *
     * Same rules as the training-level report: only verified payments count,
     * and a promissory note is money promised, not money arrived — counted
     * apart so the outstanding balance stays visible.
     */
    private function revenueFor(FieldOffice $office): array
    {
        // Only the verified payments are fetched, rather than every
        // registration in order to reach them. Still a hydrate — the rules for
        // what counts as collected live in RevenueService and are worth reading
        // through rather than restating as SQL here, which is exactly the drift
        // that made the money reports disagree in the first place.
        $verified = Payment::where('status', PaymentStatus::Verified)
            ->whereHas(
                'registration.user.profile',
                fn ($query) => $query->where('field_office_id', $office->getKey())
            )
            ->get();

        return RevenueService::summarize($verified);
    }

    /**
     * The registrations belonging to an office, as a query rather than a
     * result — so the caller can count them, or take eight, without the whole
     * history passing through PHP.
     */
    private function registrationsIn(FieldOffice $office): Builder
    {
        return Registration::whereHas(
            'user.profile',
            fn ($query) => $query->where('field_office_id', $office->getKey())
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?FieldOffice $office = null): array
    {
        $validated = $request->validate([
            'code' => [
                'required', 'string', 'max:16',
                Rule::unique('field_offices', 'code')->ignore($office?->getKey()),
            ],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(array_keys(FieldOffice::TYPES))],
            'province' => ['required', 'string', 'max:64'],
            'jurisdiction' => ['nullable', 'string', 'max:500'],
            'address' => ['nullable', 'string', 'max:500'],
            'contact_number' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:128'],
            'head_name' => ['nullable', 'string', 'max:128'],
            'head_position' => ['nullable', 'string', 'max:128'],
            'is_active' => ['boolean'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        // Jurisdiction is entered as a comma-separated list and stored as JSON.
        $validated['jurisdiction'] = collect(explode(',', $validated['jurisdiction'] ?? ''))
            ->map(fn (string $province) => trim($province))
            ->filter()
            ->values()
            ->all();

        $validated['code'] = mb_strtolower($validated['code']);

        return $validated;
    }
}
