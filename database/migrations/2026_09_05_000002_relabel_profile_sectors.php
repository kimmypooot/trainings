<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The sector list now carries its acronym, and the three sectors the office
 * never actually used are gone. `profiles.sector` is a plain string validated
 * against `ProfileOptions::sectors()`, so a row left on an old label is not
 * merely cosmetic: the participant's next profile save fails validation on a
 * field they did not touch, and every sector filter offers a value that
 * matches nobody. Rewriting the rows is what keeps the two in step.
 *
 * Constitutional Body, Judiciary and Legislature have no replacement in the
 * new list, so they fold into 'Other' — the only honest destination, and the
 * reason `down()` cannot restore them.
 */
return new class extends Migration
{
    /** @var array<string, string> */
    private array $map = [
        'National Government Agency' => 'National Government Agency (NGA)',
        'Local Government Unit' => 'Local Government Unit (LGU)',
        'State University or College' => 'State University or College (SUC)',
        'Government-Owned and Controlled Corporation' => 'Government-Owned and Controlled Corporation (GOCC)',
        'Non-Government Organization' => 'Non-Government Organization (NGO)',
        'Constitutional Body' => 'Other',
        'Judiciary' => 'Other',
        'Legislature' => 'Other',
    ];

    public function up(): void
    {
        foreach ($this->map as $from => $to) {
            DB::table('profiles')->where('sector', $from)->update(['sector' => $to]);
        }
    }

    public function down(): void
    {
        // Only the renames reverse. The three folded into 'Other' are gone,
        // and guessing which of them a given 'Other' was would invent data.
        foreach ($this->map as $from => $to) {
            if ($to === 'Other') {
                continue;
            }

            DB::table('profiles')->where('sector', $to)->update(['sector' => $from]);
        }
    }
};
