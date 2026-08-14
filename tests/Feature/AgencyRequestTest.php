<?php

namespace Tests\Feature;

use App\Enums\AgencyDocumentKind;
use App\Enums\AgencyRequestStatus;
use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\AgencyRequest;
use App\Models\Profile;
use App\Models\User;
use App\Notifications\AgencyRequestUpdated;
use App\Support\AgencyRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * The agency-request correspondence, ported from v1's `training_requests`,
 * `training_requirements`, `training_confirmations` and `training_completions`.
 */
class AgencyRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    private function agencyUser(): User
    {
        $user = User::factory()->create(['profile_completed_at' => now()]);
        Profile::factory()->for($user)->create(['organization_name' => 'Municipality of Palo']);

        return $user->refresh();
    }

    private function staff(Role $role = Role::Admin): User
    {
        return User::factory()->create(['role' => $role, 'profile_completed_at' => now()]);
    }

    private function pdf(string $name = 'letter.pdf'): UploadedFile
    {
        return UploadedFile::fake()->create($name, 64, 'application/pdf');
    }

    /** A request filed and walked forward to a given stage. */
    private function requestAt(AgencyRequestStatus $stage, ?User $agency = null): AgencyRequest
    {
        $agency ??= $this->agencyUser();
        $staff = $this->staff();

        $request = AgencyRequestService::submit($agency, [
            'agency_name' => 'Municipality of Palo',
            'training_title' => 'Records Management',
            'proposed_start' => now()->addMonth()->toDateString(),
            'proposed_end' => now()->addMonth()->addDays(2)->toDateString(),
            'proposed_venue' => 'Municipal Hall, Palo',
            'expected_participants' => 30,
        ], $this->pdf());

        if ($stage === AgencyRequestStatus::Pending) {
            return $request;
        }

        AgencyRequestService::assign($request, $staff);

        if ($stage === AgencyRequestStatus::UnderReview) {
            return $request->fresh();
        }

        AgencyRequestService::sendRequirements(
            $request->fresh(),
            $staff,
            'Please return the signed confirmation form.',
            $this->pdf('response.pdf'),
            $this->pdf('form.pdf'),
        );

        if ($stage === AgencyRequestStatus::RequirementsSent) {
            return $request->fresh();
        }

        AgencyRequestService::submitConfirmation($request->fresh(), $agency, [
            'confirmed_start' => now()->addMonth()->toDateString(),
            'confirmed_end' => now()->addMonth()->addDays(2)->toDateString(),
            'confirmed_venue' => 'Municipal Hall, Palo',
        ], $this->pdf('signed.pdf'));

        return $request->fresh();
    }

    private function completionFiles(): array
    {
        return [
            AgencyDocumentKind::CertificateOfDuties->value => $this->pdf('duties.pdf'),
            AgencyDocumentKind::AttendanceSheet->value => $this->pdf('attendance.pdf'),
            AgencyDocumentKind::ProofOfPayment->value => $this->pdf('payment.pdf'),
        ];
    }

    // --- Filing ------------------------------------------------------------

    public function test_an_agency_files_a_request_with_its_letter(): void
    {
        $agency = $this->agencyUser();

        $this->actingAs($agency)
            ->post('/my/agency-requests', [
                'training_title' => 'Records Management',
                'proposed_start' => now()->addMonth()->toDateString(),
                'proposed_end' => now()->addMonth()->addDays(2)->toDateString(),
                'proposed_venue' => 'Municipal Hall, Palo',
                'expected_participants' => 30,
                'request_letter' => $this->pdf(),
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $request = AgencyRequest::sole();

        $this->assertSame(AgencyRequestStatus::Pending, $request->status);
        // Falls back to the profile's organisation when not given explicitly.
        $this->assertSame('Municipality of Palo', $request->agency_name);
        $this->assertMatchesRegularExpression('/^AGR-\d{4}-\d{3}$/', $request->request_code);
        $this->assertTrue($request->hasDocument(AgencyDocumentKind::RequestLetter));
        Storage::disk('local')->assertExists($request->latestDocument(AgencyDocumentKind::RequestLetter)->file_path);
    }

    /**
     * The letter is what makes it a formal request rather than an enquiry, so
     * it cannot follow later.
     */
    public function test_a_request_without_a_letter_is_refused(): void
    {
        $this->actingAs($this->agencyUser())
            ->from('/my/agency-requests')
            ->post('/my/agency-requests', [
                'training_title' => 'Records Management',
                'proposed_start' => now()->addMonth()->toDateString(),
                'proposed_end' => now()->addMonth()->addDays(2)->toDateString(),
                'proposed_venue' => 'Municipal Hall, Palo',
            ])
            ->assertSessionHasErrors('request_letter');

        $this->assertSame(0, AgencyRequest::count());
    }

    // --- HRD side ----------------------------------------------------------

    public function test_picking_a_request_up_moves_it_into_review(): void
    {
        $request = $this->requestAt(AgencyRequestStatus::Pending);
        $staff = $this->staff();

        $this->actingAs($staff)
            ->post("/admin/agency-requests/{$request->id}/assign")
            ->assertSessionHas('success');

        $request = $request->fresh();

        $this->assertSame(AgencyRequestStatus::UnderReview, $request->status);
        $this->assertSame($staff->getKey(), $request->assigned_to);
    }

    /** Two officers writing to the same agency is worse than nobody doing it. */
    public function test_a_request_already_assigned_cannot_be_taken_by_someone_else(): void
    {
        $request = $this->requestAt(AgencyRequestStatus::UnderReview);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('already assigned');

        AgencyRequestService::assign($request, $this->staff());
    }

    public function test_sending_requirements_attaches_both_documents(): void
    {
        $request = $this->requestAt(AgencyRequestStatus::UnderReview);

        $this->actingAs($this->staff())
            ->post("/admin/agency-requests/{$request->id}/requirements", [
                'requirements_text' => 'Return the signed confirmation form within ten days.',
                'response_letter' => $this->pdf('response.pdf'),
                'blank_confirmation_form' => $this->pdf('form.pdf'),
            ])
            ->assertSessionHas('success');

        $request = $request->fresh()->load('documents');

        $this->assertSame(AgencyRequestStatus::RequirementsSent, $request->status);
        $this->assertTrue($request->hasDocument(AgencyDocumentKind::ResponseLetter));
        $this->assertTrue($request->hasDocument(AgencyDocumentKind::BlankConfirmationForm));
        $this->assertNotNull($request->requirements_sent_at);
    }

    public function test_the_ord_is_not_notified_twice(): void
    {
        $request = $this->requestAt(AgencyRequestStatus::Pending);
        $staff = $this->staff();

        AgencyRequestService::notifyOrd($request, $staff);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('already been notified');

        AgencyRequestService::notifyOrd($request->fresh(), $staff);
    }

    // --- Agency's reply ----------------------------------------------------

    public function test_the_confirmation_records_the_agreed_dates_separately(): void
    {
        $agency = $this->agencyUser();
        $request = $this->requestAt(AgencyRequestStatus::RequirementsSent, $agency);

        $proposedStart = $request->proposed_start->toDateString();
        $agreedStart = now()->addMonths(2)->toDateString();

        $this->actingAs($agency)
            ->post("/my/agency-requests/{$request->id}/confirmation", [
                'confirmed_start' => $agreedStart,
                'confirmed_end' => now()->addMonths(2)->addDays(2)->toDateString(),
                'confirmed_venue' => 'Leyte Provincial Capitol',
                'signed_confirmation_form' => $this->pdf('signed.pdf'),
            ])
            ->assertSessionHas('success');

        $request = $request->fresh();

        $this->assertSame(AgencyRequestStatus::Confirmed, $request->status);
        // The gap between what was asked for and what was agreed is the thing
        // people query later, so the proposal must survive the confirmation.
        $this->assertSame($proposedStart, $request->proposed_start->toDateString());
        $this->assertSame($agreedStart, $request->confirmed_start->toDateString());
        $this->assertSame('Leyte Provincial Capitol', $request->confirmed_venue);
    }

    public function test_a_confirmation_before_the_requirements_are_sent_is_refused(): void
    {
        $agency = $this->agencyUser();
        $request = $this->requestAt(AgencyRequestStatus::UnderReview, $agency);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('once HRD has sent the requirements');

        AgencyRequestService::submitConfirmation($request, $agency, [
            'confirmed_start' => now()->addMonth()->toDateString(),
            'confirmed_end' => now()->addMonth()->addDay()->toDateString(),
            'confirmed_venue' => 'Anywhere',
        ], $this->pdf());
    }

    // --- Completion --------------------------------------------------------

    /**
     * An incomplete set is kept but not marked as submitted. Agencies gather
     * these documents over days from different offices; refusing the upload
     * wholesale would throw away files they had already found.
     */
    public function test_a_partial_completion_is_kept_but_not_marked_submitted(): void
    {
        $agency = $this->agencyUser();
        $request = $this->requestAt(AgencyRequestStatus::Confirmed, $agency);

        $request = AgencyRequestService::submitCompletion($request, $agency, [
            AgencyDocumentKind::CertificateOfDuties->value => $this->pdf('duties.pdf'),
        ]);

        $this->assertNull($request->completion_submitted_at);
        $this->assertTrue($request->hasDocument(AgencyDocumentKind::CertificateOfDuties));
        $this->assertCount(2, $request->missingCompletionDocuments());
    }

    /**
     * Completeness is assessed against everything the request holds, not just
     * what this upload carried — so a follow-up supplying the last piece
     * finishes the set.
     */
    public function test_a_follow_up_supplying_the_last_document_completes_the_set(): void
    {
        $agency = $this->agencyUser();
        $request = $this->requestAt(AgencyRequestStatus::Confirmed, $agency);

        AgencyRequestService::submitCompletion($request, $agency, [
            AgencyDocumentKind::CertificateOfDuties->value => $this->pdf('duties.pdf'),
            AgencyDocumentKind::AttendanceSheet->value => $this->pdf('attendance.pdf'),
        ]);

        $request = AgencyRequestService::submitCompletion($request->fresh(), $agency, [
            AgencyDocumentKind::ProofOfPayment->value => $this->pdf('payment.pdf'),
        ], 15000.00);

        $this->assertNotNull($request->completion_submitted_at);
        $this->assertSame('15000.00', $request->payment_amount);
        $this->assertSame([], $request->missingCompletionDocuments());
    }

    /** And the incomplete state still blocks HRD from closing the request. */
    public function test_payment_cannot_be_verified_on_a_partial_submission(): void
    {
        $agency = $this->agencyUser();
        $request = $this->requestAt(AgencyRequestStatus::Confirmed, $agency);

        AgencyRequestService::submitCompletion($request, $agency, [
            AgencyDocumentKind::CertificateOfDuties->value => $this->pdf('duties.pdf'),
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('not been submitted yet');

        AgencyRequestService::verifyPayment($request->fresh(), $this->staff());
    }

    /**
     * v1 let the agency's own submission set the request to `completed`, which
     * meant they effectively signed off on their own payment.
     */
    public function test_submitting_documents_does_not_complete_the_request(): void
    {
        $agency = $this->agencyUser();
        $request = $this->requestAt(AgencyRequestStatus::Confirmed, $agency);

        $request = AgencyRequestService::submitCompletion($request, $agency, $this->completionFiles(), 15000.00);

        $this->assertSame(AgencyRequestStatus::Confirmed, $request->status);
        $this->assertNull($request->payment_verified_at);
    }

    public function test_verifying_the_payment_completes_the_request(): void
    {
        $agency = $this->agencyUser();
        $request = $this->requestAt(AgencyRequestStatus::Confirmed, $agency);
        AgencyRequestService::submitCompletion($request, $agency, $this->completionFiles(), 15000.00);

        $staff = $this->staff();

        $this->actingAs($staff)
            ->post("/admin/agency-requests/{$request->id}/verify-payment", ['notes' => 'OR issued.'])
            ->assertSessionHas('success');

        $request = $request->fresh();

        $this->assertSame(AgencyRequestStatus::Completed, $request->status);
        $this->assertSame($staff->getKey(), $request->payment_verified_by);
        $this->assertNotNull($request->closed_at);
    }

    public function test_payment_cannot_be_verified_before_documents_arrive(): void
    {
        $request = $this->requestAt(AgencyRequestStatus::Confirmed);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('not been submitted yet');

        AgencyRequestService::verifyPayment($request, $this->staff());
    }

    // --- Closing -----------------------------------------------------------

    /**
     * HRD may find out late that a training cannot run, and a request with no
     * way to close sits in the queue forever.
     */
    public function test_a_request_can_be_declined_from_any_open_stage(): void
    {
        foreach ([
            AgencyRequestStatus::Pending,
            AgencyRequestStatus::UnderReview,
            AgencyRequestStatus::RequirementsSent,
            AgencyRequestStatus::Confirmed,
        ] as $stage) {
            $request = $this->requestAt($stage);

            AgencyRequestService::reject($request, $this->staff(), 'The budget was withdrawn.');

            $this->assertSame(AgencyRequestStatus::Rejected, $request->fresh()->status, $stage->value);
        }
    }

    public function test_a_closed_request_cannot_be_reopened_by_a_decision(): void
    {
        $request = $this->requestAt(AgencyRequestStatus::Pending);
        AgencyRequestService::reject($request, $this->staff(), 'Not this year.');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('already Rejected');

        AgencyRequestService::reject($request->fresh(), $this->staff(), 'Again.');
    }

    /** Once confirmed, CSC has committed to the run. */
    public function test_an_agency_cannot_withdraw_a_confirmed_request(): void
    {
        $agency = $this->agencyUser();
        $request = $this->requestAt(AgencyRequestStatus::Confirmed, $agency);

        $this->actingAs($agency)
            ->get('/my/agency-requests')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('requests.0.can_cancel', false));
    }

    // --- Access ------------------------------------------------------------

    public function test_documents_are_reachable_by_the_filing_agency_and_staff_only(): void
    {
        $agency = $this->agencyUser();
        $request = $this->requestAt(AgencyRequestStatus::Pending, $agency);
        $document = $request->latestDocument(AgencyDocumentKind::RequestLetter);

        $this->actingAs($agency)->get("/agency-request-documents/{$document->id}")->assertOk();
        $this->actingAs($this->staff())->get("/agency-request-documents/{$document->id}")->assertOk();
        $this->actingAs($this->agencyUser())->get("/agency-request-documents/{$document->id}")->assertForbidden();
    }

    public function test_another_agency_cannot_act_on_a_request(): void
    {
        $request = $this->requestAt(AgencyRequestStatus::RequirementsSent);

        $this->actingAs($this->agencyUser())
            ->post("/my/agency-requests/{$request->id}/confirmation", [
                'confirmed_start' => now()->addMonth()->toDateString(),
                'confirmed_end' => now()->addMonth()->addDay()->toDateString(),
                'confirmed_venue' => 'Elsewhere',
                'signed_confirmation_form' => $this->pdf(),
            ])
            ->assertForbidden();
    }

    public function test_the_admin_queue_is_closed_to_field_office_staff(): void
    {
        $this->actingAs($this->staff(Role::Admin))->get('/admin/agency-requests')->assertOk();
        $this->actingAs($this->staff(Role::FieldOffice))->get('/admin/agency-requests')->assertForbidden();
        $this->actingAs($this->agencyUser())->get('/admin/agency-requests')->assertForbidden();
    }

    /** The queue's whole point is separating "waiting on us" from "waiting on them". */
    public function test_the_queue_separates_whose_move_it_is(): void
    {
        $this->requestAt(AgencyRequestStatus::Pending);
        $this->requestAt(AgencyRequestStatus::RequirementsSent);

        $this->actingAs($this->staff())
            ->get('/admin/agency-requests?filter=ours')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('requests.data', 1)
                ->where('requests.data.0.status', AgencyRequestStatus::Pending->value)
            );

        $this->actingAs($this->staff())
            ->get('/admin/agency-requests?filter=theirs')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('requests.data', 1)
                ->where('requests.data.0.status', AgencyRequestStatus::RequirementsSent->value)
            );
    }

    // --- Trail and notifications -------------------------------------------

    public function test_every_move_lands_in_the_activity_trail(): void
    {
        $agency = $this->agencyUser();
        $request = $this->requestAt(AgencyRequestStatus::Confirmed, $agency);
        AgencyRequestService::submitCompletion($request, $agency, $this->completionFiles());
        AgencyRequestService::verifyPayment($request->fresh(), $this->staff());

        $actions = ActivityLog::forSubject($request)->pluck('action')->all();

        $this->assertSame([
            'agency-request.submitted',
            'agency-request.assigned',
            'agency-request.requirements-sent',
            'agency-request.confirmed',
            'agency-request.completion-submitted',
            'agency-request.completed',
        ], $actions);
    }

    public function test_the_agency_is_notified_of_hrd_moves(): void
    {
        Notification::fake();

        $agency = $this->agencyUser();
        $request = $this->requestAt(AgencyRequestStatus::Pending, $agency);

        AgencyRequestService::assign($request, $this->staff());

        Notification::assertSentTo($agency, AgencyRequestUpdated::class);
    }
}
