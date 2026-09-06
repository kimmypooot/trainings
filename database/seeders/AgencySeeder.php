<?php

namespace Database\Seeders;

use App\Models\Agency;
use App\Models\FieldOffice;
use App\Support\AgencyReference;
use Illuminate\Database\Seeder;

/**
 * Applies database/data/agencies.json to the agencies table.
 *
 * Safe to re-run: an existing row is corrected in place rather than duplicated.
 *
 * **Rows are matched on `code`, not on `name`.** A name is the thing most
 * likely to be edited — a typo, a reorganisation, a house style — and matching
 * on it means the seeder cannot tell a rename from a new agency: it creates a
 * second row, leaves every profile already linked pointing at the old one, and
 * offers the new one to everybody afterwards. One employer, two spellings,
 * which is the split this table exists to end. Name is still the fallback for a
 * row added by hand with no code, and that fallback carries the same hazard.
 *
 * Deliberately additive — a row that has left the file is left alone rather
 * than deleted. Profiles point at these rows, and a name dropped from the file
 * by an editing slip must not take a participant's employer with it. Retiring
 * an agency is `is_active = false` in the file.
 *
 * Writes go through Eloquent rather than the query builder so that the `saved`
 * hook on Agency fires: it is what carries a corrected name or sector out to
 * the profiles already linked to the row.
 */
class AgencySeeder extends Seeder
{
    public function run(): void
    {
        $offices = FieldOffice::pluck('id', 'code');

        foreach (AgencyReference::all() as $agency) {
            $officeCode = $agency['field_office_code'] ?? null;
            $code = $agency['code'] ?? null;

            $attributes = [
                'name' => $agency['name'],
                'acronym' => $agency['acronym'] ?? null,
                'sector' => $agency['sector'],
                // An unrecognised code leaves the office unset rather than
                // failing the seed: the agency is still worth having, and the
                // pairing it could not resolve is visible as a blank rather
                // than as a wrong office.
                'field_office_id' => $officeCode ? $offices->get($officeCode) : null,
                'is_active' => $agency['is_active'] ?? true,
            ];

            $existing = filled($code) ? Agency::firstWhere('code', $code) : null;

            /*
             * Fall back to the name — and *adopt* the row rather than add one.
             *
             * The office can add an agency through /admin/agencies that the
             * source list does not have yet. When the list is later regenerated
             * and does have it, its row arrives under a code no row here
             * carries, so a plain create would try to insert a second row with
             * the same name — and `agencies.name` is unique, so the seed does
             * not quietly duplicate, it **dies**, part-applied, with a
             * constraint violation naming a column nobody was thinking about.
             * That was reproduced before this was written.
             *
             * A row already carrying this name is this agency, however it got
             * here. Adopting it keeps every profile already linked, takes the
             * file's code so the next run matches on that, and turns what was a
             * crash into the merge the office wanted.
             */
            $existing ??= Agency::firstWhere('name', $agency['name']);

            if ($existing) {
                $existing->update($attributes + ['code' => $code ?? $existing->code]);

                continue;
            }

            Agency::create($attributes + ['code' => $code]);
        }
    }
}
