<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SmeEvaluation;
use App\Models\SubjectMatterExpert;
use App\Models\Training;
use App\Support\ActivityLogger;
use App\Support\SmeEvaluationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * SME management: the directory of resource persons the office draws on.
 *
 * Modelled on the field-office screens because it is the same kind of thing —
 * reference data that other records point at. In particular it follows the same
 * deactivate-never-delete rule, and for a stronger reason: an expert carries
 * participants' evaluations of them, and deleting the row would either destroy
 * that history or leave it pointing nowhere. The database refuses the delete
 * outright (see the restrictOnDelete on sme_evaluations), so deactivation is
 * not merely the recommended path, it is the only one.
 */
class SubjectMatterExpertController extends Controller
{
    public function index(Request $request): Response
    {
        $experts = SubjectMatterExpert::withCount(['trainings', 'evaluations'])
            ->orderBy('name')
            ->get();

        // One aggregate query for the whole directory rather than a summary
        // call per row: the list shows a rating beside every name, and a page
        // of forty experts should not be forty AVG() round trips.
        $averages = SmeEvaluation::query()
            ->selectRaw('subject_matter_expert_id, '.self::meanExpression().' as average')
            ->groupBy('subject_matter_expert_id')
            ->pluck('average', 'subject_matter_expert_id');

        return Inertia::render('Admin/SubjectMatterExperts/Index', [
            'experts' => $experts->map(fn (SubjectMatterExpert $expert) => [
                'id' => $expert->id,
                'name' => $expert->name,
                'position' => $expert->position,
                'organization' => $expert->organization,
                'email' => $expert->email,
                'contact_number' => $expert->contact_number,
                'expertise' => $expert->expertise,
                'is_active' => $expert->is_active,
                'trainings' => $expert->trainings_count,
                'responses' => $expert->evaluations_count,
                'average' => $averages->has($expert->id)
                    ? round((float) $averages[$expert->id], 2)
                    : null,
                'view_url' => route('admin.smes.show', $expert),
                'edit_url' => route('admin.smes.edit', $expert),
            ])->all(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/SubjectMatterExperts/Form', ['expert' => null]);
    }

    public function store(Request $request): RedirectResponse
    {
        $expert = SubjectMatterExpert::create([
            ...$this->validated($request),
            'created_by' => $request->user()->getKey(),
        ]);

        ActivityLogger::record(
            'sme.created',
            $expert,
            "Subject matter expert “{$expert->name}” was added.",
        );

        return redirect()
            ->route('admin.smes.index')
            ->with('success', "“{$expert->name}” has been added.");
    }

    /**
     * One expert's record and how participants have rated them.
     *
     * The point of the page: before assigning somebody to a run, a coordinator
     * wants to see what the last three rooms thought of them. That is the whole
     * reason evaluations are tied to a person rather than to a training.
     */
    public function show(SubjectMatterExpert $expert): Response
    {
        $expert->loadCount(['trainings', 'evaluations']);

        $trainings = $expert->trainings()
            ->orderByDesc('starts_at')
            ->get()
            ->map(fn (Training $training) => [
                'id' => $training->id,
                'title' => $training->title,
                'training_code' => $training->training_code,
                'starts_at' => $training->starts_at->format('d M Y'),
                'status_label' => $training->status->label(),
                'topic' => $training->pivot->topic,
                'results_url' => route('admin.trainings.evaluations', $training),
            ])
            ->all();

        return Inertia::render('Admin/SubjectMatterExperts/Show', [
            'expert' => [
                'id' => $expert->id,
                'name' => $expert->name,
                'display_name' => $expert->displayName(),
                'position' => $expert->position,
                'organization' => $expert->organization,
                'email' => $expert->email,
                'contact_number' => $expert->contact_number,
                'expertise' => $expert->expertise,
                'bio' => $expert->bio,
                'remarks' => $expert->remarks,
                'is_active' => $expert->is_active,
                'trainings_count' => $expert->trainings_count,
                'edit_url' => route('admin.smes.edit', $expert),
            ],
            'assignments' => $trainings,
            'summary' => SmeEvaluationService::summaryForExpert($expert),
            ...SmeEvaluationService::formDefinition(),
        ]);
    }

    public function edit(SubjectMatterExpert $expert): Response
    {
        return Inertia::render('Admin/SubjectMatterExperts/Form', [
            'expert' => [
                'id' => $expert->id,
                'name' => $expert->name,
                'position' => $expert->position,
                'organization' => $expert->organization,
                'email' => $expert->email,
                'contact_number' => $expert->contact_number,
                'expertise' => $expert->expertise,
                'bio' => $expert->bio,
                'remarks' => $expert->remarks,
                'is_active' => $expert->is_active,
            ],
        ]);
    }

    public function update(Request $request, SubjectMatterExpert $expert): RedirectResponse
    {
        $expert->update($this->validated($request, $expert));

        ActivityLogger::record(
            'sme.updated',
            $expert,
            "Subject matter expert “{$expert->name}” was updated.",
        );

        return redirect()
            ->route('admin.smes.index')
            ->with('success', "“{$expert->name}” has been updated.");
    }

    /**
     * Retire or reinstate an expert.
     *
     * Deactivating only removes them from the picker on new assignments. Runs
     * that already carry them are untouched — pulling somebody off a programme
     * that has already been announced is a decision about that programme, made
     * on that programme's form.
     */
    public function toggle(SubjectMatterExpert $expert): RedirectResponse
    {
        $expert->update(['is_active' => ! $expert->is_active]);

        ActivityLogger::record(
            $expert->is_active ? 'sme.activated' : 'sme.deactivated',
            $expert,
            "Subject matter expert “{$expert->name}” was "
                .($expert->is_active ? 'reactivated' : 'deactivated').'.',
        );

        return back()->with(
            'success',
            "“{$expert->name}” is now ".($expert->is_active ? 'active' : 'inactive').'.'
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?SubjectMatterExpert $expert = null): array
    {
        return $request->validate([
            /*
             * Unique on the name, case-insensitively as MySQL compares it. Two
             * rows for the same person are the failure this whole table exists
             * to prevent: the ratings would split between them and neither
             * record would tell the truth.
             */
            'name' => [
                'required', 'string', 'max:160',
                Rule::unique('subject_matter_experts', 'name')->ignore($expert),
            ],
            'position' => ['nullable', 'string', 'max:160'],
            'organization' => ['nullable', 'string', 'max:160'],
            'email' => ['nullable', 'email', 'max:255'],
            'contact_number' => ['nullable', 'string', 'max:32'],
            'expertise' => ['nullable', 'string', 'max:2000'],
            'bio' => ['nullable', 'string', 'max:5000'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['boolean'],
        ]);
    }

    /**
     * The mean of the four criteria as one SQL expression.
     *
     * Built from SmeEvaluation::CRITERIA rather than typed out, so a fifth
     * criterion is picked up here without anybody remembering to.
     */
    private static function meanExpression(): string
    {
        $columns = array_keys(SmeEvaluation::CRITERIA);

        return '('.implode(' + ', array_map(fn (string $column) => "AVG({$column})", $columns))
            .') / '.count($columns);
    }
}
