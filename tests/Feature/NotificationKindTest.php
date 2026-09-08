<?php

namespace Tests\Feature;

use App\Enums\RegistrationStatus;
use App\Enums\Role;
use App\Models\Profile;
use App\Models\Registration;
use App\Models\Training;
use App\Models\User;
use App\Notifications\ParticipantNotification;
use App\Notifications\RegistrationReviewed;
use App\Notifications\StaffAnnouncement;
use App\Support\RegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use ReflectionClass;
use Tests\TestCase;

/**
 * The notifications page draws each row with a glyph and a tone chosen by its
 * `kind` — see ParticipantNotification::kind() and resources/js/activityTone.ts.
 *
 * Both halves of that fail silently, which is why this exists. A notification
 * that forgets to declare a kind inherits the neutral default and is merely
 * undifferentiated; a notification that declares a kind the frontend has no
 * entry for renders an empty 40px square, because AppIcon draws nothing at all
 * for a name it does not hold. Neither is an error anywhere, and both show up
 * only on whichever kind of event happens to be rare.
 */
class NotificationKindTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every database-backed notification class in the application.
     *
     * Discovered from the directory rather than listed, which is the whole
     * point: a list would need updating by the same person who forgot to
     * declare the kind.
     *
     * @return array<int, class-string<ParticipantNotification>>
     */
    private function notificationClasses(): array
    {
        $classes = [];

        foreach (glob(app_path('Notifications/*.php')) ?: [] as $path) {
            $class = 'App\\Notifications\\'.basename($path, '.php');

            if (! class_exists($class) || ! is_subclass_of($class, ParticipantNotification::class)) {
                continue;
            }

            if ((new ReflectionClass($class))->isAbstract()) {
                continue;
            }

            $classes[] = $class;
        }

        return $classes;
    }

    /**
     * The kinds activityTone.ts holds an entry for.
     *
     * Read out of the module rather than restated here, so this cannot pass
     * against a list that has drifted from the file the browser loads. The
     * frontend is the only place these are defined; PHP just has to name one
     * of them.
     *
     * @return array<int, string>
     */
    private function drawableKinds(): array
    {
        $source = file_get_contents(resource_path('js/activityTone.ts'));

        // The keys of the `activityTones` object literal: a bare identifier at
        // the start of a line, four spaces in, followed by a colon.
        preg_match('/export const activityTones[^{]*\{(.*?)\n\};/s', $source, $body);

        $this->assertNotEmpty($body, 'Could not find the activityTones map in activityTone.ts.');

        preg_match_all('/^ {4}([a-z][a-z-]*):/m', $body[1], $keys);

        $this->assertNotEmpty($keys[1], 'Parsed the activityTones map but found no kinds in it.');

        return $keys[1];
    }

    /**
     * Every kind a class's kind() can return, read out of the method body.
     *
     * Not by instantiating and calling it, which was the first attempt and is
     * wrong twice over. These constructors take domain models, so an instance
     * needs a registration or a payment built for it — but more importantly,
     * two of these methods are a `match` over a status, and an instance only
     * ever exercises the one arm its fixture happens to land on. Reading the
     * source covers every arm, including the default nobody writes a fixture
     * for.
     *
     * It relies on the kinds being written as literals, which is the whole
     * convention: a kind computed at runtime could not be checked against the
     * frontend by anything, here or in CI.
     *
     * @param  class-string<ParticipantNotification>  $class
     * @return array<int, string>
     */
    private function kindsNamedBy(string $class): array
    {
        $method = (new ReflectionClass($class))->getMethod('kind');

        // An inherited kind() is the base default, already asserted below.
        if ($method->getDeclaringClass()->getName() !== $class) {
            return [];
        }

        $body = implode('', array_slice(
            file($method->getFileName()),
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        ));

        preg_match_all("/'([a-z][a-z-]*)'/", $body, $literals);

        $this->assertNotEmpty(
            $literals[1],
            "{$class}::kind() names no string literal, so nothing can check it against the frontend.",
        );

        return $literals[1];
    }

    public function test_every_notification_declares_a_kind_the_frontend_can_draw(): void
    {
        $drawable = $this->drawableKinds();
        $classes = $this->notificationClasses();

        // A guard on the guard: if the discovery above ever stops finding the
        // classes, every assertion below passes vacuously.
        $this->assertGreaterThan(10, count($classes));

        foreach ($classes as $class) {
            foreach ($this->kindsNamedBy($class) as $kind) {
                $this->assertContains(
                    $kind,
                    $drawable,
                    "{$class}::kind() can return '{$kind}', which activityTone.ts has no entry for — the notifications page would draw that row with an empty icon chip.",
                );
            }
        }
    }

    public function test_the_stored_payload_carries_the_kind(): void
    {
        $participant = User::factory()->create(['profile_completed_at' => now()]);
        Profile::factory()->for($participant)->create();

        $training = Training::factory()->create(['capacity' => 5]);
        $registration = RegistrationService::register($participant->refresh(), $training);

        $registration->update(['status' => RegistrationStatus::Approved]);
        $participant->notify(new RegistrationReviewed($registration->refresh()));

        $stored = $participant->notifications()->firstOrFail();

        // The outcome, not the event: an approval and a refusal are the two
        // things a participant most needs to tell apart in a list.
        $this->assertSame('approved', $stored->data['kind']);
    }

    public function test_the_notifications_page_sends_what_the_rows_need_to_be_drawn(): void
    {
        $participant = User::factory()->create(['profile_completed_at' => now()]);
        Profile::factory()->for($participant)->create();

        $training = Training::factory()->create(['capacity' => 5]);
        $registration = RegistrationService::register($participant->refresh(), $training);
        $registration->update(['status' => RegistrationStatus::Approved]);
        $participant->notify(new RegistrationReviewed($registration->refresh()));

        $this->actingAs($participant)
            ->get('/notifications')
            ->assertOk()
            ->assertInertia(
                fn ($page) => $page
                    ->component('Notifications/Index')
                    ->where('notifications.0.kind', 'approved')
                    // The day band the page groups by, and the relative label
                    // beside it — both from FeedMoment, which the dashboard's
                    // feed formats the same events with.
                    ->where('notifications.0.group', 'Today')
                    ->where('notifications.0.at_label', fn ($label) => is_string($label) && $label !== '')
                    // Counted over the table rather than the fifty rows shown,
                    // so it agrees with the bell in the shell.
                    ->where('unread', 1)
            );
    }

    /**
     * A row written before kinds existed carries none, and there is no
     * migration that could invent one — the payload is all the table keeps.
     * The page has to stay legible for those, which is what the neutral
     * fallback in activityTone.ts is for; this asserts the server hands the
     * frontend the null that triggers it rather than failing on the missing
     * key.
     */
    public function test_a_notification_stored_before_kinds_existed_still_renders(): void
    {
        $participant = User::factory()->create(['profile_completed_at' => now()]);
        Profile::factory()->for($participant)->create();

        $participant->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => 'App\\Notifications\\RegistrationReviewed',
            'data' => ['title' => 'An older notification', 'body' => 'No kind here.', 'url' => '/dashboard'],
            'read_at' => null,
        ]);

        $this->actingAs($participant)
            ->get('/notifications')
            ->assertOk()
            ->assertInertia(
                fn ($page) => $page
                    ->where('notifications.0.title', 'An older notification')
                    ->where('notifications.0.kind', null)
            );
    }

    public function test_staff_notifications_are_not_confused_for_participant_ones(): void
    {
        // StaffAnnouncement keeps the base default deliberately — it *is* the
        // announcement — so this pins the default rather than leaving the base
        // class free to change under thirteen subclasses.
        $this->assertSame('announcement', (new ReflectionClass(StaffAnnouncement::class))
            ->newInstanceWithoutConstructor()
            ->kind());

        $this->assertNotEmpty(Role::staff());
    }
}
