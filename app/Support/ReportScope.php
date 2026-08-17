<?php

namespace App\Support;

use App\Models\Training;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * The scope of one analytics report: a single training, or all trainings
 * conducted within a calendar period.
 *
 * Shared by the analytics page and the report exports so the two surfaces
 * cannot answer differently — the same reason ParticipantFilter exists.
 *
 * A training "conducted in a period" is judged by starts_at, the primary date
 * of a run; a multi-day program belongs to the month it started in.
 */
class ReportScope
{
    public function __construct(
        public readonly string $view,
        public readonly ?int $trainingId,
        public readonly string $period,
        public readonly int $year,
        public readonly ?int $month,
        public readonly ?int $quarter,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $view = $request->string('view')->toString();
        $view = in_array($view, ['training', 'period'], true) ? $view : 'period';

        $period = $request->string('period', 'monthly')->toString();
        $period = in_array($period, ['monthly', 'quarterly', 'annual'], true) ? $period : 'monthly';

        $year = max(2000, min((int) now()->year, $request->integer('year', (int) now()->year)));
        $month = max(1, min(12, $request->integer('month', (int) now()->month)));
        $quarter = max(1, min(4, $request->integer('quarter', (int) ceil(now()->month / 3))));

        $trainingId = $request->filled('training_id') ? $request->integer('training_id') : null;

        return new self($view, $trainingId, $period, $year, $month, $quarter);
    }

    /**
     * The trainings the report covers.
     *
     * For the period views, "conducted in the period" by starts_at.
     */
    public function trainingsQuery(): Builder
    {
        if ($this->view === 'training') {
            return Training::query()->whereKey($this->trainingId ?? 0);
        }

        [$start, $end] = $this->periodBounds();

        return Training::query()->whereBetween('starts_at', [$start, $end]);
    }

    /**
     * @return array{CarbonImmutable, CarbonImmutable}|null
     */
    public function periodBounds(): ?array
    {
        if ($this->view !== 'period') {
            return null;
        }

        $january = CarbonImmutable::create($this->year, 1, 1);

        return match ($this->period) {
            'monthly' => [
                CarbonImmutable::create($this->year, $this->month, 1)->startOfMonth(),
                CarbonImmutable::create($this->year, $this->month, 1)->endOfMonth(),
            ],
            'quarterly' => [
                $january->addMonths(($this->quarter - 1) * 3)->startOfMonth(),
                $january->addMonths(($this->quarter - 1) * 3 + 2)->endOfMonth(),
            ],
            'annual' => [$january->startOfYear(), $january->endOfYear()],
        };
    }

    /** Human label, e.g. "August 2026", "Q3 2026", "2026". */
    public function periodLabel(): string
    {
        if ($this->view !== 'period') {
            return '';
        }

        return match ($this->period) {
            'monthly' => CarbonImmutable::create($this->year, $this->month, 1)->format('F Y'),
            'quarterly' => 'Q'.$this->quarter.' '.$this->year,
            'annual' => (string) $this->year,
        };
    }

    /**
     * The months inside the period, in order, for a per-period trend.
     *
     * @return array<int, CarbonImmutable>
     */
    public function monthsInPeriod(): array
    {
        [$start, $end] = $this->periodBounds();

        $months = [];
        $cursor = $start->startOfMonth();

        while ($cursor->lessThanOrEqualTo($end)) {
            $months[] = $cursor;
            $cursor = $cursor->addMonth();
        }

        return $months;
    }

    /** Safe filename stem, e.g. "training-7" or "quarterly-2026-3". */
    public function exportSlug(): string
    {
        if ($this->view === 'training') {
            return 'training-'.$this->trainingId;
        }

        return match ($this->period) {
            'monthly' => 'monthly-'.$this->year.'-'.$this->month,
            'quarterly' => 'quarterly-'.$this->year.'-'.$this->quarter,
            'annual' => 'annual-'.$this->year,
        };
    }
}
