<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Attendance;
use App\Models\Certificate;
use App\Models\FieldOffice;
use App\Models\Payment;
use App\Models\Profile;
use App\Models\Registration;
use App\Models\Training;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Exports and analytics must honour field-office scoping.
 *
 * This is the highest-risk surface added in this phase: an export that leaked
 * another office's participants would be a data-protection incident, not a
 * cosmetic bug. Every assertion here is about what must NOT appear.
 */
class ExportScopingTest extends TestCase
{
    use RefreshDatabase;

    private FieldOffice $officeA;

    private FieldOffice $officeB;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->officeA, $this->officeB] = FieldOffice::active()->take(2)->get()->all();
    }

    private function staffFor(?FieldOffice $office, Role $role = Role::FieldOffice): User
    {
        return User::factory()->create([
            'role' => $role,
            'profile_completed_at' => now(),
            'field_office_id' => $office?->getKey(),
        ]);
    }

    /** Field-office staff carrying the collecting-officer designation. */
    private function collectorFor(?FieldOffice $office): User
    {
        $user = $this->staffFor($office);

        $user->forceFill(['is_collecting_officer' => true])->save();

        return $user;
    }

    private function participantIn(FieldOffice $office, string $name): User
    {
        $user = User::factory()->create(['name' => $name, 'profile_completed_at' => now()]);
        Profile::factory()->for($user)->create(['field_office_id' => $office->getKey()]);

        return $user->refresh();
    }

    /** Drain a streamed download into a string. */
    private function body(TestResponse $response): string
    {
        return $response->streamedContent();
    }

    public function test_participant_export_is_limited_to_the_staff_members_office(): void
    {
        $mine = $this->participantIn($this->officeA, 'ALPHA PARTICIPANT');
        $theirs = $this->participantIn($this->officeB, 'BRAVO PARTICIPANT');

        $response = $this->actingAs($this->staffFor($this->officeA))
            ->get('/admin/exports/participants')
            ->assertOk();

        $csv = $this->body($response);

        $this->assertStringContainsString($mine->name, $csv);
        $this->assertStringNotContainsString($theirs->name, $csv);
        $this->assertStringNotContainsString($theirs->email, $csv);
    }

    public function test_an_admin_export_covers_every_office(): void
    {
        $a = $this->participantIn($this->officeA, 'ALPHA PARTICIPANT');
        $b = $this->participantIn($this->officeB, 'BRAVO PARTICIPANT');

        $csv = $this->body(
            $this->actingAs($this->staffFor(null, Role::Admin))
                ->get('/admin/exports/participants')
                ->assertOk()
        );

        $this->assertStringContainsString($a->name, $csv);
        $this->assertStringContainsString($b->name, $csv);
    }

    public function test_the_certificate_export_is_limited_to_the_staff_members_office(): void
    {
        $mine = $this->participantIn($this->officeA, 'ALPHA CERT');
        $theirs = $this->participantIn($this->officeB, 'BRAVO CERT');

        $training = Training::factory()->create();

        $mineCert = Certificate::factory()->released()->create([
            'user_id' => $mine->id,
            'training_id' => $training->id,
        ]);
        $theirsCert = Certificate::factory()->released()->create([
            'user_id' => $theirs->id,
            'training_id' => $training->id,
        ]);

        $csv = $this->body(
            $this->actingAs($this->staffFor($this->officeA))
                ->get('/admin/exports/certificates')
                ->assertOk()
        );

        $this->assertStringContainsString($mineCert->certificate_number, $csv);
        $this->assertStringNotContainsString($theirsCert->certificate_number, $csv);
        $this->assertStringNotContainsString($theirs->email, $csv);
    }

    public function test_the_participant_export_carries_the_directorys_filters(): void
    {
        $wanted = $this->participantIn($this->officeA, 'ALPHA PARTICIPANT');
        $wanted->profile->update(['sector' => 'Judiciary']);

        $other = $this->participantIn($this->officeA, 'BRAVO PARTICIPANT');
        $other->profile->update(['sector' => 'Local Government Unit']);

        // v1's Export All downloaded what the administrator had narrowed the
        // table down to. Same filter names, read through ParticipantFilter, so
        // the two surfaces cannot answer differently.
        $csv = $this->body(
            $this->actingAs($this->staffFor(null, Role::Admin))
                ->get('/admin/exports/participants?sector=Judiciary')
                ->assertOk()
        );

        $this->assertStringContainsString($wanted->name, $csv);
        $this->assertStringNotContainsString($other->name, $csv);
    }

    public function test_a_filtered_participant_export_still_cannot_cross_offices(): void
    {
        $mine = $this->participantIn($this->officeA, 'ALPHA PARTICIPANT');
        $mine->profile->update(['sector' => 'Judiciary']);

        $theirs = $this->participantIn($this->officeB, 'BRAVO PARTICIPANT');
        $theirs->profile->update(['sector' => 'Judiciary']);

        // The scoping is not one of the filters — it is the base the filters
        // narrow, so no query string can widen it.
        $csv = $this->body(
            $this->actingAs($this->staffFor($this->officeA))
                ->get('/admin/exports/participants?sector=Judiciary&status=active')
                ->assertOk()
        );

        $this->assertStringContainsString($mine->name, $csv);
        $this->assertStringNotContainsString($theirs->name, $csv);
    }

    public function test_participant_history_carries_the_whole_record(): void
    {
        $participant = $this->participantIn($this->officeA, 'ALPHA PARTICIPANT');

        $training = Training::factory()->create([
            'title' => 'Records Management 101',
            'payment_required' => true,
            'payment_amount' => 1500,
        ]);

        $registration = Registration::factory()->approved()->create([
            'user_id' => $participant->getKey(),
            'training_id' => $training->getKey(),
        ]);

        Payment::factory()->create([
            'registration_id' => $registration->getKey(),
            'user_id' => $participant->getKey(),
            'training_id' => $training->getKey(),
            'amount' => 1200,
            'prime_hrm_discount' => true,
            'discount_amount' => 300,
            'or_number' => 'OR-HIST-001',
            'status' => PaymentStatus::Verified,
        ]);

        $csv = $this->body(
            $this->actingAs($this->staffFor(null, Role::Admin))
                ->get("/admin/exports/participants/{$participant->id}/history")
                ->assertOk()
        );

        $this->assertStringContainsString('Records Management 101', $csv);
        $this->assertStringContainsString('ALPHA PARTICIPANT', $csv);
        // The row stands on its own: what was decided, paid and receipted.
        $this->assertStringContainsString('OR-HIST-001', $csv);
        $this->assertStringContainsString('Yes (20%)', $csv);
    }

    public function test_participant_history_says_so_when_nothing_was_paid(): void
    {
        $participant = $this->participantIn($this->officeA, 'ALPHA PARTICIPANT');

        Registration::factory()->approved()->create([
            'user_id' => $participant->getKey(),
            'training_id' => Training::factory()->create()->getKey(),
        ]);

        $csv = $this->body(
            $this->actingAs($this->staffFor(null, Role::Admin))
                ->get("/admin/exports/participants/{$participant->id}/history")
                ->assertOk()
        );

        // An empty cell reads as "unknown"; this reads as "none", which is what
        // a free training or an unpaid registration actually means.
        $this->assertStringContainsString('No payment recorded', $csv);
    }

    public function test_participant_history_covers_only_that_participant(): void
    {
        $mine = $this->participantIn($this->officeA, 'ALPHA PARTICIPANT');
        $other = $this->participantIn($this->officeA, 'BRAVO PARTICIPANT');

        foreach ([$mine, $other] as $person) {
            Registration::factory()->approved()->create([
                'user_id' => $person->getKey(),
                'training_id' => Training::factory()->create()->getKey(),
            ]);
        }

        $csv = $this->body(
            $this->actingAs($this->staffFor(null, Role::Admin))
                ->get("/admin/exports/participants/{$mine->id}/history")
                ->assertOk()
        );

        $this->assertStringContainsString($mine->name, $csv);
        $this->assertStringNotContainsString($other->name, $csv);
    }

    public function test_participant_history_is_office_scoped(): void
    {
        $theirs = $this->participantIn($this->officeB, 'BRAVO PARTICIPANT');
        $mine = $this->participantIn($this->officeA, 'ALPHA PARTICIPANT');
        $staff = $this->staffFor($this->officeA);

        // 404, not 403 — a scoped officer has no business knowing the record
        // exists, which is the rule the participant directory already applies.
        $this->actingAs($staff)
            ->get("/admin/exports/participants/{$theirs->id}/history")
            ->assertNotFound();

        $this->actingAs($staff)
            ->get("/admin/exports/participants/{$mine->id}/history")
            ->assertOk();
    }

    public function test_a_staff_account_has_no_participant_history(): void
    {
        $staff = $this->staffFor($this->officeA, Role::Management);

        $this->actingAs($this->staffFor(null, Role::Admin))
            ->get("/admin/exports/participants/{$staff->id}/history")
            ->assertNotFound();
    }

    public function test_registration_export_is_scoped(): void
    {
        $training = Training::factory()->create();

        $mine = $this->participantIn($this->officeA, 'ALPHA PARTICIPANT');
        $theirs = $this->participantIn($this->officeB, 'BRAVO PARTICIPANT');

        Registration::factory()->approved()->create([
            'user_id' => $mine->getKey(), 'training_id' => $training->getKey(),
        ]);
        Registration::factory()->approved()->create([
            'user_id' => $theirs->getKey(), 'training_id' => $training->getKey(),
        ]);

        $csv = $this->body(
            $this->actingAs($this->staffFor($this->officeA))
                ->get('/admin/exports/registrations')
                ->assertOk()
        );

        $this->assertStringContainsString($mine->name, $csv);
        $this->assertStringNotContainsString($theirs->name, $csv);
    }

    /**
     * The affected-participants export carries fee state, which makes a leak
     * here worse than a roster leak: it would disclose another office's
     * participants *and* what each of them has or has not paid.
     */
    public function test_affected_export_is_scoped_and_names_the_fee_state(): void
    {
        $training = Training::factory()->paid()->create();

        $mine = $this->participantIn($this->officeA, 'ALPHA PARTICIPANT');
        $theirs = $this->participantIn($this->officeB, 'BRAVO PARTICIPANT');

        $registration = Registration::factory()->approved()->create([
            'user_id' => $mine->getKey(), 'training_id' => $training->getKey(),
        ]);
        Registration::factory()->approved()->create([
            'user_id' => $theirs->getKey(), 'training_id' => $training->getKey(),
        ]);

        Payment::factory()->verified()->create([
            'registration_id' => $registration->getKey(),
            'user_id' => $mine->getKey(),
            'training_id' => $training->getKey(),
            'payment_method' => PaymentMethod::Promissory,
        ]);

        $csv = $this->body(
            $this->actingAs($this->staffFor($this->officeA))
                ->get("/admin/exports/trainings/{$training->id}/affected")
                ->assertOk()
        );

        $this->assertStringContainsString($mine->name, $csv);
        $this->assertStringNotContainsString($theirs->name, $csv);
        // Spelled out, because "promissory" alone has been read as money in.
        $this->assertStringContainsString('Promissory note (unpaid)', $csv);
    }

    public function test_roster_export_is_scoped_and_carries_attendance(): void
    {
        $training = Training::factory()->startingToday()->runningFor(2)->create();

        $mine = $this->participantIn($this->officeA, 'ALPHA PARTICIPANT');
        $theirs = $this->participantIn($this->officeB, 'BRAVO PARTICIPANT');

        $registration = Registration::factory()->approved()->create([
            'user_id' => $mine->getKey(), 'training_id' => $training->getKey(),
        ]);
        Registration::factory()->approved()->create([
            'user_id' => $theirs->getKey(), 'training_id' => $training->getKey(),
        ]);

        Attendance::factory()->create([
            'registration_id' => $registration->getKey(),
            'attendance_date' => $training->starts_at->toDateString(),
        ]);

        $csv = $this->body(
            $this->actingAs($this->staffFor($this->officeA))
                ->get("/admin/exports/trainings/{$training->id}/roster")
                ->assertOk()
        );

        $this->assertStringContainsString($mine->name, $csv);
        $this->assertStringNotContainsString($theirs->name, $csv);
        // Per-day columns, and the recorded attendance in one of them.
        $this->assertStringContainsString('Day 1', $csv);
        $this->assertStringContainsString('Day 2', $csv);
        $this->assertStringContainsString('Present', $csv);
    }

    public function test_a_field_office_user_with_no_office_exports_nothing(): void
    {
        $this->participantIn($this->officeA, 'ALPHA PARTICIPANT');

        // Fails closed, matching FieldOfficeScopingTest's rule for listings.
        $csv = $this->body(
            $this->actingAs($this->staffFor(null))
                ->get('/admin/exports/participants')
                ->assertOk()
        );

        $this->assertStringNotContainsString('ALPHA PARTICIPANT', $csv);
    }

    public function test_only_designated_collectors_can_export_payments(): void
    {
        Payment::factory()->create();

        $this->actingAs($this->staffFor($this->officeA))
            ->get('/admin/exports/payments')
            ->assertForbidden();

        $this->actingAs($this->collectorFor(null))
            ->get('/admin/exports/payments')
            ->assertOk();
    }

    public function test_participants_cannot_reach_any_export(): void
    {
        $participant = $this->participantIn($this->officeA, 'ALPHA PARTICIPANT');

        foreach (['participants', 'registrations', 'payments', 'certificates'] as $export) {
            $this->actingAs($participant)
                ->get("/admin/exports/{$export}")
                ->assertForbidden();
        }
    }

    public function test_an_export_can_be_requested_as_xlsx(): void
    {
        $this->participantIn($this->officeA, 'ALPHA PARTICIPANT');

        $this->actingAs($this->staffFor(null, Role::Admin))
            ->get('/admin/exports/participants?format=xlsx')
            ->assertOk()
            ->assertHeader(
                'content-type',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
            );
    }

    public function test_an_unknown_format_falls_back_to_csv(): void
    {
        $this->participantIn($this->officeA, 'ALPHA PARTICIPANT');

        $this->actingAs($this->staffFor(null, Role::Admin))
            ->get('/admin/exports/participants?format=exe')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }

    // --- The download handshake -------------------------------------------

    /**
     * An export is a plain link, so the page that opened it gets no event when
     * the download begins — the button would stay pending forever, or never
     * show pending at all. `?_dl=` comes back as a cookie, and that is the
     * whole signal useDownload.js waits on.
     */
    public function test_an_export_echoes_the_download_token_back_as_a_cookie(): void
    {
        $this->participantIn($this->officeA, 'ALPHA PARTICIPANT');

        $this->actingAs($this->staffFor(null, Role::Admin))
            ->get('/admin/exports/participants?_dl=abc123')
            ->assertOk()
            // encrypted: false — the page has to read this value verbatim, and
            // the third test below is the guard on that staying true.
            ->assertCookie('dl_token', 'abc123', false);
    }

    /**
     * The token is reflected into a response header, so it is validated rather
     * than trusted. A value carrying a newline is how an echo like this turns
     * into response splitting; anything that is not a plain token comes back
     * empty and the page falls back to its own timeout.
     */
    public function test_a_malformed_download_token_is_not_reflected(): void
    {
        $this->participantIn($this->officeA, 'ALPHA PARTICIPANT');

        $this->actingAs($this->staffFor(null, Role::Admin))
            ->get('/admin/exports/participants?_dl='.urlencode("evil\r\nSet-Cookie: admin=1"))
            ->assertOk()
            ->assertCookie('dl_token', '', false);
    }

    /**
     * The cookie must stay readable by JavaScript. Laravel encrypts cookies by
     * default, and an encrypted value would never match the token the page
     * generated — the button would hang until its timeout on every export.
     */
    public function test_the_download_token_cookie_is_not_encrypted(): void
    {
        $this->participantIn($this->officeA, 'ALPHA PARTICIPANT');

        $response = $this->actingAs($this->staffFor(null, Role::Admin))
            ->get('/admin/exports/participants?_dl=plaintoken');

        $cookie = collect($response->headers->getCookies())
            ->firstWhere(fn ($candidate) => $candidate->getName() === 'dl_token');

        $this->assertNotNull($cookie);
        $this->assertSame('plaintoken', $cookie->getValue());
        $this->assertFalse($cookie->isHttpOnly(), 'The page has to be able to read this cookie.');
    }

    // --- Analytics --------------------------------------------------------

    /**
     * The analytics overview, fetched the way the browser fetches it.
     *
     * `overview` is a deferred prop: the first response carries the page shell
     * without it, and Inertia asks for it in a follow-up partial request once
     * the page has mounted. A plain GET therefore sees no `overview` at all,
     * which would silently turn every scoping assertion below into an
     * assertion about an absent key — the failure mode these tests exist to
     * catch. Asking for it explicitly keeps them pointed at the real figures.
     *
     * A partial visit answers in JSON rather than the HTML document, so these
     * tests read the payload with `json('props.overview.…')` instead of
     * `assertInertia` — that helper asserts against the view data an initial
     * page render leaves behind, which a partial response does not have.
     */
    private function analyticsOverview(string $query = ''): TestResponse
    {
        /*
         * The version has to come from the middleware, not from
         * Inertia::getVersion(): the facade only knows the version after a
         * request has passed through HandleInertiaRequests, so reading it up
         * front yields an empty string and every one of these requests comes
         * back 409 Conflict — Inertia's asset-refresh signal, not an error the
         * assertions below would explain.
         */
        $version = app(HandleInertiaRequests::class)->version(request());

        return $this->get('/admin/analytics'.$query, [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) $version,
            'X-Inertia-Partial-Component' => 'Admin/Analytics',
            'X-Inertia-Partial-Data' => 'overview',
        ]);
    }

    public function test_analytics_counts_are_scoped_to_the_office(): void
    {
        $training = Training::factory()->create();

        Registration::factory()->approved()->create([
            'user_id' => $this->participantIn($this->officeA, 'ALPHA ONE')->getKey(),
            'training_id' => $training->getKey(),
        ]);
        Registration::factory()->approved()->create([
            'user_id' => $this->participantIn($this->officeB, 'BRAVO ONE')->getKey(),
            'training_id' => $training->getKey(),
        ]);
        Registration::factory()->approved()->create([
            'user_id' => $this->participantIn($this->officeB, 'BRAVO TWO')->getKey(),
            'training_id' => $training->getKey(),
        ]);

        $this->actingAs($this->staffFor($this->officeA))
            ->analyticsOverview()
            ->assertOk()
            ->assertJsonPath('component', 'Admin/Analytics')
            ->assertJsonPath('props.overview.headline.registrations', 1)
            // The per-office breakdown is meaningless when scoped to one.
            ->assertJsonCount(0, 'props.overview.byFieldOffice');

        $this->actingAs($this->staffFor(null, Role::Admin))
            ->analyticsOverview()
            ->assertOk()
            ->assertJsonPath('props.overview.headline.registrations', 3);
    }

    /**
     * The demographic cuts are what CSC reports upward, and they are scoped
     * like everything else — a field office reporting the region's intake as
     * its own would be worse than not reporting at all.
     */
    public function test_demographic_breakdowns_are_scoped_to_the_office(): void
    {
        $training = Training::factory()->create();

        foreach ([[$this->officeA, 'ALPHA ONE'], [$this->officeB, 'BRAVO ONE'], [$this->officeB, 'BRAVO TWO']] as [$office, $name]) {
            Registration::factory()->approved()->create([
                'user_id' => $this->participantIn($office, $name)->getKey(),
                'training_id' => $training->getKey(),
            ]);
        }

        $total = fn (array $rows) => array_sum(array_column($rows, 'count'));

        $scoped = $this->actingAs($this->staffFor($this->officeA))
            ->analyticsOverview()
            ->assertOk();

        $this->assertSame(1, $total($scoped->json('props.overview.demographics.sex')));
        $this->assertSame(1, $total($scoped->json('props.overview.demographics.positionLevel')));
        // The geographic cuts are scoped like every other one — a region chart
        // that leaked the whole region would be the same disclosure as a name
        // list.
        $this->assertSame(1, $total($scoped->json('props.overview.demographics.region')));
        $this->assertSame(1, $total($scoped->json('props.overview.demographics.province')));
        $this->assertSame(1, $total($scoped->json('props.overview.topAgencies')));

        $region = $this->actingAs($this->staffFor(null, Role::Admin))
            ->analyticsOverview()
            ->assertOk();

        $this->assertSame(3, $total($region->json('props.overview.demographics.sex')));
        $this->assertSame(3, $total($region->json('props.overview.demographics.region')));
        $this->assertSame(3, $total($region->json('props.overview.topAgencies')));
    }

    /**
     * Every cut counts the same registrations, so their totals must agree.
     * A per-column GROUP BY would quietly drop rows with a blank field and
     * leave two charts on the same page disagreeing about the intake.
     */
    public function test_every_demographic_cut_totals_the_same(): void
    {
        $training = Training::factory()->create();

        foreach (['ALPHA ONE', 'ALPHA TWO', 'ALPHA THREE'] as $name) {
            Registration::factory()->approved()->create([
                'user_id' => $this->participantIn($this->officeA, $name)->getKey(),
                'training_id' => $training->getKey(),
            ]);
        }

        $demographics = $this->actingAs($this->staffFor(null, Role::Admin))
            ->analyticsOverview()
            ->assertOk()
            ->json('props.overview.demographics');

        $totals = array_map(
            fn ($rows) => array_sum(array_column($rows, 'count')),
            $demographics,
        );

        $this->assertSame([3], array_values(array_unique($totals)));
    }

    public function test_age_bands_keep_their_order_rather_than_sorting_by_size(): void
    {
        $training = Training::factory()->create();

        Registration::factory()->approved()->create([
            'user_id' => $this->participantIn($this->officeA, 'ALPHA ONE')->getKey(),
            'training_id' => $training->getKey(),
        ]);

        $bands = $this->actingAs($this->staffFor(null, Role::Admin))
            ->analyticsOverview()
            ->assertOk()
            ->json('props.overview.demographics.ageBand');

        $this->assertSame(
            ['18-25', '26-35', '36-45', '46-55', '56-65', 'Over 65'],
            array_column($bands, 'label'),
        );
    }

    public function test_analytics_hides_money_from_non_finance_roles(): void
    {
        Payment::factory()->verified()->create();

        $this->actingAs($this->staffFor($this->officeA))
            ->analyticsOverview()
            ->assertOk()
            ->assertJsonPath('props.overview.payments', null);

        $this->actingAs($this->collectorFor(null))
            ->analyticsOverview()
            ->assertOk()
            ->assertJsonStructure(['props' => ['overview' => ['payments' => ['verified_total']]]]);
    }

    public function test_analytics_reports_an_attendance_rate(): void
    {
        $training = Training::factory()->startingToday()->create();
        $registration = Registration::factory()->approved()->create([
            'user_id' => $this->participantIn($this->officeA, 'ALPHA ONE')->getKey(),
            'training_id' => $training->getKey(),
        ]);

        Attendance::factory()->create(['registration_id' => $registration->getKey()]);
        Attendance::factory()->absent()->onDay(2)->create(['registration_id' => $registration->getKey()]);

        $this->actingAs($this->staffFor(null, Role::Admin))
            ->analyticsOverview()
            ->assertOk()
            ->assertJsonPath('props.overview.attendance.total', 2)
            // One Present, one Absent — JSON renders the 50.0 as 50.
            ->assertJsonPath('props.overview.attendance.rate', 50);
    }
}
