<?php

namespace Tests\Feature;

use App\Enums\ChargeTo;
use App\Enums\RegistrationStatus;
use App\Enums\Role;
use App\Enums\SupervisoryDocumentStatus;
use App\Models\FieldOffice;
use App\Models\Payment;
use App\Models\Profile;
use App\Models\Registration;
use App\Models\Training;
use App\Models\User;
use App\Notifications\RegistrationReviewed;
use App\Support\RegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The supervisory-course supporting document, end to end: it is required in
 * the SG 15–17 band, set at registration, judged on the roster, replaced when
 * rejected, and visible to the participant on their own registrations page.
 */
class SupervisoryDocumentTest extends TestCase
{
    use RefreshDatabase;

    private function participantIn(FieldOffice $office, string $email, string $grade = 'SG 16'): User
    {
        $user = User::factory()->create(['email' => $email, 'profile_completed_at' => now()]);
        Profile::factory()->for($user)->create(['field_office_id' => $office->id, 'salary_grade' => $grade]);

        return $user->refresh();
    }

    private function fieldOfficeStaff(FieldOffice $office): User
    {
        return User::factory()->create([
            'role' => Role::FieldOffice,
            'field_office_id' => $office->id,
            'profile_completed_at' => now(),
        ]);
    }

    private function hrd(): User
    {
        return User::factory()->create(['role' => Role::Admin, 'profile_completed_at' => now()]);
    }

    private function supervisoryTraining(): Training
    {
        return Training::factory()->create(['is_supervisory' => true]);
    }

    /**
     * Register a participant of the SG 15–17 band with a document attached, and
     * return the resulting registration with its training relation loaded.
     */
    private function registerWithDocument(User $user, Training $training): Registration
    {
        Storage::fake('local');

        $this->actingAs($user)
            ->post("/trainings/{$training->id}/register", [
                'charge_to' => ChargeTo::Personal->value,
                'needs_certificate' => true,
                'supporting_document' => UploadedFile::fake()->create('designation.pdf', 64, 'application/pdf'),
            ])
            ->assertSessionHas('success');

        return Registration::where('user_id', $user->getKey())->firstOrFail();
    }

    public function test_registration_marks_the_document_submitted_for_verification(): void
    {
        $training = $this->supervisoryTraining();
        $user = $this->participantIn(FieldOffice::where('code', 'lfoi')->firstOrFail(), 'betsy@example.com');

        $registration = $this->registerWithDocument($user, $training);

        $this->assertSame(
            SupervisoryDocumentStatus::Submitted,
            $registration->supervisory_document_status
        );
        $this->assertNull($registration->supervisory_document_reviewed_by);
        $this->assertNull($registration->supervisory_document_remarks);
    }

    public function test_a_document_above_the_band_is_not_required(): void
    {
        $training = $this->supervisoryTraining();
        $user = $this->participantIn(
            FieldOffice::where('code', 'lfoi')->firstOrFail(),
            'boss@example.com',
            'SG 22'
        );

        $this->actingAs($user)
            ->post("/trainings/{$training->id}/register", [
                'charge_to' => ChargeTo::Personal->value,
                'needs_certificate' => true,
            ])
            ->assertSessionHas('success');

        $this->assertNull(
            Registration::where('user_id', $user->getKey())->firstOrFail()->supervisory_document_status
        );
    }

