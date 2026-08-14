<?php

namespace Tests\Feature;

use App\Enums\RegistrationStatus;
use App\Enums\Role;
use App\Models\EmailTemplate;
use App\Models\Profile;
use App\Models\Registration;
use App\Models\Training;
use App\Models\User;
use App\Notifications\StaffAnnouncement;
use App\Support\AnnouncementAudience;
use App\Support\EmailTemplateRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Email templates, placeholder substitution, and audience targeting — ported
 * from v1's `send-emails.php` and its supporting API endpoints.
 */
class EmailTemplateTest extends TestCase
{
    use RefreshDatabase;

    private function staff(Role $role = Role::Admin): User
    {
        return User::factory()->create(['role' => $role, 'profile_completed_at' => now()]);
    }

    private function participant(array $profile = []): User
    {
        $user = User::factory()->create(['profile_completed_at' => now()]);
        Profile::factory()->for($user)->create($profile);

        return $user->refresh();
    }

    private function registrationFor(User $user, ?Training $training = null): Registration
    {
        return Registration::factory()->approved()->create([
            'user_id' => $user->getKey(),
            'training_id' => ($training ?? Training::factory()->create())->getKey(),
        ]);
    }

    // --- Placeholder substitution -----------------------------------------

    public function test_placeholders_are_filled_per_recipient(): void
    {
        $user = $this->participant(['first_name' => 'Maria', 'last_name' => 'Santos']);
        $training = Training::factory()->create([
            'title' => 'Records Management',
            'venue' => 'CSC Regional Office VIII',
        ]);

        $rendered = EmailTemplateRenderer::render(
            'Dear {first_name}, {training_title} runs at {venue}. Status: {registration_status}.',
            $this->registrationFor($user, $training),
        );

        $this->assertStringContainsString('Dear Maria', $rendered);
        $this->assertStringContainsString('Records Management', $rendered);
        $this->assertStringContainsString('CSC Regional Office VIII', $rendered);
        $this->assertStringNotContainsString('{', $rendered);
    }

    /**
     * A visible `{typo}` in a draft is something the sender can see and fix.
     * Blanking it produces a sentence with a hole in it that nobody notices
     * until after it has gone out.
     */
    public function test_an_unknown_placeholder_is_left_alone_rather_than_blanked(): void
    {
        $rendered = EmailTemplateRenderer::render(
            'See you at {venue_details}.',
            $this->registrationFor($this->participant()),
        );

        $this->assertStringContainsString('{venue_details}', $rendered);
    }

    /**
     * The compose screen's placeholder buttons and the substitution must come
     * from one list. In v1 they were two, and tokens the sender never replaced
     * were offered in the UI and mailed out literally.
     */
    public function test_every_offered_placeholder_is_actually_substituted(): void
    {
        $registration = $this->registrationFor($this->participant());

        $all = implode(' ', array_column(EmailTemplateRenderer::variableOptions(), 'token'));

        $this->assertStringNotContainsString('{', EmailTemplateRenderer::render($all, $registration));
    }

    // --- Audience ----------------------------------------------------------

    public function test_the_audience_narrows_by_sector_and_region(): void
    {
        $training = Training::factory()->create();

        $this->registrationFor($this->participant(['sector' => 'Local Government Units', 'region' => 'Region VIII']), $training);
        $this->registrationFor($this->participant(['sector' => 'Water Districts', 'region' => 'Region VIII']), $training);
        $this->registrationFor($this->participant(['sector' => 'Local Government Units', 'region' => 'Region VII']), $training);

        $filters = ['training_id' => $training->getKey(), 'statuses' => [RegistrationStatus::Approved->value]];

        $this->assertSame(3, AnnouncementAudience::count($filters));
        $this->assertSame(1, AnnouncementAudience::count([
            ...$filters,
            'sectors' => ['Local Government Units'],
            'regions' => ['Region VIII'],
        ]));
        $this->assertSame(2, AnnouncementAudience::count([...$filters, 'regions' => ['Region VIII']]));
    }

    /**
     * The count on screen has to be the number of emails that go out. Someone
     * registered for two selected trainings is one recipient in both.
     */
    public function test_a_participant_on_two_trainings_counts_and_receives_once(): void
    {
        Notification::fake();

        $participant = $this->participant();
        $this->registrationFor($participant);
        $this->registrationFor($participant);

        $filters = ['statuses' => [RegistrationStatus::Approved->value]];

        $this->assertSame(1, AnnouncementAudience::count($filters));

        $this->actingAs($this->staff())
            ->post('/admin/emails', [
                'subject' => 'A notice',
                'message' => 'Something worth knowing.',
                'statuses' => [RegistrationStatus::Approved->value],
            ])
            ->assertSessionHas('success');

        Notification::assertSentToTimes($participant, StaffAnnouncement::class, 1);
    }

