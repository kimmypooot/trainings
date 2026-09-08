<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\FieldOffice;
use App\Models\Profile;
use App\Models\Registration;
use App\Models\Training;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The scanner's desk-side QR lookup — for a participant who has arrived
 * without the phone their check-in code lives on.
 *
 * Deliberately online and staff-only, the way ScannerController::walkIn() is:
 * the offline roster carries only a digest of each code, never the code
 * itself, so this cannot be answered from a device with no signal, and
 * pulling up somebody's own credential needs a name attached to the request.
 */
class ScannerParticipantQrTest extends TestCase
{
    use RefreshDatabase;

    private function staff(?FieldOffice $office = null): User
    {
        return User::factory()->create([
            'role' => $office ? Role::FieldOffice : Role::Admin,
            'profile_completed_at' => now(),
            'field_office_id' => $office?->getKey(),
        ])->refresh();
    }

    private function participant(?FieldOffice $office = null): User
    {
        $user = User::factory()->create(['profile_completed_at' => now()]);
        Profile::factory()->for($user)->create(['field_office_id' => $office?->getKey()]);

        return $user->refresh();
    }

    private function registrationFor(User $participant, Training $training): Registration
    {
        return Registration::factory()->approved()->create([
            'user_id' => $participant->getKey(),
            'training_id' => $training->getKey(),
        ]);
    }

    public function test_staff_can_fetch_a_participants_code(): void
    {
        $training = Training::factory()->create();
        $participant = $this->participant();
        $registration = $this->registrationFor($participant, $training);

        $response = $this->actingAs($this->staff())
            ->getJson(route('admin.scanner.participant-qr', $registration))
            ->assertOk();

        $this->assertSame($participant->name, $response->json('name'));
        $this->assertStringStartsWith('data:image', $response->json('qr'));
    }

    /**
     * The same office boundary the roster and the walk-in desk carry — a
     * field-office operator reaches only their own participants.
     */
    public function test_a_field_office_cannot_fetch_another_offices_code(): void
    {
        $home = FieldOffice::factory()->create();
        $elsewhere = FieldOffice::factory()->create();

        $training = Training::factory()->create();
        $participant = $this->participant($elsewhere);
        $registration = $this->registrationFor($participant, $training);

        $this->actingAs($this->staff($home))
            ->getJson(route('admin.scanner.participant-qr', $registration))
            ->assertNotFound();
    }

    public function test_a_field_office_can_fetch_its_own_participants_code(): void
    {
        $office = FieldOffice::factory()->create();

        $training = Training::factory()->create();
        $participant = $this->participant($office);
        $registration = $this->registrationFor($participant, $training);

        $this->actingAs($this->staff($office))
            ->getJson(route('admin.scanner.participant-qr', $registration))
            ->assertOk();
    }

    public function test_a_participant_cannot_reach_the_desk_lookup(): void
    {
        $training = Training::factory()->create();
        $participant = $this->participant();
        $registration = $this->registrationFor($participant, $training);

        $this->actingAs($this->participant())
            ->getJson(route('admin.scanner.participant-qr', $registration))
            ->assertForbidden();
    }
}
