<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\RegistrationStatus;
use App\Enums\Role;
use App\Enums\TrainingMode;
use App\Models\Payment;
use App\Models\Profile;
use App\Models\Registration;
use App\Models\Training;
use App\Models\User;
use App\Notifications\PaymentReviewed;
use App\Notifications\RegistrationReviewed;
use App\Support\PaymentService;
use App\Support\RegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Who gets the join link for an online training.
 *
 * The rule the office set is that the link goes out only once the participant
 * has paid — for an online run the link *is* the training, so releasing it
 * early gives the thing away. Approval is the second gate: an unreviewed
 * registration is not yet a participant.
 *
 * These tests assert on the Inertia payload rather than the rendered page,
 * because the point is that the link never crosses the wire at all — hiding it
 * in the template would leave it sitting in the response body.
 */
class MeetingLinkTest extends TestCase
{
    use RefreshDatabase;

    private const LINK = 'https://meet.google.com/abc-defg-hij';

    private function participant(): User
    {
        $user = User::factory()->create(['profile_completed_at' => now()]);
        Profile::factory()->for($user)->create();

        return $user->refresh();
    }

    private function training(bool $paid = true): Training
    {
        return Training::factory()->create([
            'mode' => TrainingMode::Online,
            'meeting_link' => self::LINK,
            'payment_required' => $paid,
            'payment_amount' => $paid ? 1500 : null,
        ]);
    }

    /** @return array{0: string|null, 1: bool} the link as sent, and whether one exists */
    private function linkSeenBy(User $user, Training $training): array
    {
        $seen = [null, false];

        $this->actingAs($user)
            ->get("/trainings/{$training->slug}")
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use (&$seen) {
                $seen = [
                    $page->toArray()['props']['training']['meeting_link'],
                    $page->toArray()['props']['training']['has_meeting_link'],
                ];
            });