    /**
     * "Queued for delivery" against nobody reads as success, and the sender
     * walks away believing the announcement went out.
     */
    public function test_a_send_that_would_reach_nobody_is_refused(): void
    {
        Notification::fake();

        $this->actingAs($this->staff())
            ->from('/admin/emails')
            ->post('/admin/emails', [
                'training_id' => Training::factory()->create()->id,
                'subject' => 'A notice',
                'message' => 'Something worth knowing.',
                'statuses' => [RegistrationStatus::Approved->value],
            ])
            ->assertSessionHasErrors('audience');

        Notification::assertNothingSent();
    }

    public function test_the_announcement_body_is_personalised_on_send(): void
    {
        Notification::fake();

        $participant = $this->participant(['first_name' => 'Pedro']);
        $this->registrationFor($participant);

        $this->actingAs($this->staff())
            ->post('/admin/emails', [
                'subject' => 'Reminder for {first_name}',
                'message' => 'Hello {first_name}, see you soon.',
                'statuses' => [RegistrationStatus::Approved->value],
            ])
            ->assertSessionHas('success');

        Notification::assertSentTo(
            $participant,
            StaffAnnouncement::class,
            fn ($notification) => str_contains($notification->title($participant), 'Pedro')
        );
    }

    /**
     * The preview renders each sample the same way the send will, so what the
     * sender reads before pressing the button is what the recipient gets.
     */
    public function test_the_preview_reports_the_count_and_a_rendered_sample(): void
    {
        $this->registrationFor($this->participant(['first_name' => 'Ana']));

        $filters = ['statuses' => [RegistrationStatus::Approved->value]];

        $this->assertSame(1, AnnouncementAudience::count($filters));

        $samples = AnnouncementAudience::preview(
            $filters,
            'Hello {first_name}',
            'Body for {first_name}.',
        );

        $this->assertCount(1, $samples);
        $this->assertSame('Hello Ana', $samples[0]['subject']);
        $this->assertSame('Body for Ana.', $samples[0]['body']);
    }

    /** The compose screen gets the vocabularies it needs to offer filters. */
    public function test_the_compose_screen_offers_the_filters_and_placeholders(): void
    {
        $this->registrationFor($this->participant(['sector' => 'Water Districts', 'region' => 'Region VIII']));

        $this->actingAs($this->staff())
            ->get('/admin/emails')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Emails/Index')
                ->has('variables')
                ->where('audienceFilters.sectors.0.value', 'Water Districts')
                ->where('audienceFilters.regions.0.value', 'Region VIII')
                // Computed only on the partial reload that asks for it, so an
                // ordinary page load does not pay for a count nobody wanted.
                ->missing('audiencePreview')
            );
    }

    // --- Templates ---------------------------------------------------------

    public function test_hrd_can_save_and_delete_a_template(): void
    {
        $staff = $this->staff();

        $this->actingAs($staff)
            ->post('/admin/emails/templates', [
                'name' => 'Payment reminder',
                'subject' => 'Fee for {training_title}',
                'body' => 'Dear {first_name}, PHP {amount_due} is due.',
                'category' => 'payment',
            ])
            ->assertSessionHas('success');

        $template = EmailTemplate::sole();

        $this->assertSame('Payment reminder', $template->name);
        $this->assertSame($staff->getKey(), $template->created_by);

        $this->actingAs($staff)
            ->delete("/admin/emails/templates/{$template->id}")
            ->assertSessionHas('success');

        $this->assertSame(0, EmailTemplate::count());
    }

    /**
     * System templates back features that look them up by code, so deleting one
     * would break a send that has nothing to do with this screen.
     */
    public function test_a_system_template_cannot_be_deleted(): void
    {
        $template = EmailTemplate::create([
            'name' => 'Certificate released',
            'code' => 'certificate.released',
            'subject' => 'Your certificate',
            'body' => 'It is ready.',
            'category' => 'certificate',
            'is_system' => true,
        ]);

        $this->actingAs($this->staff())
            ->delete("/admin/emails/templates/{$template->id}")
            ->assertForbidden();

        $this->assertSame(1, EmailTemplate::count());
    }

    public function test_a_template_needs_a_known_category(): void
    {
        $this->actingAs($this->staff())
            ->from('/admin/emails')
            ->post('/admin/emails/templates', [
                'name' => 'Something',
                'subject' => 'Subject',
                'body' => 'Body',
                'category' => 'not-a-category',
            ])
            ->assertSessionHasErrors('category');
    }

    // --- Test send ---------------------------------------------------------

    public function test_a_test_send_goes_only_to_the_sender(): void
    {
        Notification::fake();

        $participant = $this->participant(['first_name' => 'Rosa']);
        $this->registrationFor($participant);
        $staff = $this->staff();

        $this->actingAs($staff)
            ->post('/admin/emails/test', [
                'subject' => 'Hello {first_name}',
                'message' => 'Body.',
                'statuses' => [RegistrationStatus::Approved->value],
            ])
            ->assertSessionHas('success');

        // Rendered against a real match, so the test shows filled placeholders
        // rather than the raw tokens.
        Notification::assertSentTo(
            $staff,
            StaffAnnouncement::class,
            fn ($notification) => str_contains($notification->title($staff), '[TEST] Hello Rosa')
        );
        Notification::assertNotSentTo($participant, StaffAnnouncement::class);
    }
}
