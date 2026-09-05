<?php

namespace App\Support;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\RegistrationStatus;
use App\Enums\Role;
use App\Models\Certificate;
use App\Models\Payment;
use App\Models\Registration;
use App\Models\Training;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * The admin dashboard's figures.
 *
 * A read model, not domain rules — it belongs beside `PendingActionCounter`
 * and `ReportScope` rather than among the services, because nothing here
 * decides anything. It only counts.
 *
 * **What this is for, and what analytics is for.** The two pages had started
 * to overlap in intent, and the rule that keeps them apart is *tense*: the
 * analytics report answers "what happened" over a chosen period — the annual
 * registration curve, the demographic cuts, revenue per training — and the
 * dashboard answers "what is happening, and is it getting better or worse".
 * So nothing here restates a chart that page already draws. Registrations by
 * month, category, field office, sex, age band, agency and attendance status
 * all live there and stay there; what lives here is the month against the one
 * before it, the queues with work in them, where registrations are stuck, and
 * which upcoming runs are not filling. If a figure below ever needs a year of
 * context to be read, it belongs on the report, not on this page.
 *
 * **Every figure is aggregated in the database.** The analytics overview
 * pulls whole tables into PHP and groups them there, which is defensible for
 * a report somebody opens occasionally and is not defensible for the page
 * every staff member lands on. Nothing in this file calls `->get()` on a
 * domain table: the trends are one conditional-aggregate query each, the
 * pipeline is one `GROUP BY`, and the capacity watch is a single query with a
 * `withCount` subquery. The whole page is a fixed number of queries whatever
 * the table sizes are.
 *
 * **Scoping and role gating are the same rules the rest of the app applies.**
 * People counts run through the field-office scope; the training catalogue is
 * regional, as it is on every other screen; and money follows the analytics
 * precedent of being global, because none of the financial roles are
 * office-scoped. A figure a role cannot act on is left out rather than shown
 * as a zero — the queue equivalent of a badge that lies.
 */
final class DashboardMetrics
{
    /** Months of history behind each KPI sparkline, including this one. */
    private const TREND_MONTHS = 6;

    /**
     * The window the completion rate is measured over.
     *
     * A calendar month is too short to be a rate: one large run finishing on
     * the 28th moves it thirty points, and a month with nothing ending has no
     * rate at all. Ninety days is long enough to hold several runs and short
     * enough to still describe now.
     */
    private const RATE_WINDOW_DAYS = 90;

    /** How many under-filled runs the capacity watch names. */
    private const CAPACITY_WATCH = 5;

    /**
     * @return array<string, mixed>
     */
    public static function for(User $user): array
    {
        $officeId = $user->scopedFieldOfficeId();
        $now = CarbonImmutable::now();

        return [
            'period' => self::period($now),
            'kpis' => self::kpis($user, $officeId, $now),
            'attention' => self::attention($user, $officeId),
            'pipeline' => self::pipeline($officeId),
            'capacity' => self::capacity(),
        ];
    }

    // --- Period ------------------------------------------------------------

    /**
     * This month so far, and the same stretch of the month before it.
     *
     * Month-to-date against month-to-date, never against the whole of last
     * month. On the 3rd of September, three days of registrations set beside
     * the whole of August reads as a collapse in demand, every month, for as
     * long as anybody looks at the page — a comparison that is wrong in a
     * predictable direction is worse than none.
     *
     * `subMonthNoOverflow` because the naive subtraction of a month from the
     * 31st lands in the following month, which would compare September against
     * a window starting in September.
     *
     * @return array{label: string, comparison: string, days: int}
     */
    private static function period(CarbonImmutable $now): array
    {
        return [
            'label' => $now->format('F Y'),
            'comparison' => $now->subMonthNoOverflow()->format('F'),
            'days' => self::elapsedDays($now),
        ];
    }

    /**
     * The month-to-date range, and the equivalent stretch of last month.
     *
     * @return array{0: array{0: string, 1: string}, 1: array{0: string, 1: string}}
     */
    private static function comparisonRanges(CarbonImmutable $now): array
    {
        /*
         * Whole days, not "up to this instant".
         *
         * Cutting the window at the current second looks more precise and is
         * worse in both directions: it silently drops anything recorded in the
         * same second the page is rendered — which in a test, and in any
         * request that writes and then reads, is everything — and it sets a
         * part-day against a full day last month. Two windows of N whole days
         * are the same length and are what the page says it is showing.
         */
        $days = self::elapsedDays($now);
        $start = $now->startOfMonth();
        $previousStart = $start->subMonthNoOverflow();

        return [
            [$start->toDateTimeString(), $start->addDays($days)->toDateTimeString()],
            [$previousStart->toDateTimeString(), $previousStart->addDays($days)->toDateTimeString()],
        ];
    }

