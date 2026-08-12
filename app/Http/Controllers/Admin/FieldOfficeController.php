<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FieldOffice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class FieldOfficeController extends Controller
{
    public function index(): Response
    {
        $offices = FieldOffice::withCount('profiles')->orderBy('name')->get();

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
