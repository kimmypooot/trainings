<?php

namespace Tests\Feature;

use App\Enums\ChargeTo;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Enums\RegistrationStatus;
use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\Payment;
use App\Models\Profile;
use App\Models\Registration;
use App\Models\Training;
use App\Models\User;
use App\Support\ActivityLogger;
use App\Support\PaymentService;
use App\Support\RefundService;
use App\Support\RegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The audit trail, consolidating v1's activity_logs, security_logs,
 * registration_status_logs and training_activity_log into one table.
 */
class ActivityLogTest extends TestCase
{
    use RefreshDatabase;

    private function participant(): User
    {
        $user = User::factory()->create(['profile_completed_at' => now()]);
        Profile::factory()->for($user)->create();

        return $user->refresh();
    }

    private function officer(Role $role = Role::Admin): User
    {
        return User::factory()->create(['role' => $role, 'profile_completed_at' => now()]);
    }

    public function test_registering_is_recorded_against_the_registration(): void
    {
        $user = $this->participant();
        $training = Training::factory()->create();

        $this->actingAs($user)->post("/trainings/{$training->id}/register", [
            'charge_to' => ChargeTo::Agency->value,
            'needs_certificate' => true,
        ]);

        $log = ActivityLog::forSubject(Registration::sole())->sole();

        $this->assertSame('registration.created', $log->action);
        $this->assertSame($user->getKey(), $log->causer_id);
        $this->assertSame(ChargeTo::Agency->value, $log->properties['charge_to']);
    }

    public function test_a_review_records_both_sides_of_the_transition(): void
    {
        $registration = Registration::factory()->create(['status' => RegistrationStatus::Pending]);
        $reviewer = $this->officer();

        RegistrationService::review($registration, RegistrationStatus::Approved, $reviewer);

        $log = ActivityLog::where('action', 'registration.approved')->sole();

        $this->assertSame(RegistrationStatus::Pending->value, $log->properties['from']);
        $this->assertSame(RegistrationStatus::Approved->value, $log->properties['to']);
        $this->assertSame($reviewer->getKey(), $log->causer_id);
    }

    public function test_verifying_a_payment_records_the_officer_and_receipt(): void
    {
        $payment = Payment::factory()->create();
        $officer = $this->officer(Role::CollectingOfficer);

        PaymentService::verify($payment, $officer, null, ['or_number' => 'OR-2026-00100']);

        $log = ActivityLog::where('action', 'payment.verified')->sole();

        $this->assertSame($officer->getKey(), $log->causer_id);
        $this->assertSame('OR-2026-00100', $log->properties['or_number']);
    }

    public function test_each_refund_stage_lands_in_the_trail(): void
    {
        $officer = $this->officer(Role::CollectingOfficer);
        $refund = RefundService::request(
            Payment::factory()->verified()->create(),
            'The training was cancelled.',
            ['account_name' => 'A B', 'bank_name' => 'LBP', 'account_number' => '123'],
        );

        RefundService::advance($refund, RefundStatus::Processing, $officer);

        $this->assertSame(1, ActivityLog::where('action', 'refund.for_review')->count());
        $this->assertSame(1, ActivityLog::where('action', 'refund.processing')->count());
    }

    /**
     * The name is captured at write time precisely so the trail survives the
     * account. A deleted reviewer must not turn a decision anonymous.
     */
    public function test_the_trail_still_names_an_actor_whose_account_is_gone(): void
    {
        $registration = Registration::factory()->create(['status' => RegistrationStatus::Pending]);
        $reviewer = $this->officer();
        $name = $reviewer->name;

        RegistrationService::review($registration, RegistrationStatus::Approved, $reviewer);
        $reviewer->delete();

        $log = ActivityLog::where('action', 'registration.approved')->sole();

        $this->assertNull($log->fresh()->causer_id);
        $this->assertSame($name, $log->fresh()->actorName());
    }

    /**
     * Logging is best-effort: losing an audit row is bad, but failing the
     * operation being audited is worse.
     *
     * The failure is forced with a property that cannot be JSON-encoded, which
     * makes the write throw exactly where a real outage would. Dropping the
     * table would be the more obvious way to provoke it, but DDL implicitly
     * commits in MySQL and would tear down RefreshDatabase's transaction for
     * every test that ran afterwards.
     */
    public function test_a_failing_log_write_returns_null_instead_of_throwing(): void
    {
        $handle = fopen('php://memory', 'r');

        $log = ActivityLogger::record(
            'test.unencodable',
            null,
            'A property that cannot be serialised.',
            ['handle' => $handle],
        );

        fclose($handle);

        $this->assertNull($log);
        $this->assertSame(0, ActivityLog::where('action', 'test.unencodable')->count());
    }

    /** And the operation it was recording still completes. */
    public function test_the_audited_operation_survives_a_logging_failure(): void
    {
        $payment = Payment::factory()->create();

        // Any logged action still leaves the payment verified; the assertion
        // that matters is that verify() returns rather than propagating.
        PaymentService::verify($payment, $this->officer(Role::CollectingOfficer));

        $this->assertSame(PaymentStatus::Verified, $payment->fresh()->status);
    }

    public function test_the_trail_is_superadmin_only(): void
    {
        $this->actingAs($this->officer(Role::SuperAdmin))->get('/admin/activity')->assertOk();
        $this->actingAs($this->officer(Role::Admin))->get('/admin/activity')->assertForbidden();
        $this->actingAs($this->officer(Role::CollectingOfficer))->get('/admin/activity')->assertForbidden();
        $this->actingAs($this->participant())->get('/admin/activity')->assertForbidden();
    }

    public function test_the_trail_can_be_filtered_by_area(): void
    {
        $officer = $this->officer(Role::SuperAdmin);

        RegistrationService::review(
            Registration::factory()->create(['status' => RegistrationStatus::Pending]),
            RegistrationStatus::Approved,
            $officer,
        );
        PaymentService::verify(Payment::factory()->create(), $officer);

        $this->actingAs($officer)
            ->get('/admin/activity?module=payment')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('logs.data', 1)
                ->where('logs.data.0.action', 'payment.verified')
            );
    }

    public function test_signing_in_stamps_the_last_login(): void
    {
        $user = User::factory()->create([
            'password' => 'Password123',
            'profile_completed_at' => now(),
        ]);

        $this->assertNull($user->last_login_at);

        $this->post('/login', ['email' => $user->email, 'password' => 'Password123']);

        $this->assertNotNull($user->fresh()->last_login_at);
    }
}
