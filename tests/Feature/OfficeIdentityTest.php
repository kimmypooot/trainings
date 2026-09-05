<?php

namespace Tests\Feature;

use App\Models\FieldOffice;
use App\Models\User;
use App\Notifications\EmailChangeRequested;
use App\Notifications\PasswordChanged;
use App\Support\FieldOfficeReference;
use App\Support\PhilippineGeography;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
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
     * The region matcher, which decides who this office serves.
     *
     * Tolerant about spelling because older and imported profile rows are, but
     * word-bounded because roman numerals nest: the pair at the end is the case
     * that makes a plain `str_contains` unusable no matter which numeral is
     * substituted into it.
     */
    #[DataProvider('regionMatches')]
    public function test_a_region_string_is_matched_against_the_office_region(
        string $candidate,
        string $canonical,
        bool $expected
    ): void {
        $this->assertSame(
            $expected,
            PhilippineGeography::denotesRegion($candidate, $canonical),
            "\"{$candidate}\" against \"{$canonical}\""
        );
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: bool}>
     */
    public static function regionMatches(): array
    {
        $ev = 'Region VIII (Eastern Visayas)';

        return [
            'exact canonical' => [$ev, $ev, true],
            'numeral only' => ['Region VIII', $ev, true],
            'name only' => ['Eastern Visayas', $ev, true],
            'upper cased' => ['REGION VIII', $ev, true],
            'loose spacing' => ['Region   VIII', $ev, true],
            'a different region' => ['Region XI (Davao Region)', $ev, false],
            'a parenthetical abbreviation' => ['NCR', 'National Capital Region (NCR)', true],
            'no numeral in the name' => ['MIMAROPA Region', 'MIMAROPA Region', true],

            // VII is a prefix of VIII, in both directions.
            'VIII is not VII' => ['Region VIII (Eastern Visayas)', 'Region VII (Central Visayas)', false],
            'VII is not VIII' => ['Region VII (Central Visayas)', $ev, false],
        ];
    }

    /**
     * The office list is data a deployment supplies, not an array compiled in.
     *
     * It was nine Regional Office VIII offices in a PHP class, seeded on first
     * migrate, so another region's installation came up holding Biliran, Leyte
     * I and II and the rest — with the incumbent directors' names attached.
     */
    public function test_the_field_office_list_comes_from_a_data_file(): void
    {
        $this->assertFileExists(FieldOfficeReference::path());

        $offices = FieldOfficeReference::all();

        $this->assertNotEmpty($offices);

        foreach ($offices as $office) {
            $this->assertArrayHasKey('code', $office);
            $this->assertArrayHasKey('name', $office);
            $this->assertArrayHasKey('jurisdiction', $office);
        }

        // The rows the migration seeded are the rows the file describes, so the
        // two cannot disagree about who this deployment serves.
        $this->assertSame(
            array_column($offices, 'code'),
            FieldOffice::orderBy('id')->pluck('code')->all()
        );
    }

    /**
     * Nothing in the presentation layer hard-codes this office.
     *
     * Deliberately scoped to what is rendered — Vue templates and Blade views.
     * One piece of domain logic still carries Region VIII and is out of scope
     * on purpose rather than by oversight: App\Support\FieldOfficeReference's
     * office list, which is seeded from inside a migration and so cannot be
     * swapped by configuration alone.
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
