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
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Ports v1's `admin/hrd/analytics.php`.
 *
 * Every figure honours the same field-office scoping as the listings — a field
 * office looking at "attendance rate" must be seeing its own, not the region's.
 */
class AnalyticsController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $officeId = $request->user()->scopedFieldOfficeId();
        $months = max(3, min(24, (int) $request->integer('months', 12)));
        $since = now()->subMonths($months - 1)->startOfMonth();

        return Inertia::render('Admin/Analytics', [
            'range' => ['months' => $months, 'since' => $since->format('M Y')],
            'headline' => $this->headline($officeId),
            'registrationsByMonth' => $this->registrationsByMonth($officeId, $since),
            'byCategory' => $this->byCategory($officeId),
            'byFieldOffice' => $this->byFieldOffice($officeId),
            'attendance' => $this->attendance($officeId),
            'payments' => $this->payments($request),
            'demographics' => $this->demographics($officeId),
            'topAgencies' => $this->topAgencies($officeId),
            'scopedTo' => $request->user()->fieldOffice?->name,
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
}
