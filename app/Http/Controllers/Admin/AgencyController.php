<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\FieldOffice;
use App\Models\Profile;
use App\Support\ActivityLogger;
use App\Support\ProfileOptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The employer list behind the profile form's picker.
 *
 * `agencies` shipped as seeder-only reference data: 285 rows loaded from
 * database/data/agencies.json, with no way to correct one or add one short of
 * editing the file and redeploying. The other two reference tables — field
 * offices and subject matter experts — have had admin screens from the start,
 * and Agency's own docblock names this one as the obvious next thing. This is
 * it.
 *
 * **The list going stale is a silent failure, which is why this screen leads
 * with the gap rather than the list.** A participant who cannot find their
 * employer types it instead: `agency_id` stays null, `organization_name` is
 * still written, the registration completes, and nobody is told. Before this
 * screen `agency_id` was written by ProfileService and then read by *nothing*
 * — no filter, no export, no report — so a missing agency was invisible while
 * quietly recreating the "DEPED / DepEd / Department of Education" split the
 * table exists to end. The typed employers are therefore the first panel on
 * the page, counted and ranked, each one click from becoming a row.
 *
 * The bias in that drift is the reason it matters: the seeded list came from
 * an existing directory, which covers national agencies well and small ones
 * badly, so the people who end up typing are disproportionately from small
 * agencies. Left alone the data stays clean for the Department of Education
 * and gets steadily messier for every water district in the region.
 *
 * admin|superadmin, matching field offices: this is region-wide reference
 * data, and a field-office user editing it would be changing what every other
 * office sees.
 */
class AgencyController extends Controller
{
    /**
     * How many typed employers to offer. Long enough to be a worklist, short
     * enough to stay a prompt rather than a second table — the tail of
     * one-offs is mostly spelling variants of rows already above it.
     */
    private const UNLISTED_SHOWN = 12;

