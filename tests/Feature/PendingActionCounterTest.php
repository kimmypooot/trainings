<?php

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Enums\Role;
use App\Models\FieldOffice;
use App\Models\Payment;
use App\Models\Profile;
use App\Models\RefundRequest;
use App\Models\Registration;
use App\Models\RegistrationOutput;
use App\Models\Training;
use App\Models\TrainingRequest;
use App\Models\User;
use App\Support\CancellationRequestService;
use App\Support\PendingActionCounter;
use App\Support\RefundService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The sidebar pending-action badges.
 *
 * The counts must be the same rule the queue screens apply — a badge that
 * advertises work a role cannot even open, or that another office owns, is a
 * badge that lies.
 */
class PendingActionCounterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    private function staff(Role $role = Role::Admin, ?FieldOffice $office = null): User
    {
        return User::factory()->create([
            'role' => $role,
            'profile_completed_at' => now(),
            'field_office_id' => $office?->getKey(),
        ]);
    }

    private function participant(?FieldOffice $office = null): User
    {
        $user = User::factory()->create(['profile_completed_at' => now()]);
        Profile::factory()->for($user)->create($office ? ['field_office_id' => $office->getKey()] : []);

        return $user->refresh();
    }

    private function paidRegistration(User $participant): Registration
    {
        return Registration::factory()->approved()->create([
            'user_id' => $participant->getKey(),
            'training_id' => Training::factory()->create([
                'payment_required' => true,
                'payment_amount' => 1500,
            ])->getKey(),
        ]);
    }

    private function claim(Payment $payment): RefundRequest
    {
        return RefundService::request($payment, 'The training was cancelled.', [
            'account_name' => 'Juan Dela Cruz',
            'bank_name' => 'Land Bank of the Philippines',
            'account_number' => '1234567890',
        ]);
    }

    // --- Participant side -------------------------------------------------

    public function test_a_participant_without_pending_work_has_no_badge(): void
    {
        $participant = $this->participant();

        $this->assertSame(0, PendingActionCounter::for($participant)['payments']);
    }

    public function test_a_pending_payment_proof_counts_for_the_participant(): void
    {
        $participant = $this->participant();
        Payment::factory()->create(['user_id' => $participant->getKey(), 'status' => PaymentStatus::Pending]);

        $this->assertSame(1, PendingActionCounter::for($participant)['payments']);
    }

    public function test_a_settled_payment_is_not_pending_work_for_the_participant(): void
    {
        $participant = $this->participant();
        Payment::factory()->verified()->create(['user_id' => $participant->getKey()]);

        $this->assertSame(0, PendingActionCounter::for($participant)['payments']);
    }

    public function test_a_fee_still_owed_on_an_active_slot_counts_for_the_participant(): void
    {
        $participant = $this->participant();
        $this->paidRegistration($participant);

        $this->assertSame(1, PendingActionCounter::for($participant)['payments']);
    }

    // --- Payments queue ---------------------------------------------------

    public function test_a_collecting_officer_counts_pending_payments_and_open_refunds(): void
    {
        $officer = $this->staff(Role::CollectingOfficer);

        Payment::factory()->create();
        Payment::factory()->verified()->create();
        $this->claim(Payment::factory()->verified()->create());

        $counts = PendingActionCounter::for($officer);
        $this->assertSame(2, $counts['admin-payments']);
    }

    public function test_a_refund_that_has_left_the_pipeline_is_not_open_work(): void
    {
        $officer = $this->staff(Role::CollectingOfficer);
        $refund = $this->claim(Payment::factory()->verified()->create());

        while ($refund->status !== RefundStatus::Refunded && $refund->status->next() !== null) {
            $refund = RefundService::advance($refund, $refund->status->next(), $officer);
        }

        $this->assertSame(0, PendingActionCounter::for($officer)['admin-payments']);
    }

    // --- Requests queue ---------------------------------------------------

    public function test_an_admin_counts_every_pending_queue(): void
    {
        $admin = $this->staff();
        $participant = $this->participant();

        CancellationRequestService::open(
            Registration::factory()->create(['user_id' => $participant->getKey()]),
            'Assigned to field work that week.',
        );
        RegistrationOutput::create([
            'registration_id' => Registration::factory()->create(['user_id' => $participant->getKey()])->getKey(),
            'title' => 'Reflection paper',
            'description' => 'What I learned.',
            'file_path' => 'outputs/paper.pdf',
            'original_filename' => 'paper.pdf',
            'file_size' => 2048,
            'mime_type' => 'application/pdf',
        ]);
        TrainingRequest::factory()->create(['requested_by' => $participant->getKey()]);

        $this->assertSame(3, PendingActionCounter::for($admin)['admin-requests']);
    }

    public function test_resolved_requests_are_not_pending(): void
    {
        $admin = $this->staff();
        $participant = $this->participant();

        TrainingRequest::factory()->approved()->create(['requested_by' => $participant->getKey()]);

        $this->assertSame(0, PendingActionCounter::for($admin)['admin-requests']);
    }

    public function test_a_field_office_sees_only_its_own_offices_pending_work(): void
    {
        $mine = FieldOffice::factory()->create();
        $theirs = FieldOffice::factory()->create();
        $officer = $this->staff(Role::FieldOffice, $mine);

        TrainingRequest::factory()->create(['requested_by' => $this->participant($mine)->getKey()]);
        TrainingRequest::factory()->create(['requested_by' => $this->participant($theirs)->getKey()]);

        $this->assertSame(1, PendingActionCounter::for($officer)['admin-requests']);
    }

    public function test_an_admin_is_not_scoped_to_a_field_office(): void
    {
        $office = FieldOffice::factory()->create();
        $admin = $this->staff(Role::Admin);

        TrainingRequest::factory()->create(['requested_by' => $this->participant($office)->getKey()]);
        TrainingRequest::factory()->create(['requested_by' => $this->participant()->getKey()]);

        $this->assertSame(2, PendingActionCounter::for($admin)['admin-requests']);
    }
}
