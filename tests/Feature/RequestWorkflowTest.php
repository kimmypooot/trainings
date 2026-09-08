<?php

namespace Tests\Feature;

use App\Enums\RegistrationStatus;
use App\Enums\RequestStatus;
use App\Enums\Role;
use App\Models\CancellationRequest;
use App\Models\Profile;
use App\Models\Registration;
use App\Models\RegistrationOutput;
use App\Models\Training;
use App\Models\User;
use App\Notifications\CancellationReviewed;
use App\Support\CancellationRequestService;
use App\Support\RegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The request queues ported from v1: withdrawals and post-training outputs.
 */
class RequestWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function staff(Role $role = Role::Admin): User
    {
        return User::factory()->create(['role' => $role, 'profile_completed_at' => now()]);
    }

    private function participant(): User
    {
        $user = User::factory()->create(['profile_completed_at' => now()]);
        Profile::factory()->for($user)->create();

        return $user->refresh();
    }

    // --- Cancellation requests -------------------------------------------

    public function test_withdrawing_creates_a_request_and_holds_the_slot(): void
    {
        $participant = $this->participant();
        $training = Training::factory()->paid()->create(['capacity' => 1]);
        $registration = RegistrationService::register($participant, $training);

        $this->actingAs($participant)
            ->delete("/my/registrations/{$registration->id}", [
                'reason' => 'Assigned to field work that week.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(1, CancellationRequest::count());

        // Crucially the slot is NOT freed yet — the training is still full.
        $this->assertSame(RegistrationStatus::Pending, $registration->fresh()->status);
        $this->assertTrue($training->fresh()->isFull());
    }

    public function test_a_withdrawal_needs_a_reason(): void
    {
        $participant = $this->participant();
        $registration = RegistrationService::register($participant, Training::factory()->paid()->create());

        $this->actingAs($participant)
            ->from('/my/registrations')
            ->delete("/my/registrations/{$registration->id}", ['reason' => ''])
            ->assertSessionHasErrors('reason');

        $this->assertSame(0, CancellationRequest::count());
    }

    public function test_a_participant_cannot_withdraw_someone_elses_registration(): void
    {
        $registration = RegistrationService::register($this->participant(), Training::factory()->paid()->create());

        $this->actingAs($this->participant())
            ->delete("/my/registrations/{$registration->id}", ['reason' => 'Not mine to cancel.'])
            ->assertForbidden();
    }

    public function test_a_second_open_withdrawal_is_refused(): void
    {
        $registration = RegistrationService::register($this->participant(), Training::factory()->paid()->create());

        CancellationRequestService::open($registration, 'First reason.');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('already have a withdrawal request');

        CancellationRequestService::open($registration->fresh(), 'Second reason.');
    }

    public function test_approving_a_withdrawal_frees_the_slot(): void
    {
        $participant = $this->participant();
        $training = Training::factory()->paid()->create(['capacity' => 1]);
        $registration = RegistrationService::register($participant, $training);
        $request = CancellationRequestService::open($registration, 'Conflicting assignment.');

        $this->actingAs($this->staff())
            ->post("/admin/requests/cancellations/{$request->id}", ['decision' => 'approved'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(RegistrationStatus::Cancelled, $registration->fresh()->status);
        $this->assertFalse($training->fresh()->isFull());
    }

    public function test_declining_a_withdrawal_keeps_the_registration(): void
    {
        $registration = RegistrationService::register($this->participant(), Training::factory()->paid()->create());
        $request = CancellationRequestService::open($registration, 'Changed my mind.');

        $this->actingAs($this->staff())
            ->post("/admin/requests/cancellations/{$request->id}", [
                'decision' => 'rejected',
                'remarks' => 'Slot already catered for.',
            ])
            ->assertRedirect();

        $this->assertSame(RegistrationStatus::Pending, $registration->fresh()->status);
        $this->assertSame(RequestStatus::Rejected, $request->fresh()->status);
    }

    public function test_a_withdrawal_cannot_be_reviewed_twice(): void
    {
        $registration = RegistrationService::register($this->participant(), Training::factory()->paid()->create());
        $request = CancellationRequestService::open($registration, 'A reason.');
        $staff = $this->staff();

        CancellationRequestService::review($request, RequestStatus::Approved, $staff);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('already been reviewed');

        CancellationRequestService::review($request->fresh(), RequestStatus::Rejected, $staff);
    }

    public function test_the_participant_is_notified_of_the_withdrawal_decision(): void
    {
        Notification::fake();

        $participant = $this->participant();
        $registration = RegistrationService::register($participant, Training::factory()->paid()->create());
        $request = CancellationRequestService::open($registration, 'A reason.');

        CancellationRequestService::review($request, RequestStatus::Approved, $this->staff());

        Notification::assertSentTo($participant, CancellationReviewed::class);
    }

    // --- Output submission ------------------------------------------------

    /**
     * The submission form has to be reachable, not merely routed.
     *
     * HRD's review queue could receive outputs from the day of the rewrite, but
     * nothing on the participant side offered a way to send one — so the queue
     * was permanently empty. These assert the page carries what the form needs.
     */
    public function test_a_supervisory_registration_offers_output_submission(): void
    {
        $participant = $this->participant();
        $registration = Registration::factory()->completed()->create([
            'user_id' => $participant->getKey(),
            'training_id' => Training::factory()->paid()->create([
                'is_supervisory' => true,
                'starts_at' => now()->subWeek(),
            ])->getKey(),
        ]);

        $this->actingAs($participant)
            ->get('/my/registrations')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('registrations.0.id', $registration->getKey())
                ->has('registrations.0.output_submission')
                ->has('registrations.0.output_submission.submitted', 0)
            );
    }

    public function test_an_ordinary_training_is_not_asked_for_an_output(): void
    {
        $participant = $this->participant();
        Registration::factory()->completed()->create([
            'user_id' => $participant->getKey(),
            'training_id' => Training::factory()->paid()->create([
                'is_supervisory' => false,
                'starts_at' => now()->subWeek(),
            ])->getKey(),
        ]);

        $this->actingAs($participant)
            ->get('/my/registrations')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('registrations.0.output_submission', null)
            );
    }

    public function test_an_output_is_not_asked_for_before_the_training_starts(): void
    {
        $participant = $this->participant();
        Registration::factory()->approved()->create([
            'user_id' => $participant->getKey(),
            'training_id' => Training::factory()->paid()->create([
                'is_supervisory' => true,
                'starts_at' => now()->addWeek(),
            ])->getKey(),
        ]);

        // There is nothing to write up yet.
        $this->actingAs($participant)
            ->get('/my/registrations')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('registrations.0.output_submission', null)
            );
    }

    public function test_a_submitted_output_and_its_verdict_come_back_to_the_participant(): void
    {
        Storage::fake('local');

        $participant = $this->participant();
        $registration = Registration::factory()->completed()->create([
            'user_id' => $participant->getKey(),
            'training_id' => Training::factory()->paid()->create([
                'is_supervisory' => true,
                'starts_at' => now()->subWeek(),
            ])->getKey(),
        ]);

        $this->actingAs($participant)
            ->post("/my/registrations/{$registration->id}/outputs", [
                'title' => 'Re-entry action plan',
                'file' => UploadedFile::fake()->create('plan.pdf', 120, 'application/pdf'),
            ])
            ->assertRedirect();

        RegistrationOutput::sole()->forceFill([
            'status' => RequestStatus::Rejected,
            'review_remarks' => 'Please expand section 3.',
        ])->save();

        // The reviewer's note is the whole point of showing a rejection back.
        $this->actingAs($participant)
            ->get('/my/registrations')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('registrations.0.output_submission.submitted', 1)
                ->where('registrations.0.output_submission.submitted.0.title', 'Re-entry action plan')
                ->where('registrations.0.output_submission.submitted.0.status', 'rejected')
                ->where('registrations.0.output_submission.submitted.0.remarks', 'Please expand section 3.')
            );
    }

    public function test_a_participant_can_submit_an_output(): void
    {
        Storage::fake('local');

        $participant = $this->participant();
        $registration = Registration::factory()->completed()->create([
            'user_id' => $participant->getKey(),
            'training_id' => Training::factory()->paid()->create()->getKey(),
        ]);

        $this->actingAs($participant)
            ->post("/my/registrations/{$registration->id}/outputs", [
                'title' => 'Re-entry action plan',
                'file' => UploadedFile::fake()->create('plan.pdf', 120, 'application/pdf'),
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $output = RegistrationOutput::sole();

        $this->assertSame('plan.pdf', $output->original_filename);
        Storage::disk('local')->assertExists($output->file_path);
    }

    public function test_a_dangerous_file_type_is_refused(): void
    {
        Storage::fake('local');

        $participant = $this->participant();
        $registration = Registration::factory()->completed()->create([
            'user_id' => $participant->getKey(),
            'training_id' => Training::factory()->paid()->create()->getKey(),
        ]);

        $this->actingAs($participant)
            ->from('/my/registrations')
            ->post("/my/registrations/{$registration->id}/outputs", [
                'title' => 'Definitely a plan',
                'file' => UploadedFile::fake()->create('shell.php', 10, 'application/x-php'),
            ])
            ->assertSessionHasErrors('file');

        $this->assertSame(0, RegistrationOutput::count());
    }

    public function test_a_participant_cannot_submit_against_someone_elses_registration(): void
    {
        Storage::fake('local');

        $registration = Registration::factory()->completed()->create([
            'user_id' => $this->participant()->getKey(),
            'training_id' => Training::factory()->paid()->create()->getKey(),
        ]);

        $this->actingAs($this->participant())
            ->post("/my/registrations/{$registration->id}/outputs", [
                'title' => 'Not mine',
                'file' => UploadedFile::fake()->create('plan.pdf', 10, 'application/pdf'),
            ])
            ->assertForbidden();
    }

    public function test_an_output_download_is_limited_to_its_owner_and_staff(): void
    {
        Storage::fake('local');

        $owner = $this->participant();
        $registration = Registration::factory()->completed()->create([
            'user_id' => $owner->getKey(),
            'training_id' => Training::factory()->paid()->create()->getKey(),
        ]);

        $this->actingAs($owner)->post("/my/registrations/{$registration->id}/outputs", [
            'title' => 'Plan',
            'file' => UploadedFile::fake()->create('plan.pdf', 10, 'application/pdf'),
        ]);

        $output = RegistrationOutput::sole();

        $this->actingAs($owner)->get("/outputs/{$output->id}/download")->assertOk();
        $this->actingAs($this->staff())->get("/outputs/{$output->id}/download")->assertOk();
        $this->actingAs($this->participant())->get("/outputs/{$output->id}/download")->assertForbidden();
    }

    // --- Queue screen -----------------------------------------------------

    public function test_the_staff_queue_lists_both_request_types(): void
    {
        $participant = $this->participant();
        $registration = RegistrationService::register($participant, Training::factory()->paid()->create());

        CancellationRequestService::open($registration, 'A reason.');

        $this->actingAs($this->staff())
            ->get('/admin/requests')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Requests/Index')
                ->has('cancellations', 1)
                ->has('outputs', 0)
            );
    }

    public function test_participants_cannot_reach_the_staff_queue(): void
    {
        $this->actingAs($this->participant())->get('/admin/requests')->assertForbidden();
    }
}
