<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Enums\RegistrationStatus;
use App\Enums\Role;
use App\Enums\TrainingStatus;
use App\Models\FieldOffice;
use App\Models\Payment;
use App\Models\Profile;
use App\Models\Registration;
use App\Models\Training;
use App\Models\User;
use App\Notifications\RegistrationTransferred;
use App\Support\RegistrationService;
use App\Support\RescheduleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Rescheduling: publishing a replacement run and finding who it stranded.
 *
 * The assertions that matter most here are the ones about money. A participant
 * whose fee state is reported wrongly is one whose payment ends up attached to
 * a training that never ran, and the promissory-note case is the one worth
 * guarding hardest — it is verified, so almost every "has this been paid"
 * check in the app says yes, and it is nonetheless money the office has not
 * received.
 */
class TrainingRescheduleTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => Role::Admin, 'profile_completed_at' => now()]);
    }

    private function participant(?FieldOffice $office = null): User
    {
        $user = User::factory()->create(['profile_completed_at' => now()]);
        Profile::factory()->for($user)->create(
            $office === null ? [] : ['field_office_id' => $office->getKey()]
        );

        return $user->refresh();
    }

    private function registerOn(Training $training, ?FieldOffice $office = null): Registration
    {
        return RegistrationService::register($this->participant($office), $training);
    }

    /** A verified payment of the given method, which is what "settled" means. */
    private function settle(Registration $registration, PaymentMethod $method): Payment
    {
        return Payment::factory()->verified()->create([
            'registration_id' => $registration->getKey(),
            'user_id' => $registration->user_id,
            'training_id' => $registration->training_id,
            'payment_method' => $method,
            'amount' => 1500,
        ]);
    }

    public function test_the_affected_list_separates_paid_promissory_and_unpaid(): void
    {
        $training = Training::factory()->paid()->create();

        $paid = $this->registerOn($training);
        $promissory = $this->registerOn($training);
        $unpaid = $this->registerOn($training);

        $this->settle($paid, PaymentMethod::Cash);
        $this->settle($promissory, PaymentMethod::Promissory);

        $rows = RescheduleService::affected($training->refresh())->keyBy('id');

        $this->assertSame('paid', $rows[$paid->id]['fee_state']);
        $this->assertSame('promissory', $rows[$promissory->id]['fee_state']);
        $this->assertSame('unpaid', $rows[$unpaid->id]['fee_state']);
    }

    /**
     * The distinction the whole feature turns on. A promissory note is a
     * verified payment, so hasSettledFee() is true and the participant was let
     * into the room — but no money arrived, so hasClearedFee() is false.
     * Collapsing the two would report the office as holding cash it does not.
     */
    public function test_a_promissory_note_is_settled_but_not_cleared(): void
    {
        $training = Training::factory()->paid()->create();
        $registration = $this->registerOn($training);

        $this->settle($registration, PaymentMethod::Promissory);

        $registration = $registration->fresh(['training', 'payments']);

        $this->assertTrue($registration->hasSettledFee());
        $this->assertFalse($registration->hasClearedFee());

        $summary = RescheduleService::summarise(RescheduleService::affected($training->refresh()));

        $this->assertSame(1, $summary['promissory']);
        $this->assertSame(0, $summary['paid']);
        // Promised, not collected — the sum the office still has to chase.
        $this->assertSame(0.0, $summary['collected']);
        $this->assertSame(1500.0, $summary['promised']);
    }

    /** A free run has no money at stake, and must not report a room as paid. */
    public function test_a_free_training_reports_no_fee_rather_than_paid(): void
    {
        $training = Training::factory()->create(['payment_required' => false]);
        $this->registerOn($training);

        $rows = RescheduleService::affected($training->refresh());

        $this->assertSame('free', $rows->first()['fee_state']);
    }

    public function test_cancelled_and_rejected_registrations_are_not_affected(): void
    {
        $training = Training::factory()->paid()->create();

        $staying = $this->registerOn($training);
        $waitlisted = $this->registerOn($training);
        $cancelled = $this->registerOn($training);
        $rejected = $this->registerOn($training);

        $waitlisted->forceFill(['status' => RegistrationStatus::Waitlisted])->save();
        $cancelled->forceFill(['status' => RegistrationStatus::Cancelled])->save();
        $rejected->forceFill(['status' => RegistrationStatus::Rejected])->save();

        $ids = RescheduleService::affected($training->refresh())->pluck('id')->all();

        // Waitlisted people arranged leave around the old dates too.
        $this->assertEqualsCanonicalizing([$staying->id, $waitlisted->id], $ids);
    }

    /**
     * The guard on the whole screen being trustworthy: what the list predicts
     * has to be what the transfer does. If these two ever disagree, the list
     * shows a clean set of names, moves some of them, and reports the rest in a
     * flash message — which is how somebody stays on a run that will not
     * happen.
     */
    public function test_the_predicted_blockers_match_what_the_transfer_actually_does(): void
    {
        $training = Training::factory()->paid()->create();
        $target = Training::factory()->paid()->create(['capacity' => 2]);

        $registrations = collect(range(1, 5))->map(fn () => $this->registerOn($training));

        $predicted = RescheduleService::affected($training->refresh(), $target->refresh())
            ->keyBy('id');

        $expectedMovable = $predicted->where('movable', true)->keys()->all();

        // Only the target's two seats, and to the first two registered.
        $this->assertSame($registrations->take(2)->pluck('id')->all(), $expectedMovable);

        $result = RegistrationService::transfer(
            $registrations->pluck('id')->all(),
            $target->refresh(),
            $this->admin(),
            'The original run was called off for low turnout.',
        );

        $this->assertSame(2, $result['moved']);
        $this->assertCount(3, $result['skipped']);

        $actuallyMoved = Registration::where('training_id', $target->getKey())
            ->orderBy('registered_at')
            ->pluck('id')
            ->all();

        $this->assertSame($expectedMovable, $actuallyMoved);

        foreach ($predicted->where('movable', false) as $row) {
            $this->assertSame('target is full', $row['blocker']);
        }
    }

    /**
     * The denormalised copy on payments is kept in sync by exactly one line in
     * transfer(). A regression there does not fail loudly — it silently
     * attributes the money to the run that never happened.
     */
    public function test_transferring_carries_the_payment_to_the_new_run(): void
    {
        Notification::fake();

        $training = Training::factory()->paid()->create();
        $target = Training::factory()->paid()->create();

        $registration = $this->registerOn($training);
        $payment = $this->settle($registration, PaymentMethod::Promissory);

        RegistrationService::transfer(
            [$registration->id],
            $target,
            $this->admin(),
            'Moved to the rescheduled run.',
        );

        $this->assertSame($target->id, $registration->refresh()->training_id);
        $this->assertSame($target->id, $payment->refresh()->training_id);

        Notification::assertSentTo($registration->user, RegistrationTransferred::class);
    }

    public function test_a_reschedule_creates_a_new_run_linked_to_the_original(): void
    {
        $training = Training::factory()->paid()->create(['is_supervisory' => true]);

        $this->actingAs($this->admin())
            ->get("/admin/trainings/{$training->id}/reschedule")
            ->assertOk();

        $this->actingAs($this->admin())->post('/admin/trainings', [
            'title' => $training->title,
            'venue' => $training->venue,
            'starts_at' => now()->addMonth()->format('Y-m-d\TH:i'),
            'ends_at' => now()->addMonth()->addDay()->format('Y-m-d\TH:i'),
            'status' => TrainingStatus::Published->value,
            'payment_required' => true,
            'payment_amount' => 1500,
            'is_supervisory' => true,
            'rescheduled_from_training_id' => $training->id,
        ])->assertRedirect();

        $replacement = Training::where('rescheduled_from_training_id', $training->id)->first();

        $this->assertNotNull($replacement);
        // The original stands untouched — that is the point of the whole shape.
        $this->assertSame(1, Training::whereKey($training->id)->count());
        $this->assertSame($training->id, $replacement->rescheduledFrom->id);
    }

    /** Provenance, not a pointer to be re-aimed once the move has happened. */
    public function test_the_reschedule_link_cannot_be_repointed_by_an_edit(): void
    {
        $training = Training::factory()->paid()->create();
        $other = Training::factory()->create();

        $this->actingAs($this->admin())->put("/admin/trainings/{$training->id}", [
            'title' => $training->title,
            'venue' => $training->venue,
            'starts_at' => $training->starts_at->format('Y-m-d\TH:i'),
            'ends_at' => $training->ends_at->format('Y-m-d\TH:i'),
            'status' => $training->status->value,
            'payment_required' => true,
            'payment_amount' => 1500,
            'rescheduled_from_training_id' => $other->id,
        ])->assertRedirect();

        $this->assertNull($training->refresh()->rescheduled_from_training_id);
    }

    /** The affected screen defaults to the replacement already on record. */
    public function test_the_affected_screen_defaults_to_the_linked_replacement(): void
    {
        $training = Training::factory()->paid()->create();
        $replacement = Training::factory()->paid()->create([
            'rescheduled_from_training_id' => $training->getKey(),
        ]);

        $this->registerOn($training);

        $this->actingAs($this->admin())
            ->get("/admin/trainings/{$training->id}/affected")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Trainings/Affected')
                ->where('target.id', $replacement->id)
                ->where('summary.total', 1)
                ->where('affected.0.movable', true)
            );
    }

    public function test_a_field_office_user_sees_only_its_own_affected_participants(): void
    {
        $offices = FieldOffice::factory()->count(2)->create();
        $training = Training::factory()->paid()->create();

        $mine = $this->registerOn($training, $offices[0]);
        $this->registerOn($training, $offices[1]);

        $rows = RescheduleService::affected($training->refresh(), null, $offices[0]->getKey());

        $this->assertSame([$mine->id], $rows->pluck('id')->all());
    }

    public function test_the_affected_export_is_scoped_to_the_requesting_office(): void
    {
        $offices = FieldOffice::factory()->count(2)->create();
        $training = Training::factory()->paid()->create();

        $mine = $this->registerOn($training, $offices[0]);
        $theirs = $this->registerOn($training, $offices[1]);

        $staff = User::factory()->create([
            'role' => Role::FieldOffice,
            'field_office_id' => $offices[0]->getKey(),
            'profile_completed_at' => now(),
        ]);

        $response = $this->actingAs($staff)
            ->get("/admin/exports/trainings/{$training->id}/affected");

        $response->assertOk();

        $content = $response->streamedContent();

        $this->assertStringContainsString($mine->user->name, $content);
        $this->assertStringNotContainsString($theirs->user->name, $content);
    }

    public function test_only_hrd_may_open_the_reschedule_and_affected_screens(): void
    {
        $training = Training::factory()->paid()->create();

        foreach ([Role::Management, Role::FieldOffice] as $role) {
            $staff = User::factory()->create(['role' => $role, 'profile_completed_at' => now()]);

            $this->actingAs($staff)
                ->get("/admin/trainings/{$training->id}/reschedule")
                ->assertForbidden();

            $this->actingAs($staff)
                ->get("/admin/trainings/{$training->id}/affected")
                ->assertForbidden();
        }
    }
}