    public function test_the_roster_surfaces_documents_awaiting_a_verdict(): void
    {
        $leyte = FieldOffice::where('code', 'lfoi')->firstOrFail();
        $training = $this->supervisoryTraining();

        $this->registerWithDocument(
            $this->participantIn($leyte, 'a@example.com'),
            $training
        );
        $this->registerWithDocument(
            $this->participantIn($leyte, 'b@example.com'),
            $training
        );

        $this->actingAs($this->hrd())
            ->get("/admin/trainings/{$training->id}/roster")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('summary.documents_to_review', 2)
                ->where('training.is_supervisory', true)
                ->has('registrations', 2)
                ->where('registrations.0.supervisory_document.status', 'submitted')
                ->where('registrations.0.supervisory_document.can_review', true)
            );
    }

    public function test_a_field_office_can_review_its_own_office_document(): void
    {
        $leyte = FieldOffice::where('code', 'lfoi')->firstOrFail();
        $training = $this->supervisoryTraining();
        $registration = $this->registerWithDocument(
            $this->participantIn($leyte, 'a@example.com'),
            $training
        );

        $this->actingAs($this->fieldOfficeStaff($leyte))
            ->post("/admin/registrations/{$registration->id}/supervisory-document", [
                'decision' => 'verified',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(
            SupervisoryDocumentStatus::Verified,
            $registration->fresh()->supervisory_document_status
        );
    }

    public function test_a_field_office_cannot_review_another_offices_document(): void
    {
        $leyte = FieldOffice::where('code', 'lfoi')->firstOrFail();
        $samar = FieldOffice::where('code', 'sfo')->firstOrFail();
        $training = $this->supervisoryTraining();
        $registration = $this->registerWithDocument(
            $this->participantIn($samar, 'samar@example.com'),
            $training
        );

        $this->actingAs($this->fieldOfficeStaff($leyte))
            ->post("/admin/registrations/{$registration->id}/supervisory-document", [
                'decision' => 'verified',
            ])
            ->assertNotFound();

        $this->assertSame(
            SupervisoryDocumentStatus::Submitted,
            $registration->fresh()->supervisory_document_status
        );
    }

    /**
     * The download route is reached by a direct URL, not only through the
     * roster review action above — so the same office guard has to apply
     * there too, or a field-office user could read another office's
     * designation order just by knowing the registration id.
     */
    public function test_a_field_office_cannot_download_another_offices_document(): void
    {
        $leyte = FieldOffice::where('code', 'lfoi')->firstOrFail();
        $samar = FieldOffice::where('code', 'sfo')->firstOrFail();
        $training = $this->supervisoryTraining();
        $registration = $this->registerWithDocument(
            $this->participantIn($samar, 'samar@example.com'),
            $training
        );

        $this->actingAs($this->fieldOfficeStaff($leyte))
            ->get("/registrations/{$registration->id}/supporting-document")
            ->assertNotFound();
    }

    public function test_a_rejection_demands_a_reason(): void
    {
        $leyte = FieldOffice::where('code', 'lfoi')->firstOrFail();
        $training = $this->supervisoryTraining();
        $registration = $this->registerWithDocument(
            $this->participantIn($leyte, 'a@example.com'),
            $training
        );

        $this->actingAs($this->hrd())
            ->post("/admin/registrations/{$registration->id}/supervisory-document", [
                'decision' => 'rejected',
            ])
            ->assertSessionHasErrors('remarks');

        $this->assertSame(
            SupervisoryDocumentStatus::Submitted,
            $registration->fresh()->supervisory_document_status
        );
    }

    public function test_a_rejection_lets_the_participant_resubmit(): void
    {
        Storage::fake('local');

        $leyte = FieldOffice::where('code', 'lfoi')->firstOrFail();
        $training = $this->supervisoryTraining();
        $user = $this->participantIn($leyte, 'a@example.com');
        $registration = $this->registerWithDocument($user, $training);

        $this->actingAs($this->hrd())
            ->post("/admin/registrations/{$registration->id}/supervisory-document", [
                'decision' => 'rejected',
                'remarks' => 'The memo does not name any staff under this person.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $rejected = $registration->fresh();
        $this->assertSame(SupervisoryDocumentStatus::Rejected, $rejected->supervisory_document_status);
        $this->assertNotNull($rejected->supervisory_document_remarks);

        // The participant sees the rejection and the review note on their own
        // registrations page, and the workflow still allows a replacement.
        $this->actingAs($user)
            ->get('/my/registrations')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('registrations.0.supervisory_document.status', 'rejected')
                ->where('registrations.0.supervisory_document.can_resubmit', true)
                ->where(
                    'registrations.0.supervisory_document.remarks',
                    'The memo does not name any staff under this person.'
                )
            );

        $this->actingAs($user)
            ->post("/my/registrations/{$registration->id}/supporting-document", [
                'supporting_document' => UploadedFile::fake()->create('memo-v2.pdf', 64, 'application/pdf'),
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $resubmitted = $registration->fresh();
        $this->assertSame(SupervisoryDocumentStatus::Submitted, $resubmitted->supervisory_document_status);
        $this->assertNull($resubmitted->supervisory_document_remarks);
        $this->assertNull($resubmitted->supervisory_document_reviewed_by);
        Storage::disk('local')->assertExists($resubmitted->supporting_document_path);
    }

    public function test_a_participant_cannot_resubmit_for_another_office_registration(): void
    {
        $leyte = FieldOffice::where('code', 'lfoi')->firstOrFail();
        $samar = FieldOffice::where('code', 'sfo')->firstOrFail();
        $training = $this->supervisoryTraining();
        $registration = $this->registerWithDocument(
            $this->participantIn($samar, 'samar@example.com'),
            $training
        );

        $this->actingAs($this->participantIn($leyte, 'other@example.com'))
            ->post("/my/registrations/{$registration->id}/supporting-document", [
                'supporting_document' => UploadedFile::fake()->create('memo.pdf', 64, 'application/pdf'),
            ])
            ->assertForbidden();
    }

    public function test_a_verified_document_cannot_be_reviewed_again(): void
    {
        $leyte = FieldOffice::where('code', 'lfoi')->firstOrFail();
        $training = $this->supervisoryTraining();
        $registration = $this->registerWithDocument(
            $this->participantIn($leyte, 'a@example.com'),
            $training
        );

        $this->actingAs($this->hrd())
            ->post("/admin/registrations/{$registration->id}/supervisory-document", [
                'decision' => 'verified',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        // A second verdict is refused by the service — the roster no longer
        // offers the action, so this is the guard against a double post.
        $this->actingAs($this->hrd())
            ->post("/admin/registrations/{$registration->id}/supervisory-document", [
                'decision' => 'verified',
            ])
            ->assertSessionHasErrors('registration');

        $this->assertSame(
            SupervisoryDocumentStatus::Verified,
            $registration->fresh()->supervisory_document_status
        );
    }

    public function test_an_approved_registration_writes_the_deposit_details_to_the_notification(): void
    {
        $training = Training::factory()->create([
            'is_supervisory' => true,
            'payment_required' => true,
            'payment_amount' => 1500,
        ]);
        $user = $this->participantIn(
            FieldOffice::where('code', 'lfoi')->firstOrFail(),
            'betsy@example.com'
        );
        $registration = $this->registerWithDocument($user, $training);

        RegistrationService::review(
            $registration,
            RegistrationStatus::Approved,
            $this->hrd()
        );

        $notification = new RegistrationReviewed($registration->fresh());

        $this->assertStringContainsString('Land Bank of the Philippines', $notification->body($user));
        $this->assertStringContainsString('Account Number:', $notification->body($user));
        $this->assertStringContainsString('1,500.00', $notification->body($user));
    }

    public function test_the_approval_notification_adds_no_deposit_details_when_fee_is_settled(): void
    {
        $training = Training::factory()->create([
            'is_supervisory' => true,
            'payment_required' => true,
            'payment_amount' => 1500,
        ]);
        $user = $this->participantIn(
            FieldOffice::where('code', 'lfoi')->firstOrFail(),
            'betsy@example.com'
        );
        $registration = $this->registerWithDocument($user, $training);

        Payment::factory()->verified()->create([
            'registration_id' => $registration->id,
            'amount' => 1500,
        ]);

        RegistrationService::review(
            $registration,
            RegistrationStatus::Approved,
            $this->hrd()
        );

        $notification = new RegistrationReviewed($registration->fresh());

        $this->assertStringNotContainsString('Account Number:', $notification->body($user));
    }
}
