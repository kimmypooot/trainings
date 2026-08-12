<?php

namespace Tests\Feature;

use App\Enums\RegistrationStatus;
use App\Enums\Role;
use App\Models\EmailLog;
use App\Models\Profile;
use App\Models\Registration;
use App\Models\Training;
use App\Models\User;
use App\Notifications\RegistrationReviewed;
use App\Notifications\StaffAnnouncement;
use App\Notifications\TrainingReminder;
use App\Support\RegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * v3 could display notifications but never created any. These cover the write
 * side: review decisions, reminders, HRD announcements, and the mail audit log.
 */
class NotificationDeliveryTest extends TestCase
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

    public function test_approving_a_registration_notifies_the_participant(): void
    {
        Notification::fake();

        $participant = $this->participant();
        $registration = RegistrationService::register($participant, Training::factory()->create());

        RegistrationService::review($registration, RegistrationStatus::Approved, $this->staff());

        Notification::assertSentTo(
            $participant,
            RegistrationReviewed::class,
            fn (RegistrationReviewed $notification) => str_contains($notification->title($participant), "You're confirmed")
        );
    }

    public function test_a_rejection_carries_the_reason_into_the_notification(): void
    {
        Notification::fake();

        $participant = $this->participant();
        $registration = RegistrationService::register($participant, Training::factory()->create());

        RegistrationService::review(
            $registration,
            RegistrationStatus::Rejected,
            $this->staff(),
            'Reserved for frontline staff this cycle.'
        );

        Notification::assertSentTo(
            $participant,
            RegistrationReviewed::class,
            fn (RegistrationReviewed $notification) => str_contains(
                $notification->body($participant),
                'Reserved for frontline staff this cycle.'
            )
        );
    }

    public function test_a_notification_lands_in_the_database_and_the_in_app_list(): void
    {
        $participant = $this->participant();
        $registration = RegistrationService::register($participant, Training::factory()->create());

        RegistrationService::review($registration, RegistrationStatus::Approved, $this->staff());

        $this->assertSame(1, $participant->notifications()->count());

        $this->actingAs($participant)
            ->get('/notifications')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Notifications/Index')
                ->has('notifications', 1)
                ->where('notifications.0.read', false)
            );
    }

    public function test_the_unread_badge_reflects_a_new_notification(): void
    {
        $participant = $this->participant();
        $registration = RegistrationService::register($participant, Training::factory()->create());

        RegistrationService::review($registration, RegistrationStatus::Approved, $this->staff());

        $this->actingAs($participant)
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page->where('unreadNotifications', 1));
    }

    public function test_sending_mail_writes_an_email_log_row(): void
    {
        $participant = $this->participant();
        $registration = RegistrationService::register($participant, Training::factory()->create());

        RegistrationService::review($registration, RegistrationStatus::Approved, $this->staff());

        $log = EmailLog::sole();

        $this->assertSame($participant->email, $log->recipient_email);
        $this->assertSame($participant->id, $log->user_id);
        $this->assertStringContainsString("You're confirmed", $log->subject);
        $this->assertNotNull($log->sent_at);
    }

    public function test_hrd_can_queue_an_announcement_to_confirmed_participants(): void
    {
        Notification::fake();

        $training = Training::factory()->create();
        $confirmed = $this->participant();
        $pending = $this->participant();

        Registration::factory()->approved()->create([
            'user_id' => $confirmed->getKey(),
            'training_id' => $training->getKey(),
        ]);
        Registration::factory()->create([
            'user_id' => $pending->getKey(),
            'training_id' => $training->getKey(),
        ]);

        $this->actingAs($this->staff())
            ->post('/admin/emails', [
                'training_id' => $training->id,
                'subject' => 'Venue change',
                'message' => 'We have moved to the Annex Building.',
                'statuses' => ['approved'],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        Notification::assertSentTo($confirmed, StaffAnnouncement::class);
        Notification::assertNotSentTo($pending, StaffAnnouncement::class);
    }

    public function test_an_announcement_requires_a_subject_and_an_audience(): void
    {
        $training = Training::factory()->create();

        $this->actingAs($this->staff())
            ->from('/admin/emails')
            ->post('/admin/emails', [
                'training_id' => $training->id,
                'subject' => '',
                'message' => '',
                'statuses' => [],
            ])
            ->assertSessionHasErrors(['subject', 'message', 'statuses']);
    }

    public function test_field_office_staff_cannot_reach_the_email_screen(): void
    {
        $this->actingAs($this->staff(Role::FieldOffice))
            ->get('/admin/emails')
            ->assertForbidden();
    }

    public function test_reminders_go_only_to_confirmed_participants(): void
    {
        Notification::fake();

        $training = Training::factory()->create(['starts_at' => now()->addDay()->setTime(9, 0)]);
        $confirmed = $this->participant();
        $waitlisted = $this->participant();

        Registration::factory()->approved()->create([
            'user_id' => $confirmed->getKey(),
            'training_id' => $training->getKey(),
        ]);
        Registration::factory()->create([
            'user_id' => $waitlisted->getKey(),
            'training_id' => $training->getKey(),
            'status' => RegistrationStatus::Waitlisted,
        ]);

        $this->artisan('tims:send-reminders --days=1')->assertSuccessful();

        Notification::assertSentTo($confirmed, TrainingReminder::class);
        Notification::assertNotSentTo($waitlisted, TrainingReminder::class);
    }

    public function test_reminders_skip_trainings_outside_the_window(): void
    {
        Notification::fake();

        $training = Training::factory()->create(['starts_at' => now()->addWeeks(2)]);
        $participant = $this->participant();

        Registration::factory()->approved()->create([
            'user_id' => $participant->getKey(),
            'training_id' => $training->getKey(),
        ]);

        $this->artisan('tims:send-reminders --days=1')->assertSuccessful();

        Notification::assertNothingSent();
    }
}
