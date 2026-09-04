<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\EmailChangeRequested;
use App\Notifications\PasswordChanged;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Who this deployment says it is.
 *
 * This codebase is handed to regional offices one copy each, so every string
 * naming the operating office is a deployment fact and belongs in
 * config/office.php. Roughly half the app already read it; the other half had
 * "Regional Office VIII" typed in, and would have told another region's
 * participants — and every search engine — that the portal belongs to Region
 * VIII.
 *
 * Two kinds of check here. The functional ones render the thing and read the
 * office out of it, which is the honest test. The last one is a grep over the
 * presentation layer, because most of these strings live in Vue templates that
 * nothing else in the suite reaches: Inertia pages are deliberately not
 * unit-tested, so without it a re-introduced literal would ship silently.
 */
class OfficeIdentityTest extends TestCase
{
    use RefreshDatabase;

    /** A fictional office, so a pass cannot come from the real defaults. */
    private function useAnotherOffice(): void
    {
        config([
            'office.name' => 'Civil Service Commission Regional Office V',
            'office.short_name' => 'CSC RO V',
            'office.region' => 'Bicol',
        ]);
    }

    /**
     * The structured data a crawler reads, which is how a search result gets
     * its name. This is rendered in the Blade shell rather than by Inertia, so
     * a plain first load is what exercises it.
     */
    public function test_the_structured_data_names_the_configured_office(): void
    {
        $this->useAnotherOffice();

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('"name": "Civil Service Commission Regional Office V"', $html);
        $this->assertStringContainsString('"alternateName": "CSC RO V"', $html);
        $this->assertStringNotContainsString('Regional Office VIII', $html);
    }

    /**
     * An office with no short name publishes no alternateName, rather than a
     * null — following config/office.php's rule that an unset value is omitted.
     */
    public function test_the_structured_data_omits_an_unset_short_name(): void
    {
        config(['office.short_name' => null]);

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString('alternateName', $html);
    }

    /**
     * The two security emails that tell someone to contact the office.
     *
     * Both already signed off with config('office.name') via BrandsMail, while
     * naming Regional Office VIII in the body — so one message could credit two
     * different offices, and on any other deployment the body was simply wrong.
     */
    public function test_the_security_emails_name_the_configured_office(): void
    {
        $this->useAnotherOffice();

        $user = User::factory()->create();

        $notifications = [
            // Both branches of PasswordChanged carry the line under test.
            new PasswordChanged(created: false),
            new PasswordChanged(created: true),
            new EmailChangeRequested('new@example.com'),
        ];

        foreach ($notifications as $notification) {
            $body = (string) $notification->toMail($user)->render();

            $this->assertStringContainsString('Civil Service Commission Regional Office V', $body);
            $this->assertStringNotContainsString('Regional Office VIII', $body);
        }
    }

    /**
     * Nothing in the presentation layer hard-codes this office.
     *
     * Deliberately scoped to what is rendered — Vue templates and Blade views.
     * The domain layer still carries Region VIII in places that are logic
     * rather than chrome (App\Support\FieldOfficeReference's office list,
     * Profile::isOutsideRegion's "VIII" test, and the physical-OR rule built on
     * it). Those are a separate, larger piece of work; they are out of this
     * test's scope on purpose rather than by oversight.
     */
    public function test_no_view_or_component_hard_codes_the_office(): void
    {
        $forbidden = ['Regional Office VIII', 'RO VIII', 'Eastern Visayas', 'ro08.'];

        $offenders = [];

        foreach ($this->presentationFiles() as $file) {
            $source = $this->withoutComments((string) file_get_contents($file));

            foreach ($forbidden as $needle) {
                if (str_contains($source, $needle)) {
                    $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file)." ({$needle})";
                }
            }
        }

        $this->assertSame([], $offenders, implode("\n", [
            'These render this office by name instead of reading config("office.*"):',
            ...$offenders,
        ]));
    }

    /**
     * @return array<int, string>
     */
    private function presentationFiles(): array
    {
        $files = [];

        foreach ([resource_path('js'), resource_path('views')] as $root) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

            foreach ($iterator as $file) {
                if ($file->isFile() && in_array($file->getExtension(), ['vue', 'js', 'ts', 'php'], true)) {
                    $files[] = $file->getPathname();
                }
            }
        }

        return $files;
    }

    /**
     * Strip comments, so the explanations of *why* these strings moved to
     * config — which necessarily quote the old value — do not fail the test
     * that enforces the move.
     */
    private function withoutComments(string $source): string
    {
        return (string) preg_replace(
            ['/\{\{--.*?--\}\}/s', '/<!--.*?-->/s', '#/\*.*?\*/#s', '#^\s*//.*$#m'],
            '',
            $source
        );
    }
}