    /** Days of this month elapsed, counting today. */
    private static function elapsedDays(CarbonImmutable $now): int
    {
        return (int) $now->startOfMonth()->diffInDays($now) + 1;
    }

    /**
     * Calendar-month ranges, oldest first, ending with this month to date.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    private static function trendRanges(CarbonImmutable $now): array
    {
        $ranges = [];

        for ($back = self::TREND_MONTHS - 1; $back >= 0; $back--) {
            $start = $now->startOfMonth()->subMonthsNoOverflow($back);
            $ranges[] = [$start->toDateTimeString(), $start->addMonthNoOverflow()->toDateTimeString()];
        }

        return $ranges;
    }

    // --- KPIs --------------------------------------------------------------

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function kpis(User $user, ?int $officeId, CarbonImmutable $now): array
    {
        $trend = self::trendRanges($now);
        [$current, $previous] = self::comparisonRanges($now);

        $registrations = fn () => self::scopeToOffice(Registration::query(), 'user.profile', $officeId);
        $certificates = fn () => self::scopeToOffice(
            Certificate::whereNotNull('generated_at'), 'user.profile', $officeId
        );

        $tiles = [
            self::countTile(
                key: 'registrations',
                label: 'Registrations',
                icon: 'list',
                column: 'registrations.registered_at',
                trend: self::bucketed($registrations(), 'registrations.registered_at', $trend),
                pair: self::bucketed($registrations(), 'registrations.registered_at', [$current, $previous]),
            ),
            self::countTile(
                key: 'certificates',
                label: 'Certificates Issued',
                icon: 'certificate',
                column: 'certificates.generated_at',
                trend: self::bucketed($certificates(), 'certificates.generated_at', $trend),
                pair: self::bucketed($certificates(), 'certificates.generated_at', [$current, $previous]),
            ),
            self::completionTile($officeId, $now),
        ];

        /*
         * Money is the collecting officer's tile, on the same gate the payment
         * screens and exports use — not the role list, which would miss a
         * field-office user carrying the designation.
         */
        if ($user->collectsPayments()) {
            $tiles[] = self::revenueTile($trend, $current, $previous);
        }

