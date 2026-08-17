<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\FieldOffice;
use App\Models\Registration;
use App\Support\RevenueService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class FieldOfficeController extends Controller
{
    public function index(): Response
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
                'view_url' => route('admin.field-offices.show', $office),
                'edit_url' => route('admin.field-offices.edit', $office),
            ])->all(),
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

        $registrations = Registration::with(['training', 'payments', 'user'])
            ->whereHas('user.profile', fn ($query) => $query->where('field_office_id', $fieldOffice->getKey()))
            ->get();

        $settled = $registrations->filter(fn (Registration $r) => $r->hasSettledFee())->count();
        $revenue = $this->revenueFor($registrations);

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
                'registrations' => $registrations->count(),
                'settled' => $settled,
                'outstanding' => $registrations->count() - $settled,
                'collected' => $revenue['collected'],
                'promissory' => $revenue['promissory'],
            ],
            'recent' => $registrations
                ->sortByDesc('created_at')
                ->take(8)
                ->map(fn (Registration $registration) => [
                    'id' => $registration->id,
                    'participant' => $registration->user->name,
                    'training' => $registration->training->title,
                    'status' => $registration->status->value,
                    'status_label' => $registration->status->label(),
                    'registered_at' => $registration->created_at?->format('d M Y'),
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
     * Offices are deactivated rather than deleted — participant profiles point
     * at them, and history must stay readable.
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
     * What this office's registrations actually brought in.
     *
     * Same rules as the training-level report: only verified payments count,
     * and a promissory note is money promised, not money arrived — counted
     * apart so the outstanding balance stays visible.
     */
    private function revenueFor($registrations): array
    {
        $verified = $registrations->flatMap(
            fn (Registration $registration) => $registration->payments
                ->filter(fn ($payment) => $payment->status === PaymentStatus::Verified)
        );

        return RevenueService::summarize($verified);
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
