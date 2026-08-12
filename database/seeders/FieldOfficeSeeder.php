<?php

namespace Database\Seeders;

use App\Models\FieldOffice;
use App\Support\FieldOfficeReference;
use Illuminate\Database\Seeder;

/**
 * Keeps the office list current. The rows are created by the migration that
 * builds the table; this re-applies the reference values on demand.
 *
 * Safe to re-run: rows are matched on their code.
 */
class FieldOfficeSeeder extends Seeder
{
    public function run(): void
    {
        foreach (FieldOfficeReference::all() as $office) {
            FieldOffice::updateOrCreate(['code' => $office['code']], $office);
        }
    }
}
