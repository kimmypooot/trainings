<?php

namespace Tests\Feature;

use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

/**
 * Sign-ins, failures and lockouts are on the record.
 *
 * `users.last_login_at` used to be the whole authentication trail: one column,
 * overwritten every time. That answers "is this account dormant" and nothing
 * else. It cannot say how many failed attempts preceded a compromise, whether
 * an account was reached from an unusual address, or when somebody was locked
 * out — the three questions an incident actually asks.
 *
 * Kept in a log channel rather than `activity_logs` deliberately. v1 put login
 * rows in the activity log and the volume buried the administrative decisions
 * worth auditing, which is the reason LoginController stopped recording them at
 * all. Two kinds of record, two stores, two retention periods.
 */
class AuthenticationLogTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, array{event: string, context: array<string, mixed>}> */
    private array $written = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->written = [];

        Log::shouldReceive('channel')->with('auth')->andReturnSelf();
        Log::shouldReceive('info')->andReturnUsing(function ($event, $context = []) {
            $this->written[] = ['event' => $event, 'context' => $context];
        });
        // Anything else the application logs during a request is not this
        // test's business, and must not fail the expectation above.
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('warning', 'error', 'debug', 'notice')->andReturnNull();
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    private function participant(string $email = 'probe@example.test'): User
    {
        $user = User::factory()->create([
            'email' => $email,
            'password' => 'CorrectHorseBattery123',
            'profile_completed_at' => now(),
        ]);
        Profile::factory()->for($user)->create();

        return $user->refresh();
    }

    /** @return array<int, array<string, mixed>> */
    private function entriesFor(string $event): array
    {
        return array_values(array_map(
            fn (array $line) => $line['context'],
            array_filter($this->written, fn (array $line) => $line['event'] === $event),
        ));
    }

    public function test_a_successful_sign_in_is_recorded(): void
    {
        $user = $this->participant();

        $this->post('/login', [
            'email' => 'probe@example.test',
            'password' => 'CorrectHorseBattery123',
        ])->assertRedirect();

        $entries = $this->entriesFor('login');

        $this->assertCount(1, $entries, 'A sign-in was recorded once, or not at all.');
        $this->assertSame($user->getKey(), $entries[0]['user_id']);
        $this->assertSame('probe@example.test', $entries[0]['email']);
        $this->assertFalse($entries[0]['via_remember']);
    }

    /**
     * The distinction the log has to preserve: a person mistyping their own
     * password looks nothing like somebody walking a list of addresses, and
     * that difference is not recoverable afterwards.
     */
    public function test_a_failed_sign_in_records_whether_the_account_exists(): void
    {
        $this->participant();

        $this->post('/login', ['email' => 'probe@example.test', 'password' => 'wrong-one-1234']);
        $this->post('/login', ['email' => 'nobody@example.test', 'password' => 'wrong-one-1234']);

        $entries = $this->entriesFor('login.failed');

        $this->assertCount(2, $entries);
        $this->assertTrue($entries[0]['account_exists']);
        $this->assertFalse($entries[1]['account_exists']);
    }

    /**
     * The single most important property of this file. A failed attempt is very
     * often a real password typed against the wrong account, and
     * `Failed::$credentials` carries it — it is marked #[\SensitiveParameter]
     * for exactly that reason. Logging the array would put working credentials
     * in a file with a wider audience than the database has.
     */
    public function test_no_password_ever_reaches_the_log(): void
    {
        $this->participant();

        $this->post('/login', ['email' => 'probe@example.test', 'password' => 'wrong-one-1234']);
        $this->post('/login', ['email' => 'probe@example.test', 'password' => 'CorrectHorseBattery123']);

        $encoded = json_encode($this->written);

        $this->assertStringNotContainsString('wrong-one-1234', $encoded);
        $this->assertStringNotContainsString('CorrectHorseBattery123', $encoded);
        $this->assertStringNotContainsString('$2y$', $encoded, 'A password hash reached the log.');
    }

    public function test_signing_out_is_recorded(): void
    {
        $user = $this->participant();

        $this->actingAs($user)->post('/logout')->assertRedirect();

        $entries = $this->entriesFor('logout');

        $this->assertCount(1, $entries);
        $this->assertSame($user->getKey(), $entries[0]['user_id']);
    }

    /**
     * A lockout is the clearest signal in the file that somebody is being
     * attacked, so it is recorded distinctly rather than as a sixth failure.
     */
    public function test_a_lockout_is_recorded(): void
    {
        $this->participant();

        // The controller's limiter fires on the sixth attempt for one address.
        foreach (range(1, 8) as $ignored) {
            $this->post('/login', ['email' => 'probe@example.test', 'password' => 'wrong-one-1234']);
        }

        $this->assertNotEmpty(
            $this->entriesFor('login.lockout'),
            'Sign-in throttling engaged without leaving a record of it.'
        );
    }

    /**
     * Each event exactly once. Laravel discovers listeners by scanning
     * app/Listeners for `handle*` methods, so a `handleLogin()` here would be
     * registered by discovery *and* by the subscriber, and every sign-in would
     * be written twice — which is what happened before the methods were
     * renamed. A duplicated audit trail is not a cosmetic problem: it makes
     * "how many attempts were there" unanswerable.
     */
    public function test_each_event_is_recorded_exactly_once(): void
    {
        $this->participant();

        $this->post('/login', [
            'email' => 'probe@example.test',
            'password' => 'CorrectHorseBattery123',
        ]);

        $this->assertCount(1, $this->entriesFor('login'));
    }
}
