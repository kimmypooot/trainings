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
use App\Models\Registration;
use App\Models\Training;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
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
     * Money, for the roles that handle it.
     *
     * Not office-scoped, deliberately: none of the financial roles are tied to
     * a field office, and the office-scoped roles cannot see this block at all.
     *
     * @return array<string, mixed>|null
     */
    private function payments(Request $request): ?array
    {
        if (! $request->user()->role->handlesPayments()) {
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