    public function index(Request $request): Response
    {
        $search = $request->string('search')->toString();
        $sector = $request->string('sector')->toString();
        $officeId = $request->string('field_office_id')->toString();
        $status = $request->string('status')->toString();

        $agencies = Agency::query()
            ->withCount('profiles')
            ->with('fieldOffice:id,name')
            ->when($search, fn ($query, $s) => $query->where(
                fn ($inner) => $inner->where('name', 'like', "%{$s}%")
                    ->orWhere('acronym', 'like', "%{$s}%")
            ))
            ->when($sector, fn ($query, $s) => $query->where('sector', $s))
            ->when($officeId, fn ($query, $id) => $query->where('field_office_id', $id))
            ->when($status === 'active', fn ($query) => $query->where('is_active', true))
            ->when($status === 'inactive', fn ($query) => $query->where('is_active', false))
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Admin/Agencies/Index', [
            'agencies' => $agencies->through(fn (Agency $agency) => [
                'id' => $agency->id,
                'name' => $agency->name,
                'acronym' => $agency->acronym,
                'sector' => $agency->sector,
                'field_office' => $agency->fieldOffice?->name,
                'is_active' => $agency->is_active,
                'participants' => $agency->profiles_count,
                // An agency nobody is filed under can be removed outright; one
                // with people on it can only be deactivated. Sent per row so
                // the screen can say which, rather than leaving a superadmin to
                // find out by pressing the button.
                'can_delete' => $agency->profiles_count === 0,
                'edit_url' => route('admin.agencies.edit', $agency),
            ]),
            'filters' => [
                'search' => $search,
                'sector' => $sector,
                'field_office_id' => $officeId,
                'status' => $status,
            ],
            'counts' => $this->counts(),
            'unlisted' => $this->unlisted(),
            'sectors' => $this->sectorOptions(),
            'offices' => $this->officeOptions(),
            // Every active agency, for matching a typed shortcut against the
            // list. Reuses Agency::options() so the combobox here searches on
            // acronym exactly as the participant's own picker does — which is
            // the whole point when the typed value *is* an acronym.
            'agencyOptions' => Agency::options(),
            // Deleting is not reversible, so it is held one role above the rest
            // of this screen — the same split field offices use.
            'canDelete' => $request->user()->role === Role::SuperAdmin,
        ]);
    }

    public function create(Request $request): Response
    {
        $prefill = $request->string('name')->toString() ?: null;

        return Inertia::render('Admin/Agencies/Form', [
            'agency' => null,
            // Arrives when the row is being created from a typed employer on
            // the index, so the name is already filled in and the person is
            // choosing a sector and an office rather than retyping what is on
            // the screen in front of them.
            'prefillName' => $prefill,
            // How many people typed exactly this, and so will be moved onto the
            // agency the moment it exists. Shown on the form, because it is the
            // difference between adding a row and resolving a backlog.
            'claimCount' => $prefill === null ? 0 : Profile::query()
                ->whereNull('agency_id')
                ->where('organization_name', $prefill)
                ->count(),
            'sectors' => $this->sectorOptions(),
            'offices' => $this->officeOptions(),
            'linkedProfiles' => 0,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $agency = Agency::create($this->validated($request));

        /*
         * A code, assigned rather than asked for.
         *
         * It marks provenance — `ui-` is a row somebody added on this screen,
         * `jp-<n>` is one that came from the imported source list — and gives
         * the row a stable key of its own, which is what `agencies.code` is
         * for everywhere else.
         *
         * It is explicitly **not** what stops AgencySeeder duplicating this row
         * when the source list later gains the same agency. Nothing here could
         * be: the seeder walks the *file's* rows and looks each one up by its
         * own code, so a row added here is never matched by whatever code it
         * carries. That case is handled in the seeder, which adopts a row
         * already holding the name instead of inserting a second one — see the
         * comment there, and AgencyManagementTest.
         *
         * Deliberately not a form field. A code is meaningless to the person
         * adding a water district, and the only thing they could do with it is
         * collide with one.
         */ $agency->update(['code' => 'ui-'.$agency->getKey()]);

        $moved = $this->claimTypedEmployer(
            $agency,
            $request->string('claim')->toString() ?: null,
        );

        ActivityLogger::record(
            'agency.created',
            $agency,
            sprintf('%s added to the employer list.', $agency->name),
            [
                'sector' => $agency->sector,
                'field_office_id' => $agency->field_office_id,
            ],
        );

        return redirect()
            ->route('admin.agencies.index')
            ->with('success', $moved > 0
                ? sprintf(
                    '“%s” has been added, and %d participant%s who typed it moved onto it.',
                    $agency->name,
                    $moved,
                    $moved === 1 ? '' : 's',
                )
                : "“{$agency->name}” has been added to the employer list.");
    }

    public function edit(Agency $agency): Response
    {
        $agency->loadCount('profiles');

        return Inertia::render('Admin/Agencies/Form', [
            'agency' => [
                'id' => $agency->id,
                'name' => $agency->name,
                'acronym' => $agency->acronym,
                'sector' => $agency->sector,
                'field_office_id' => $agency->field_office_id,
                'is_active' => $agency->is_active,
            ],
            'prefillName' => null,
            'claimCount' => 0,
            'sectors' => $this->sectorOptions(),
            'offices' => $this->officeOptions(),
            /*
             * How many people this row speaks for.
             *
             * The form warns before changing the field office, and a warning
             * that cannot say how many people it affects is one nobody can
             * weigh. See update() for why that particular edit is not a
             * relabel.
             */
            'linkedProfiles' => $agency->profiles_count,
        ]);
    }

    /**
     * Corrections propagate, and one of them moves people.
     *
     * The `saved` hook on Agency pushes a changed name, sector or field office
     * out to every profile linked to the row — that is what keeps the
     * denormalized copies on `profiles` honest, and it is why an edit here is
     * not confined to this table.
     *
     * Changing the **field office** is the one that is not a relabel. Every
     * linked participant moves into that office's scoped view: they leave one
     * office's lists, exports and roster counts and appear in another's. It is
     * a legitimate thing to do — agencies are reassigned — but it is a
     * visibility change dressed as a dropdown, so the form confirms it by
     * number first, and the audit entry records it whichever way it went.
     */
    public function update(Request $request, Agency $agency): RedirectResponse
    {
        $validated = $this->validated($request, $agency);

        $before = $agency->only(array_keys($validated));
        $agency->update($validated);
        $after = $agency->only(array_keys($validated));

        // Only what moved: a form posts every field whether or not it changed,
        // and an office reassignment buried among four unchanged values is a
        // trail nobody scans.
        $changes = array_keys(array_diff_assoc($after, $before));

        if ($changes !== []) {
            ActivityLogger::record(
                'agency.updated',
                $agency,
                sprintf('%s: %s changed.', $agency->name, implode(', ', $changes)),
                [
                    'changed' => $changes,
                    'from' => array_intersect_key($before, array_flip($changes)),
                    'to' => array_intersect_key($after, array_flip($changes)),
                    // The number is the point of the entry when the office
                    // moved: it says how many people the change carried.
                    'profiles_affected' => $agency->profiles()->count(),
                ],
            );
        }

        return redirect()
            ->route('admin.agencies.index')
            ->with('success', "“{$agency->name}” has been updated.");
    }

    /**
     * Retire an agency without stranding anyone filed under it.
     *
     * Deactivating drops it from the profile form's picker while every profile
     * already pointing at it keeps its employer name, sector and office — the
     * same deactivate-never-delete rule field offices and subject matter
     * experts follow, and for the same reason: these rows are the record of
     * who attended what.
     */
    public function toggle(Agency $agency): RedirectResponse
    {
        $agency->update(['is_active' => ! $agency->is_active]);

        ActivityLogger::recordTransition(
            $agency->is_active ? 'agency.activated' : 'agency.deactivated',
            $agency,
            $agency->is_active ? 'inactive' : 'active',
            $agency->is_active ? 'active' : 'inactive',
            sprintf('%s is now %s.', $agency->name, $agency->is_active ? 'active' : 'inactive'),
            ['profiles' => $agency->profiles()->count()],
        );

        return back()->with(
            'success',
            "“{$agency->name}” is now ".($agency->is_active ? 'active' : 'inactive').'.'
        );
    }

    /**
     * Remove an agency nobody is filed under.
     *
     * The bound is attachment, not age — the same rule and the same reasoning
     * as FieldOfficeController::destroy. `profiles.agency_id` is
     * `nullOnDelete`, so deleting a row people point at would not refuse, it
     * would silently unlink them: those participants would drop back to the
     * typed-employer state with nothing recording that it happened, which is
     * the quiet kind of wrong this codebase keeps having to dig out.
     *
     * Superadmin only, unlike the rest of this screen. Adding and correcting
     * an employer is administration; removing one is not reversible.
     */
    public function destroy(Agency $agency): RedirectResponse
    {
        $agency->loadCount('profiles');

        if ($agency->profiles_count > 0) {
            return back()->with('error', sprintf(
                '“%s” cannot be deleted — %d participant%s still filed under it. Deactivate it instead, '
                .'which stops it being offered on new profiles while keeping existing records readable.',
                $agency->name,
                $agency->profiles_count,
                $agency->profiles_count === 1 ? ' is' : 's are',
            ));
        }

        $name = $agency->name;

        ActivityLogger::record(
            'agency.deleted',
            $agency,
            sprintf('%s removed from the employer list.', $name),
            ['sector' => $agency->sector, 'field_office_id' => $agency->field_office_id],
        );

        $agency->delete();

        return back()->with('success', "“{$name}” has been removed from the employer list.");
    }

    /**
     * Move the people who typed an employer onto the agency it turned out to be.
     *
     * Without this the gap panel is a worklist that never shrinks. Adding
     * "Department of Education" does nothing for the twelve participants who
     * typed "DEPED": their `agency_id` stays null, so they keep the free text,
     * they stay off the canonical sector and office, and the panel goes on
     * offering the same row forever. Working through it would change nothing,
     * which is the opposite of what a worklist is for.
     *
     * Matched on the exact typed string, and only where nothing is linked yet.
     * Somebody who has since picked an agency properly is not swept up by a
     * decision about a name they no longer carry.
     *
     * The writes mirror ProfileService::resolveEmployer exactly, because this
     * is the same act the participant would have performed by picking the
     * agency themselves: the reference's own spelling, its sector, and its
     * office only when it names one — an agency
     * with no office leaves the participant's own answer standing rather than
     * blanking it.
     *
     * Query builder rather than a loop: this can touch every participant at a
     * large agency, and none of the writes needs a model event.
     */
    private function claimTypedEmployer(Agency $agency, ?string $typed): int
    {
        if (blank($typed)) {
            return 0;
        }

        $changes = [
            'agency_id' => $agency->getKey(),
            // The reference's spelling, matching what ProfileService writes when
            // the participant picks the agency themselves. This is the same act.
            'organization_name' => $agency->name,
            'sector' => $agency->sector,
        ];

        if ($agency->field_office_id !== null) {
            $changes['field_office_id'] = $agency->field_office_id;
        }

        $moved = Profile::query()
            ->whereNull('agency_id')
            ->where('organization_name', $typed)
            ->update($changes);

        if ($moved > 0) {
            ActivityLogger::record(
                'agency.employers_claimed',
                $agency,
                sprintf(
                    '%d participant%s who typed "%s" moved onto %s.',
                    $moved,
                    $moved === 1 ? '' : 's',
                    $typed,
                    $agency->name,
                ),
                ['typed' => $typed, 'participants' => $moved],
            );
        }

        return $moved;
    }

    /**
     * A typed employer that turned out to be an agency already on the list.
     *
     * The other half of the gap panel, and the case the "Add to list" button
     * cannot serve: somebody typed "DEPED" while "Department of Education" is
     * already there. Adding it again is refused by the unique name — correctly,
     * since a second row is the split this table exists to end — so without
     * this the shortcut spelling has nowhere to go and sits in the panel
     * permanently.
     */
    public function resolve(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'organization_name' => ['required', 'string', 'max:255'],
            'agency_id' => ['required', 'exists:agencies,id'],
        ]);

        $agency = Agency::findOrFail($validated['agency_id']);
        $moved = $this->claimTypedEmployer($agency, $validated['organization_name']);

        if ($moved === 0) {
            return back()->with(
                'error',
                sprintf('Nobody is still filed under “%s” — it may already have been resolved.', $validated['organization_name']),
            );
        }

        return back()->with('success', sprintf(
            '%d participant%s moved onto “%s”.',
            $moved,
            $moved === 1 ? '' : 's',
            $agency->name,
        ));
    }

    /**
     * Correct the text people typed, without deciding what it is yet.
     *
     * The third thing a typed employer can need, and the one neither of the
     * others does. "DEPED", "DEP ED" and "Dep. Ed." are three rows in the gap
     * panel and three different strings on three participants' records, and
     * until they read the same they cannot be resolved as one group — matching
     * or adding has to be done three times, and each is a separate decision
     * about the same employer.
     *
     * Renaming them to one spelling merges the rows: the next pass sees a
     * single entry with three participants behind it, which is one decision
     * instead of three. It also leaves the participants' own records reading
     * properly in the meantime, which matters because `organization_name` is
     * what every export, roster and search actually shows.
     *
     * Deliberately does *not* link anything. An agency may not exist for this
     * employer yet, and inventing one to tidy a spelling would put a row in
     * the picker that nobody decided to add.
     */
    public function rename(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'organization_name' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
        ]);

        /*
         * Stored exactly as written, and deliberately not upper-cased.
         *
         * A participant typing their own employer gets upper-cased, because
         * free text has no owner to be canonical for it. This is not that:
         * an administrator correcting a spelling *is* the owner deciding it,
         * the same act as naming a row on the agency list. Shouting it back
         * would refuse the correction being made — the button exists so
         * "DEPED" can become "Department of Education", and forcing capitals
         * turns that into "DEPARTMENT OF EDUCATION", which is the shortcut
         * again in a longer form.
         *
         * Nothing downstream needs the capitals: the column's collation is
         * case-insensitive, so grouping this panel and every search over it
         * already folds case, and the one PHP-side grouping folds it itself.
         */
        $to = trim($validated['name']);
        $from = $validated['organization_name'];

        // Case-insensitively: the column's collation treats these as the same
        // value, so a change of capitals alone updates nothing and reports a
        // move that did not happen.
        if (mb_strtoupper($to) === mb_strtoupper($from)) {
            return back();
        }

        $moved = Profile::query()
            ->whereNull('agency_id')
            ->where('organization_name', $from)
            ->update(['organization_name' => $to]);

        if ($moved === 0) {
            return back()->with(
                'error',
                sprintf('Nobody is still filed under “%s” — it may already have been resolved.', $from),
            );
        }

        ActivityLogger::record(
            'agency.typed_employer_renamed',
            null,
            sprintf('“%s” corrected to “%s” for %d participant%s.', $from, $to, $moved, $moved === 1 ? '' : 's'),
            ['from' => $from, 'to' => $to, 'participants' => $moved],
        );

        return back()->with('success', sprintf(
            '“%s” is now “%s” for %d participant%s.',
            $from,
            $to,
            $moved,
            $moved === 1 ? '' : 's',
        ));
    }

    /**
     * The employers people typed because the list did not have them.
     *
     * `agency_id` null *is* the "not on the list" state — there is no second
     * flag — so this is simply the profiles that took the typed path, grouped
     * by what they typed. Ordered by how many people said the same thing,
     * because that is the order worth working through: twenty people typing
     * one name is one missing row, and fixing it moves twenty participants
     * onto canonical data at once.
     *
     * Names are already stored uppercase (ProfileService::upperCased), so
     * grouping needs no normalising here — variants that differ by case have
     * already collapsed into one another before they reach this query.
     *
     * @return array<string, mixed>
     */
    private function unlisted(): array
    {
        $typed = fn () => Profile::query()
            ->whereNull('agency_id')
            ->whereNotNull('organization_name')
            ->where('organization_name', '!=', '');

        // toBase(), because these rows are aggregates rather than profiles:
        // hydrating them as Profile models would promise a `total` property
        // the model does not have, which is exactly what it looks like to
        // anyone reading it afterwards.
        $rows = $typed()
            ->toBase()
            ->select('organization_name', DB::raw('count(*) as total'))
            ->groupBy('organization_name')
            ->orderByDesc('total')
            ->orderBy('organization_name')
            ->limit(self::UNLISTED_SHOWN)
            ->get();

        // The two totals in one round trip rather than two: how many people
        // typed something, and how many different things they typed. The
        // second is the size of the worklist, the first is how many
        // participants finishing it would move onto canonical data.
        $totals = (array) $typed()
            ->toBase()
            ->selectRaw('count(*) as participants, count(distinct organization_name) as distinct_names')
            ->first();

        return [
            'rows' => $rows->map(fn ($row) => [
                'name' => $row->organization_name,
                'participants' => (int) $row->total,
                'add_url' => route('admin.agencies.create', ['name' => $row->organization_name]),
            ])->all(),
            'distinct' => (int) ($totals['distinct_names'] ?? 0),
            'shown' => self::UNLISTED_SHOWN,
            'participants' => (int) ($totals['participants'] ?? 0),
        ];
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        return [
            'all' => Agency::count(),
            'active' => Agency::where('is_active', true)->count(),
            'inactive' => Agency::where('is_active', false)->count(),
            // An agency with no office serves nobody in particular, and the
            // profile form has to ask the participant for one instead — so
            // these are worth finishing rather than leaving.
            'without_office' => Agency::whereNull('field_office_id')->count(),
        ];
    }

    /** @return array<int, array<string, string>> */
    private function sectorOptions(): array
    {
        return collect(ProfileOptions::sectors())
            ->map(fn (string $sector) => ['value' => $sector, 'label' => $sector])
            ->values()
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function officeOptions(): array
    {
        return FieldOffice::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (FieldOffice $office) => ['value' => $office->id, 'label' => $office->name])
            ->all();
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Agency $agency = null): array
    {
        return $request->validate([
            /*
             * Unique, and that is the whole point of the table.
             *
             * Two rows with the same name would put the same employer in the
             * picker twice, which is the split this list exists to end — and
             * the participant choosing between them has no way to tell which
             * is the real one.
             */
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('agencies', 'name')->ignore($agency?->getKey()),
            ],
            'acronym' => ['nullable', 'string', 'max:32'],
            // Validated against the same list the profile form offers. A
            // sector off that list bounces the participant's next save on a
            // field they never touched — the trap the relabel migration exists
            // to clean up after.
            'sector' => ['required', Rule::in(ProfileOptions::sectors())],
            'field_office_id' => ['nullable', 'exists:field_offices,id'],
            'is_active' => ['boolean'],
        ]);
    }
}
