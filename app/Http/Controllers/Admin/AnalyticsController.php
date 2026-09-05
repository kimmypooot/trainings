<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AttendanceStatus;
use App\Enums\PaymentStatus;
use App\Enums\RegistrationStatus;
use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Certificate;
use App\Models\FieldOffice;
use App\Models\Payment;
use App\Models\Profile;
use App\Models\Registration;
use App\Models\Training;
use App\Support\ReportScope;
use App\Support\RevenueService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Ports v1's `admin/hrd/analytics.php`.
 *
 * The page has three views: a live Overview (the original dashboard), a report
 * for one selected training, and a report covering all trainings conducted in
 * a calendar period (monthly, quarterly, annual). Each report comes in two
 * forms — revenue, which includes the PRIME-HRM discount line, and a
 * demographic breakdown.
 *
 * Every figure honours the same field-office scoping as the listings — a field
 * office looking at "attendance rate" or "revenue" must be seeing its own, not
 * the region's. The one deliberate exception is the Overview money block,
 * which is global because none of the financial roles are office-scoped.
 */
class AnalyticsController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $officeId = $request->user()->scopedFieldOfficeId();
        $canSeeMoney = $request->user()->collectsPayments();

        $view = $request->string('view', 'overview')->toString();
        $view = in_array($view, ['overview', 'training', 'period'], true) ? $view : 'overview';

        $scope = ReportScope::fromRequest($request);

        return Inertia::render('Admin/Analytics', [
            'view' => $view,
            'scopedTo' => $request->user()->fieldOffice?->name,
            // Money is gated on the collecting-officer designation, the same
            // gate the overview block and the payment exports use.
            'canSeeMoney' => $canSeeMoney,
            'trainingOptions' => $this->trainingOptions(),
            'selectedTrainingId' => $scope->trainingId,
            'period' => [
                'value' => $scope->period,
                'year' => $scope->year,
                'month' => $scope->month,
                'quarter' => $scope->quarter,
            ],
            /*
             * Guarded on the view, like the other two reports below. The
             * overview fans out into a dozen aggregates — the headline tallies,
             * twelve months of registrations, category and field-office splits,
             * attendance, the whole demographics block, charge-to and the top
             * agencies — and none of it is rendered on the other two tabs,
             * which only ever showed it to throw it away.
             *
             * Deferred on the tab that *does* render it, because those dozen
             * aggregates all had to finish before the browser received a single
             * byte: the tab bar, the scope notice and the page shell — none of
             * which depend on any of it — were held hostage by the slowest
             * query in the set. Now the shell paints immediately against a
             * skeleton and the figures land in a follow-up request.
             *
             * Deferred rather than lazy because nothing has to ask for it: this
             * is the tab's whole content, always wanted, just not wanted
             * *before* the page can be drawn. Inertia requests it on its own
             * once the page mounts.
             */
            'overview' => $view === 'overview'
                ? Inertia::defer(fn () => $this->overview($request, $officeId))
                : null,
            'trainingReport' => $view === 'training'
                ? $this->trainingReport($scope, $officeId, $canSeeMoney)
                : null,
            'periodReport' => $view === 'period'
                ? $this->periodReport($scope, $officeId, $canSeeMoney)
                : null,
        ]);
    }

    /**
     * Apply office scoping to a registration-rooted query.
     */
    private function scope($query, ?int $officeId)
    {
        return $query->when($officeId !== null, fn ($inner) => $inner->whereHas(
            'user.profile',
            fn ($profile) => $profile->where('field_office_id', $officeId)
        ));
    }

    // --- Overview ----------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function overview(Request $request, ?int $officeId): array
    {
        $months = max(3, min(24, $request->integer('months', 12)));
        $since = now()->subMonths($months - 1)->startOfMonth();

        return [
            'range' => ['months' => $months, 'since' => $since->format('M Y')],
            'headline' => $this->headline($officeId),
            'registrationsByMonth' => $this->registrationsByMonth($officeId, $since),
            'byCategory' => $this->byCategory($officeId),
            'byFieldOffice' => $this->byFieldOffice($officeId),
            'attendance' => $this->attendance($officeId),
            'payments' => $this->payments($request),
            'demographics' => $this->demographics($officeId),
            'topAgencies' => $this->topAgencies($officeId),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function headline(?int $officeId): array
    {
        $registrations = $this->scope(Registration::query(), $officeId);

        return [
            'trainings' => Training::count(),
            'registrations' => (clone $registrations)->count(),
            'completed' => (clone $registrations)->where('status', RegistrationStatus::Completed)->count(),
            'certificates' => Certificate::whereNotNull('generated_at')
                ->when($officeId !== null, fn ($query) => $query->whereHas(
                    'user.profile',
                    fn ($profile) => $profile->where('field_office_id', $officeId)
                ))
                ->count(),
        ];
    }

    /**
     * Registrations per calendar month.
     *
     * Grouped in PHP rather than SQL: the date functions differ between
     * database engines, and the app and the test suite now share one.
     *
     * @return array<int, array{month: string, count: int}>
     */
    private function registrationsByMonth(?int $officeId, \DateTimeInterface $since): array
    {
        $counts = $this->scope(Registration::query(), $officeId)
            ->where('registered_at', '>=', $since)
            ->pluck('registered_at')
            ->countBy(fn ($date) => $date->format('Y-m'));

        $months = [];
        $cursor = CarbonImmutable::parse($since)->startOfMonth();

        while ($cursor->lessThanOrEqualTo(now())) {
            $months[] = [
                'month' => $cursor->format('M Y'),
                'count' => $counts->get($cursor->format('Y-m'), 0),
            ];
            $cursor = $cursor->addMonth();
        }

        return $months;
    }

    /**
     * @return array<int, array{label: string, count: int}>
     */
    private function byCategory(?int $officeId): array
    {
        return $this->scope(Registration::with('training'), $officeId)
            ->get()
            ->groupBy(fn (Registration $registration) => $registration->training->category ?: 'Uncategorised')
            ->map(fn ($group, $label) => ['label' => $label, 'count' => $group->count()])
            ->sortByDesc('count')
            ->values()
            ->all();
    }

    /**
     * Per-office totals.
     *
     * Pointless for an office-scoped user — they would see a single bar showing
     * their own name — so it is returned empty for them.
     *
     * @return array<int, array{label: string, count: int}>
     */
    private function byFieldOffice(?int $officeId): array
    {
        if ($officeId !== null) {
            return [];
        }

        $counts = Registration::with('user.profile')
            ->get()
            ->countBy(fn (Registration $registration) => $registration->user->profile?->field_office_id);

        return FieldOffice::active()
            ->get()
            ->map(fn (FieldOffice $office) => [
                'label' => $office->name,
                'count' => $counts->get($office->id, 0),
            ])
            ->sortByDesc('count')
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function attendance(?int $officeId): array
    {
        $query = Attendance::query()
            ->when($officeId !== null, fn ($inner) => $inner->whereHas(
                'registration.user.profile',
                fn ($profile) => $profile->where('field_office_id', $officeId)
            ));

        $total = (clone $query)->count();

        $byStatus = array_map(function (AttendanceStatus $status) use ($query) {
            return [
                'label' => $status->label(),
                'count' => (clone $query)->where('status', $status)->count(),
            ];
        }, AttendanceStatus::cases());

        $credited = (clone $query)->whereIn('status', AttendanceStatus::crediting())->count();

        return [
            'total' => $total,
            'byStatus' => $byStatus,
            // Share of recorded days that counted toward completion.
            'rate' => $total > 0 ? round($credited / $total * 100, 1) : null,
        ];
    }

    /**
     * Who is being trained, ported from v1's `get-analytics-data.php`.
     *
     * These are the cuts CSC reports upward — a regional office is asked how
     * many first-level staff it reached, and how the intake splits by sex and
     * age band. The vocabulary already exists in ProfileOptions, so the only
     * thing missing was the counting.
     *
     * Counted over registrations rather than participants: one person attending
     * three trainings is three training slots delivered, which is what the
     * report is about. A head-count would answer a different question.
     *
     * @return array<string, array<int, array{label: string, count: int}>>
     */
    private function demographics(?int $officeId): array
    {
        // One pass over the profiles behind the registrations. Grouping in PHP
        // rather than SQL keeps every cut on identical rows — a per-column
        // GROUP BY would silently drop registrations whose profile is missing
        // that one field, and the totals would then disagree between charts.
        $profiles = $this->scope(Registration::with('user.profile'), $officeId)
            ->get()
            ->map(fn (Registration $registration) => $registration->user->profile)
            ->filter();

        return [
            'sex' => $this->tally($profiles, 'sex'),
            'positionLevel' => $this->tally($profiles, 'position_level'),
            'employmentStatus' => $this->tally($profiles, 'employment_status'),
            'sector' => $this->tally($profiles, 'sector'),
            'ageBand' => $this->ageBands($profiles),
            'chargeTo' => $this->chargeTo($officeId),
            /*
             * Where participants actually come from, which v1 called the "geo
             * breakdown". v1 grouped by field office — already charted above —
             * but the office is an administrative assignment, not a location:
             * two participants in the same office can be provinces apart, and
             * out-of-region attendees (who drive the physical-OR pipeline) all
             * collapse into whichever office was picked. These read the PSGC
             * fields on the profile instead, so the answer is geography.
             */
            'region' => $this->tally($profiles, 'region'),
            'province' => $this->tally($profiles, 'province'),
        ];
    }

    /**
     * Count one profile column, largest first.
     *
     * @param  Collection<int, Profile>  $profiles
     * @return array<int, array{label: string, count: int}>
     */
    private function tally($profiles, string $column): array
    {
        return $profiles
            ->countBy(fn ($profile) => filled($profile->{$column}) ? $profile->{$column} : 'Not stated')
            ->map(fn (int $count, string $label) => ['label' => $label, 'count' => $count])
            ->sortByDesc('count')
            ->values()
            ->all();
    }

    /**
     * A yes/no profile flag, in the order a reader expects: the answer, then
     * the unanswered. Kept apart from tally() because "No" is an answer here,
     * not a blank to collapse into "Not stated".
     *
     * @param  Collection<int, Profile>  $profiles
     * @return array<int, array{label: string, count: int}>
     */
    private function tallyYesNo($profiles, string $column): array
    {
        $counts = array_fill_keys(['Yes', 'No', 'Not stated'], 0);

        foreach ($profiles as $profile) {
            if ($profile->{$column} === null) {
                $counts['Not stated']++;
            } else {
                $counts[$profile->{$column} ? 'Yes' : 'No']++;
            }
        }

        return array_values(array_map(
            fn (string $label) => ['label' => $label, 'count' => $counts[$label]],
            array_filter(
                array_keys($counts),
                fn (string $label) => $counts[$label] > 0,
            ),
        ));
    }

    /**
     * Age at the time the report is run, in the bands v1 used.
     *
     * @param  Collection<int, Profile>  $profiles
     * @return array<int, array{label: string, count: int}>
     */
    private function ageBands($profiles): array
    {
        $bands = ['18-25', '26-35', '36-45', '46-55', '56-65', 'Over 65'];
        $counts = array_fill_keys([...$bands, 'Not stated'], 0);

        foreach ($profiles as $profile) {
            if ($profile->date_of_birth === null) {
                $counts['Not stated']++;

                continue;
            }

            $age = $profile->date_of_birth->age;

            $counts[match (true) {
                $age <= 25 => '18-25',
                $age <= 35 => '26-35',
                $age <= 45 => '36-45',
                $age <= 55 => '46-55',
                $age <= 65 => '56-65',
                default => 'Over 65',
            }]++;
        }

        // Age bands keep their natural order rather than being sorted by size —
        // a distribution read out of sequence is not a distribution.
        return array_values(array_map(
            fn (string $label) => ['label' => $label, 'count' => $counts[$label]],
            array_filter(
                array_keys($counts),
                fn (string $label) => $counts[$label] > 0 || $label !== 'Not stated',
            ),
        ));
    }

    /**
     * Personal versus agency-funded, from the registration itself.
     *
     * @return array<int, array{label: string, count: int}>
     */
    private function chargeTo(?int $officeId): array
    {
        $counts = $this->scope(Registration::query(), $officeId)
            ->get()
            ->countBy(fn (Registration $registration) => $registration->charge_to?->label() ?? 'Not stated');

        return $counts
            ->map(fn (int $count, string $label) => ['label' => $label, 'count' => $count])
            ->sortByDesc('count')
            ->values()
            ->all();
    }

    /**
     * The agencies sending the most people.
     *
     * Capped at ten: the tail is a long list of agencies with one registration
     * each, which tells nobody anything and makes the chart unreadable.
     *
     * @return array<int, array{label: string, count: int}>
     */
    private function topAgencies(?int $officeId): array
    {
        return $this->scope(Registration::with('user.profile'), $officeId)
            ->get()
            ->countBy(fn (Registration $registration) => $registration->user->profile?->organization_name ?: 'Not stated')
            ->map(fn (int $count, string $label) => ['label' => $label, 'count' => $count])
            ->sortByDesc('count')
            ->take(10)
            ->values()
            ->all();
    }

    /**
     * Money, for the roles that handle it.
     *
     * Not office-scoped, deliberately: none of the financial roles are tied to
     * a field office, and the office-scoped roles cannot see this block at all.
     *
     * @return array<string, mixed>|null
     */
    private function payments(Request $request): ?array
    {
        if (! $request->user()->collectsPayments()) {
            return null;
        }

        $query = Payment::query();

        return [
            'verified_total' => (clone $query)->where('status', PaymentStatus::Verified)->sum('amount'),
            'pending_count' => (clone $query)->where('status', PaymentStatus::Pending)->count(),
            'rejected_count' => (clone $query)->where('status', PaymentStatus::Rejected)->count(),
        ];
    }

    // --- Reports -----------------------------------------------------------

    /**
     * @return array<int, array{value: int, label: string}>
     */
    private function trainingOptions(): array
    {
        return Training::orderByDesc('starts_at')
            ->get(['id', 'title', 'starts_at', 'ends_at'])
            ->map(fn (Training $training) => [
                'value' => $training->getKey(),
                // The full span, not just the month: two runs of the same
                // course four weeks apart both read "Foundations — Mar 2026"
                // and the picker gave no way to tell which one was selected.
                // `starts_at` is NOT NULL, so the null branch this used to
                // carry could never be taken — it only looked defensive
                // because the column was resolving as a string.
                'label' => $training->title.' — '.$training->dateRange(),
            ])
            ->values()
            ->all();
    }

    /**
     * The report for one selected training.
     *
     * The payments are derived from the scoped registrations rather than
     * queried independently: a payment belongs to a registration, so scoping
     * the registrations scopes the money with no second whereHas.
     *
     * @return array<string, mixed>|null
     */
    private function trainingReport(ReportScope $scope, ?int $officeId, bool $canSeeMoney): ?array
    {
        $training = Training::find($scope->trainingId);

        if ($training === null) {
            return null;
        }

        $registrations = $this->scope(
            Registration::with(['user.profile', 'payments']),
            $officeId
        )->where('training_id', $training->getKey())->get();

        return [
            'training' => [
                'id' => $training->getKey(),
                'title' => $training->title,
                // The span rather than the start. A three-day run reported as
                // one date reads as a one-day run, and the reader has no way
                // to tell the difference from inside the report.
                'dates' => $training->dateRange(),
                'duration_days' => $training->duration_days,
                'payment_amount' => $training->payment_amount,
            ],
            'revenue' => $canSeeMoney ? $this->revenueBlock($registrations) : null,
            'breakdown' => $this->breakdownBlock($registrations),
        ];
    }

    /**
     * The report over all trainings conducted in the period.
     *
     * @return array<string, mixed>
     */
    private function periodReport(ReportScope $scope, ?int $officeId, bool $canSeeMoney): array
    {
        $trainings = $scope->trainingsQuery()->get();
        $trainingIds = $trainings->pluck('id');

        $registrations = $trainingIds->isEmpty()
            ? collect()
            : $this->scope(
                Registration::with(['user.profile', 'payments']),
                $officeId
            )->whereIn('training_id', $trainingIds)->get();

        return [
            'period' => $scope->period,
            'label' => $scope->periodLabel(),
            'conducted' => $trainings->count(),
            'participants' => $registrations->count(),
            'revenue' => $canSeeMoney ? $this->revenueBlock($registrations) : null,
            'breakdown' => $this->breakdownBlock($registrations),
            // The money earned per month inside the period, so an annual view
            // shows the seasonal shape rather than one lump.
            'byPeriod' => $canSeeMoney ? $this->revenueByPeriod($trainings, $registrations, $scope) : null,
        ];
    }

    /**
     * The money figures for a set of registrations.
     *
     * Pending and rejected are counted from the same payment rows so the
     * report's three statuses always add up to the same payments.
     *
     * @param  Collection<int, Registration>  $registrations
     * @return array<string, mixed>
     */
    private function revenueBlock(Collection $registrations): array
    {
        $payments = $registrations->flatMap(
            fn (Registration $registration) => $registration->payments
        );

        $verified = $payments->filter(fn (Payment $payment) => $payment->status === PaymentStatus::Verified);

        return [
            ...RevenueService::summarize($verified),
            'pending_count' => $payments->filter(fn (Payment $payment) => $payment->status === PaymentStatus::Pending)->count(),
            'rejected_count' => $payments->filter(fn (Payment $payment) => $payment->status === PaymentStatus::Rejected)->count(),
            'discounted' => RevenueService::discountedList($verified),
        ];
    }

    /**
     * The demographic cuts of a set of registrations.
     *
     * @param  Collection<int, Registration>  $registrations
     * @return array<string, mixed>
     */
    private function breakdownBlock(Collection $registrations): array
    {
        $profiles = $registrations->map(fn (Registration $registration) => $registration->user->profile)->filter();

        return [
            'total' => $registrations->count(),
            'sex' => $this->tally($profiles, 'sex'),
            'pwd' => $this->tallyYesNo($profiles, 'is_pwd'),
            'positionLevel' => $this->tally($profiles, 'position_level'),
            'employmentStatus' => $this->tally($profiles, 'employment_status'),
            'sector' => $this->tally($profiles, 'sector'),
            'ageBand' => $this->ageBands($profiles),
        ];
    }

    /**
     * Verified revenue per month inside the period, earned where the training
     * started. A payment recorded late still belongs to the run that earned it.
     *
     * @param  Collection<int, Training>  $trainings
     * @param  Collection<int, Registration>  $registrations
     * @return array<int, array{label: string, gross: float, discount: float, collected: float, promissory: float, count: int}>
     */
    private function revenueByPeriod($trainings, $registrations, ReportScope $scope): array
    {
        $byTraining = $registrations->groupBy('training_id');

        $rows = array_fill_keys(
            array_map(
                fn (CarbonImmutable $month) => $month->format('Y-m'),
                $scope->monthsInPeriod(),
            ),
            ['gross' => 0.0, 'discount' => 0.0, 'collected' => 0.0, 'promissory' => 0.0, 'count' => 0],
        );

        foreach ($trainings as $training) {
            $verified = collect($byTraining->get($training->getKey(), []))
                ->flatMap(fn (Registration $registration) => $registration->payments)
                ->filter(fn (Payment $payment) => $payment->status === PaymentStatus::Verified);

            $key = $training->starts_at->format('Y-m');

            if (! array_key_exists($key, $rows)) {
                continue;
            }

            // Through RevenueService rather than summed here. Hand-rolling it
            // is how the trend came to count a promissory note's gross and
            // discount that the headline above it leaves out, so the rows
            // added up to more than the total they sat under.
            $summary = RevenueService::summarize($verified);

            $rows[$key]['count'] += $verified->count();
            $rows[$key]['gross'] += $summary['gross'];
            $rows[$key]['discount'] += $summary['discount'];
            $rows[$key]['collected'] += $summary['collected'];
            $rows[$key]['promissory'] += $summary['promissory'];
        }

        return array_map(
            fn (string $key, array $row) => [
                // Parsed with an explicit first-of-month. 'Y-m' leaves the day
                // unset, so PHP fills it from today and a 31st turns '2026-02'
                // into March — the trend then files February's money under the
                // wrong label, but only when the report is run late in a month.
                'label' => CarbonImmutable::createFromFormat('Y-m-d', $key.'-01')->format('M Y'),
                'gross' => round($row['gross'], 2),
                'discount' => round($row['discount'], 2),
                'collected' => round($row['collected'], 2),
                'promissory' => round($row['promissory'], 2),
                'count' => $row['count'],
            ],
            array_keys($rows),
            array_values($rows),
        );
    }
}