        return $seen;
    }

    public function test_an_unregistered_participant_is_told_a_link_exists_but_not_what_it_is(): void
    {
        [$link, $exists] = $this->linkSeenBy($this->participant(), $this->training());

        $this->assertNull($link);
        $this->assertTrue($exists);
    }

    public function test_an_approved_participant_who_has_not_paid_does_not_get_the_link(): void
    {
        $participant = $this->participant();
        $training = $this->training();

        Registration::factory()->approved()->create([
            'user_id' => $participant->getKey(),
            'training_id' => $training->getKey(),
        ]);

        [$link] = $this->linkSeenBy($participant, $training);

        $this->assertNull($link);
    }

    /** An uploaded proof is a claim, not a receipt. */
    public function test_a_payment_still_awaiting_verification_does_not_release_the_link(): void
    {
        $participant = $this->participant();
        $training = $this->training();

        $registration = Registration::factory()->approved()->create([
            'user_id' => $participant->getKey(),
            'training_id' => $training->getKey(),
        ]);

        Payment::factory()->create([
            'registration_id' => $registration->getKey(),
            'user_id' => $participant->getKey(),
            'training_id' => $training->getKey(),
            'status' => PaymentStatus::Pending,
        ]);

        [$link] = $this->linkSeenBy($participant, $training);

        $this->assertNull($link);
    }

    public function test_a_verified_payment_releases_the_link(): void
    {
        $participant = $this->participant();
        $training = $this->training();

        $registration = Registration::factory()->approved()->create([
            'user_id' => $participant->getKey(),
            'training_id' => $training->getKey(),
        ]);

        Payment::factory()->verified()->create([
            'registration_id' => $registration->getKey(),
            'user_id' => $participant->getKey(),
            'training_id' => $training->getKey(),
        ]);

        [$link] = $this->linkSeenBy($participant, $training);

        $this->assertSame(self::LINK, $link);
    }

    /**
     * Paying does not substitute for being approved.
     *
     * The likeliest way this breaks is someone reading the rule as "paid
     * means in" — but a pending registration has not been reviewed by HRD.
     */
    public function test_a_verified_payment_on_a_pending_registration_still_withholds_the_link(): void
    {
        $participant = $this->participant();
        $training = $this->training();

        $registration = Registration::factory()->create([
            'user_id' => $participant->getKey(),
            'training_id' => $training->getKey(),
        ]);

        Payment::factory()->verified()->create([
            'registration_id' => $registration->getKey(),
            'user_id' => $participant->getKey(),
            'training_id' => $training->getKey(),
        ]);

        [$link] = $this->linkSeenBy($participant, $training);

        $this->assertNull($link);
    }

    /**
     * A promissory note is treated as settlement for admission purposes.
     *
     * The office has agreed to let this person in on the strength of the note,
     * and for an online run the link is the only way in — withholding it would
     * make the note meaningless. The certificate is where the unpaid fee bites
     * instead; see CertificateTest.
     */
    public function test_a_verified_promissory_note_releases_the_link(): void
    {
        $participant = $this->participant();
        $training = $this->training();

        $registration = Registration::factory()->approved()->create([
            'user_id' => $participant->getKey(),
            'training_id' => $training->getKey(),
        ]);

        Payment::factory()->verified()->create([
            'registration_id' => $registration->getKey(),
            'user_id' => $participant->getKey(),
            'training_id' => $training->getKey(),
            'payment_method' => PaymentMethod::Promissory,
        ]);

        [$link] = $this->linkSeenBy($participant, $training);

        $this->assertSame(self::LINK, $link);
    }

    /** An unverified note is still just a piece of paper. */
    public function test_a_promissory_note_awaiting_verification_does_not_release_the_link(): void
    {
        $participant = $this->participant();
        $training = $this->training();

        $registration = Registration::factory()->approved()->create([
            'user_id' => $participant->getKey(),
            'training_id' => $training->getKey(),
        ]);

        Payment::factory()->create([
            'registration_id' => $registration->getKey(),
            'user_id' => $participant->getKey(),
            'training_id' => $training->getKey(),
            'payment_method' => PaymentMethod::Promissory,
            'status' => PaymentStatus::Pending,
        ]);

        [$link] = $this->linkSeenBy($participant, $training);

        $this->assertNull($link);
    }

    /** With nothing to pay, approval is the only gate left. */
    public function test_a_free_training_releases_the_link_on_approval(): void
    {
        $participant = $this->participant();
        $training = $this->training(paid: false);

        Registration::factory()->approved()->create([
            'user_id' => $participant->getKey(),
            'training_id' => $training->getKey(),
        ]);

        [$link] = $this->linkSeenBy($participant, $training);

        $this->assertSame(self::LINK, $link);
    }

    public function test_a_cancelled_registration_loses_the_link(): void
    {
        $participant = $this->participant();
        $training = $this->training();

        $registration = Registration::factory()->cancelled()->create([
            'user_id' => $participant->getKey(),
            'training_id' => $training->getKey(),
        ]);

        Payment::factory()->verified()->create([
            'registration_id' => $registration->getKey(),
            'user_id' => $participant->getKey(),
            'training_id' => $training->getKey(),
        ]);

        [$link] = $this->linkSeenBy($participant, $training);

        $this->assertNull($link);
    }

    // --- The link as it goes out by mail ---------------------------------

    public function test_verifying_the_payment_mails_the_link(): void
    {
        Notification::fake();

        $participant = $this->participant();
        $training = $this->training();

        $registration = Registration::factory()->approved()->create([
            'user_id' => $participant->getKey(),
            'training_id' => $training->getKey(),
        ]);

        $payment = Payment::factory()->create([
            'registration_id' => $registration->getKey(),
            'user_id' => $participant->getKey(),
            'training_id' => $training->getKey(),
        ]);

        PaymentService::verify($payment, User::factory()->create(['role' => Role::CollectingOfficer]));

        Notification::assertSentTo(
            $participant,
            fn (PaymentReviewed $notification) => str_contains($notification->body($participant), self::LINK)
        );
    }

    /**
     * The approval mail withholds the link while the fee is outstanding.
     *
     * Approval and payment can land in either order, so both notifications ask
     * the same question — and this is the order where approval comes first.
     */
    public function test_the_approval_mail_withholds_the_link_until_the_fee_is_paid(): void
    {
        Notification::fake();

        $participant = $this->participant();
        $training = $this->training();

        $registration = Registration::factory()->create([
            'user_id' => $participant->getKey(),
            'training_id' => $training->getKey(),
        ]);

        RegistrationService::review(
            $registration,
            RegistrationStatus::Approved,
            User::factory()->create(['role' => Role::Admin])
        );

        Notification::assertSentTo(
            $participant,
            fn (RegistrationReviewed $notification) => ! str_contains($notification->body($participant), self::LINK)
        );
    }

    /*
     * ── The registrations list ────────────────────────────────────────────
     *
     * My/Registrations opens a training in a dialog rather than sending the
     * participant to the detail page, so the link has to reach that payload on
     * exactly the same terms — and be withheld from it on the same terms. A
     * second surface is a second chance to leak it.
     */

    /** @return array{0: string|null, 1: bool} the link as sent, and whether one exists */
    private function linkInMyRegistrations(User $user): array
    {
        $seen = [null, false];

        $this->actingAs($user)
            ->get('/my/registrations')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use (&$seen) {
                $training = $page->toArray()['props']['registrations'][0]['training'];

                $seen = [$training['meeting_link'], $training['has_meeting_link']];
            });

        return $seen;
    }

    public function test_the_registrations_list_withholds_an_unearned_link(): void
    {
        $participant = $this->participant();
        $training = $this->training();

        Registration::factory()->approved()->create([
            'user_id' => $participant->getKey(),
            'training_id' => $training->getKey(),
        ]);

        [$link, $exists] = $this->linkInMyRegistrations($participant);

        // Approved but unpaid: told one exists, never what it is.
        $this->assertNull($link);
        $this->assertTrue($exists);
    }

    public function test_the_registrations_list_releases_an_earned_link(): void
    {
        $participant = $this->participant();
        $training = $this->training();

        $registration = Registration::factory()->approved()->create([
            'user_id' => $participant->getKey(),
            'training_id' => $training->getKey(),
        ]);

        Payment::factory()->create([
            'registration_id' => $registration->getKey(),
            'user_id' => $participant->getKey(),
            'training_id' => $training->getKey(),
            'amount' => 1500,
            'payment_method' => PaymentMethod::Online,
            'status' => PaymentStatus::Verified,
        ]);

        [$link] = $this->linkInMyRegistrations($participant);

        $this->assertSame(self::LINK, $link);
    }
}