        return $tiles;
    }

    /**
     * @param  array<int, float>  $trend
     * @param  array<int, float>  $pair
     * @return array<string, mixed>
     */
    private static function countTile(
        string $key,
        string $label,
        string $icon,
        string $column,
        array $trend,
        array $pair,
    ): array {
        return [
            'key' => $key,
            'label' => $label,
            'icon' => $icon,
            'value' => (int) $pair[0],
            'format' => 'count',
            'tone' => 'brand',
            'spark' => array_map('intval', $trend),
            'delta' => self::delta($pair[0], $pair[1]),
        ];
    }

    /**
     * Registrations that reached Completed, among those that could have.
     *
     * The denominator is deliberately not every registration: a run that has
     * not finished cannot have completions, and counting it would make the
     * rate a measure of how much is in flight rather than of how much is being
     * closed out. So it counts registrations on runs that *ended* inside the
     * window, and asks how many of those were marked complete rather than left
     * sitting at Approved — which is exactly the backlog the "Needs Attention"
     * card lists by name.
     *
     * @return array<string, mixed>
     */
    private static function completionTile(?int $officeId, CarbonImmutable $now): array
    {
        $window = fn (CarbonImmutable $from, CarbonImmutable $to) => self::scopeToOffice(
            Registration::whereIn('status', [RegistrationStatus::Approved, RegistrationStatus::Completed])
                ->whereHas('training', fn (Builder $query) => $query->whereBetween('ends_at', [$from, $to])),
            'user.profile',
            $officeId,
        );

        $rate = function (CarbonImmutable $from, CarbonImmutable $to) use ($window): ?float {
            // `toBase()` because what comes back is an aggregate row, not a
            // registration: hydrating a model for it would attach casts and a
            // primary key to two numbers that have neither.
            $row = (array) $window($from, $to)->toBase()->selectRaw(
                'COUNT(*) as eligible, SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as completed',
                [RegistrationStatus::Completed->value],
            )->first();

            $eligible = (int) ($row['eligible'] ?? 0);

            // Null rather than zero for a window with nothing in it: "0%" is a
            // claim that work was left undone, and no runs ended is not that.
            return $eligible > 0 ? round((int) ($row['completed'] ?? 0) / $eligible * 100, 1) : null;
        };

        $current = $rate($now->subDays(self::RATE_WINDOW_DAYS), $now);
        $previous = $rate($now->subDays(self::RATE_WINDOW_DAYS * 2), $now->subDays(self::RATE_WINDOW_DAYS));

        return [
            'key' => 'completion',
            'label' => 'Completion Rate',
            'icon' => 'check-circle',
            'value' => $current,
            'format' => 'percent',
            'caption' => 'Runs that ended in the last '.self::RATE_WINDOW_DAYS.' days',
            // The one tile whose colour is a judgement rather than a category.
            'tone' => match (true) {
                $current === null => 'brand',
                $current >= 90.0 => 'success',
                $current >= 70.0 => 'warning',
                default => 'danger',
            },
            'spark' => null,
            'delta' => $current === null || $previous === null
                ? null
                // Points, not percent: the change in a percentage is itself a
                // percentage, and "up 8%" on a rate is ambiguous in a way
                // "up 8 points" is not.
                : self::pointsDelta($current, $previous),
        ];
    }

    /**
     * What was actually banked, on RevenueService's rule: verified, and not a
     * promissory note — a note is verified the moment it is accepted and no
     * money has moved.
     *
     * Global rather than office-scoped, matching the analytics money block:
     * none of the financial roles is scoped to an office, so narrowing this
     * would produce a figure that reconciles against nothing.
     *
     * @param  array<int, array{0: string, 1: string}>  $trend
     * @param  array{0: string, 1: string}  $current
     * @param  array{0: string, 1: string}  $previous
     * @return array<string, mixed>
     */
    private static function revenueTile(array $trend, array $current, array $previous): array
    {
        $collected = fn () => Payment::where('status', PaymentStatus::Verified)
            ->where('payment_method', '!=', PaymentMethod::Promissory->value);

        $pair = self::bucketed($collected(), 'payments.payment_date', [$current, $previous], 'payments.amount');

        return [
            'key' => 'revenue',
            'label' => 'Collected',
            'icon' => 'card',
            'value' => round($pair[0], 2),
            'format' => 'money',
            'caption' => 'Verified payments, region-wide',
            'tone' => 'success',
            'spark' => self::bucketed($collected(), 'payments.payment_date', $trend, 'payments.amount'),
            'delta' => self::delta($pair[0], $pair[1]),
        ];
    }

    /**
     * This period against the last, as a direction and a share.
     *
     * `percent` is null when there is nothing to divide by. A month that goes
     * from nothing to something has not risen by any percentage — it started —
     * and printing "+100%" for it, or "+∞", says something the data does not.
     *
     * @return array{direction: string, percent: float|null, previous: float}
     */
    private static function delta(float $current, float $previous): array
    {
        return [
            'direction' => match (true) {
                $current > $previous => 'up',
                $current < $previous => 'down',
                default => 'flat',
            },
            'percent' => $previous > 0 ? round(($current - $previous) / $previous * 100, 1) : null,
            'previous' => $previous,
        ];
    }

    /**
     * @return array{direction: string, points: float, previous: float}
     */
    private static function pointsDelta(float $current, float $previous): array
    {
        return [
            'direction' => match (true) {
                $current > $previous => 'up',
                $current < $previous => 'down',
                default => 'flat',
            },
            'points' => round($current - $previous, 1),
            'previous' => $previous,
        ];
    }

    // --- Queues ------------------------------------------------------------

    /**
     * The queues this user can actually clear.
     *
     * Zeros are kept, and that is deliberate — the panel is a checklist of the
     * work this role owns, and a queue that is empty is worth saying so about.
     * What is *not* kept is a queue the role cannot act on: those are dropped
     * entirely rather than shown at zero, because a row nobody can clear is a
     * row that teaches the reader to stop looking. The request and payment
     * counts come from PendingActionCounter so this panel and the sidebar
     * badge can never disagree.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function attention(User $user, ?int $officeId): array
    {
        $pending = PendingActionCounter::for($user);
        $items = [];

        $awaitingReview = self::scopeToOffice(
            Registration::where('status', RegistrationStatus::Pending),
            'user.profile',
            $officeId,
        )->count();

        $items[] = [
            'key' => 'registrations',
            'label' => 'Registrations awaiting review',
            'count' => $awaitingReview,
            'href' => '/admin/trainings',
        ];

        if (isset($pending['admin-requests'])) {
            $items[] = [
                'key' => 'requests',
                'label' => 'Requests to decide',
                'count' => $pending['admin-requests'],
                'href' => '/admin/requests',
            ];
        }

        if (isset($pending['admin-payments'])) {
            $items[] = [
                'key' => 'payments',
                'label' => 'Payments and refunds to review',
                'count' => $pending['admin-payments'],
                'href' => '/admin/payments',
            ];
        }

        // Re-sending a certificate is what clearing this queue means, and that
        // sits with the roles that manage participant records.
        if (in_array($user->role, [Role::Admin, Role::SuperAdmin, Role::FieldOffice], true)) {
            $items[] = [
                'key' => 'certificates',
                'label' => 'Certificates not yet emailed',
                'count' => self::scopeToOffice(
                    Certificate::whereNotNull('generated_at')->whereNull('email_sent_at'),
                    'user.profile',
                    $officeId,
                )->count(),
                'href' => '/admin/certificates?emailed=0',
            ];
        }

        return $items;
    }

    // --- Breakdowns --------------------------------------------------------

    /**
     * Where registrations currently sit.
     *
     * The status split is the one breakdown the analytics page does not draw,
     * and it is the one this page needs: it is not history, it is a queue
     * depth per stage, and a bulge at Pending or Approved is somebody's
     * afternoon. Every case is returned, in enum order, so the legend does not
     * reshuffle as counts move.
     *
     * @return array<int, array{label: string, count: int, status: string}>
     */
    private static function pipeline(?int $officeId): array
    {
        $counts = self::scopeToOffice(Registration::query(), 'user.profile', $officeId)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return array_map(fn (RegistrationStatus $status) => [
            'label' => $status->label(),
            'status' => $status->value,
            'count' => (int) $counts->get($status->value, 0),
        ], RegistrationStatus::cases());
    }

    /**
     * Published runs that are not filling.
     *
     * Ordered by how empty they are and capped, because this is a to-do list
     * rather than a census — the full catalogue is one click away and the
     * question here is which handful still need promoting. Runs with no
     * capacity set are excluded: without a denominator there is no such thing
     * as under-filled, and showing them at 0% would invent a problem.
     *
     * Regional for everybody, like the rest of the training catalogue: a run
     * belongs to the region, and a field office filling seats on it is doing
     * the region's work.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function capacity(): array
    {
        return Training::visible()
            ->upcoming()
            ->whereNotNull('capacity')
            ->where('capacity', '>', 0)
            ->withCount([
                'registrations as active_count' => fn ($query) => $query->whereIn(
                    'status', RegistrationStatus::occupying()
                ),
            ])
            ->get()
            ->map(function (Training $training) {
                // `withCount`'s alias is a runtime attribute, not a column, so
                // it is read as one rather than dressed up as a property.
                $registered = (int) $training->getAttribute('active_count');
                $capacity = (int) $training->capacity;

                return [
                    'label' => $training->title,
                    'count' => (int) round($registered / $capacity * 100),
                    'registered' => $registered,
                    'capacity' => $capacity,
                    'starts_at' => $training->starts_at->format('d M Y'),
                    'href' => route('admin.trainings.roster', $training),
                ];
            })
            ->sortBy('count')
            ->take(self::CAPACITY_WATCH)
            ->values()
            ->all();
    }

    // --- Plumbing ----------------------------------------------------------

    /**
     * The field-office scope, applied through the relation path that reaches a
     * profile. The same predicate the listings, exports and roster use.
     */
    private static function scopeToOffice(Builder $query, string $path, ?int $officeId): Builder
    {
        return $query->when($officeId !== null, fn (Builder $inner) => $inner->whereHas(
            $path,
            fn (Builder $profile) => $profile->where('field_office_id', $officeId),
        ));
    }

    /**
     * Several date windows counted in one round trip.
     *
     * Conditional aggregation rather than a `GROUP BY` on a date function:
     * the buckets are computed in PHP and sent as plain bounds, so nothing
     * here depends on a database's own calendar functions, and a month with no
     * rows still comes back as a zero instead of being absent from the result
     * — which is what turns a series into a chart with a hole in it. Six
     * months of trend is one query, not six.
     *
     * `$value` is what to add up per matching row — `1` to count them, a
     * column name to sum it. It is interpolated rather than bound because a
     * placeholder cannot stand for a column; every caller is in this file and
     * passes a literal.
     *
     * @param  array<int, array{0: string, 1: string}>  $ranges
     * @return array<int, float>
     */
    private static function bucketed(Builder $query, string $column, array $ranges, string $value = '1'): array
    {
        $selects = [];
        $bindings = [];

        foreach (array_values($ranges) as $index => [$from, $to]) {
            $selects[] = "SUM(CASE WHEN {$column} >= ? AND {$column} < ? THEN {$value} ELSE 0 END) as bucket_{$index}";
            $bindings[] = $from;
            $bindings[] = $to;
        }

        $row = (array) $query->toBase()->selectRaw(implode(', ', $selects), $bindings)->first();

        return array_map(
            fn (int $index) => (float) ($row["bucket_{$index}"] ?? 0),
            array_keys(array_values($ranges)),
        );
    }
}
