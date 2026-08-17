<?php

namespace App\Http\Controllers;

use App\Enums\RegistrationStatus;
use App\Enums\TrainingStatus;
use App\Models\Registration;
use App\Models\Training;
use App\Models\User;
use App\Support\PublicCatalogService;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    /**
     * Show the public landing page.
     */
    public function index(): Response
    {
        return Inertia::render('Home', [
            'stats' => $this->stats(),
            'upcomingTrainings' => $this->upcomingTrainings(),
        ]);
    }

    /**
     * How many programs the landing page advertises: two full rows of three.
     * The rest live on /programs, which the section links out to.
     */
    private const LIMIT = 6;

    /**
     * The first few programs on offer, for the landing page.
     *
     * The query, the payload and the status vocabulary are
     * PublicCatalogService's — /programs shows the same cards from the same
     * source, and the two must never describe a program differently depending
     * on which page you met it on.
     *
     * @return array<int, array<string, mixed>>
     */
    private function upcomingTrainings(): array
    {
        return PublicCatalogService::query()
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (Training $training) => PublicCatalogService::card($training))
            ->all();
    }

    /**
     * Landing-page headline figures.
     *
     * Real counts straight from the database, cached for an hour so a public
     * page never runs four queries per hit. The regional-offices figure stays a
     * constant on purpose: it describes the nationwide CSC organisation, which
     * this database (a single regional deployment) cannot count.
     *
     * @return array<int, array{figure: string, label: string}>
     */
    private function stats(): array
    {
        return Cache::remember('home.stats', now()->addHour(), function () {
            $enrolled = User::whereNotNull('profile_completed_at')->count();

            // "Delivered" = a training that has actually begun, any status that
            // is not still a draft or unceremoniously cancelled.
            $delivered = Training::whereNotIn('status', [TrainingStatus::Draft, TrainingStatus::Cancelled])
                ->where('starts_at', '<=', now())
                ->count();

            $approvedOrCompleted = Registration::whereIn('status', [
                RegistrationStatus::Approved,
                RegistrationStatus::Completed,
            ])->count();
            $completed = Registration::where('status', RegistrationStatus::Completed)->count();
            $completion = $approvedOrCompleted
                ? (int) round($completed / $approvedOrCompleted * 100)
                : 0;

            return [
                ['figure' => number_format($enrolled), 'label' => 'Personnel enrolled'],
                ['figure' => number_format($delivered), 'label' => 'Programs delivered'],
                ['figure' => $completion.'%', 'label' => 'Completion rate'],
                ['figure' => '17', 'label' => 'Regional offices'],
            ];
        });
    }
}
