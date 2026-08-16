<?php

namespace App\Support;

use Database\Seeders\FieldOfficeSeeder;

/**
 * The canonical CSC Regional Office VIII office list, as recorded in v2.
 *
 * Held here so the migration that creates the table and the seeder that keeps
 * it current cannot drift apart — the migration needs the rows in place before
 * profiles can be linked to them.
 *
 * @see FieldOfficeSeeder
 */
class FieldOfficeReference
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function all(): array
    {
        return [
            [
                'code' => 'bfo',
                'name' => 'CSC Field Office - Biliran',
                'type' => 'field_office',
                'province' => 'Biliran',
                'jurisdiction' => ['Biliran'],
                'email' => 'ro08.fo_biliran@csc.gov.ph',
                'head_name' => 'Michael M. Dela Cruz',
                'head_position' => 'Director II',
            ],
            [
                'code' => 'lfoi',
                'name' => 'CSC Field Office - Leyte I',
                'type' => 'field_office',
                'province' => 'Leyte',
                'jurisdiction' => ['Leyte'],
                'email' => 'ro08.fo_leyte1@csc.gov.ph',
                'head_name' => 'Ma. Natividad L. Costibolo',
                'head_position' => 'Director II',
            ],
            [
                'code' => 'lfoii',
                'name' => 'CSC Field Office - Leyte II',
                'type' => 'field_office',
                'province' => 'Leyte',
                'jurisdiction' => ['Leyte'],
                'email' => 'ro08.fo_leyte2@csc.gov.ph',
                'head_name' => 'Pharida Q. Aurelia',
                'head_position' => 'Director II',
            ],
            [
                'code' => 'slfo',
                'name' => 'CSC Field Office - Southern Leyte',
                'type' => 'field_office',
                'province' => 'Southern Leyte',
                'jurisdiction' => ['Southern Leyte'],
                'email' => null,
                'head_name' => 'Richmond A. Sanglay',
                'head_position' => 'OIC-Field Office Caretaker',
            ],
            [
                'code' => 'wlso',
                'name' => 'CSC Satellite Office - Western Leyte',
                'type' => 'satellite_office',
                'province' => 'Western Leyte',
                'jurisdiction' => ['Western Leyte'],
                'email' => 'ro08.fo_westernleyte@csc.gov.ph',
                'head_name' => 'Michael M. Dela Cruz',
                'head_position' => 'Director II',
            ],
            [
                'code' => 'sfo',
                'name' => 'CSC Field Office - Samar',
                'type' => 'field_office',
                'province' => 'Samar',
                'jurisdiction' => ['Samar'],
                'email' => null,
                'head_name' => 'Rey Albert B. Uy',
                'head_position' => 'Director II',
            ],
            [
                'code' => 'esfo',
                'name' => 'CSC Field Office - Eastern Samar',
                'type' => 'field_office',
                'province' => 'Eastern Samar',
                'jurisdiction' => ['Eastern Samar'],
                'email' => 'ro08.fo_easternsamar@csc.gov.ph',
                'head_name' => 'Rey Albert B. Uy',
                'head_position' => 'Director II',
            ],
            [
                'code' => 'nsfo',
                'name' => 'CSC Field Office - Northern Samar',
                'type' => 'field_office',
                'province' => 'Northern Samar',
                'jurisdiction' => ['Northern Samar'],
                'email' => null,
                'head_name' => null,
                'head_position' => null,
            ],
            [
                'code' => 'hrd',
                'name' => 'Outside Region VIII',
                'type' => 'division',
                'province' => 'Outside Region VIII',
                'jurisdiction' => [
                    'Biliran', 'Leyte', 'Western Leyte', 'Southern Leyte',
                    'Samar', 'Eastern Samar', 'Northern Samar', 'Outside Region VIII',
                ],
                'email' => null,
                'head_name' => 'Jay M. Merelos',
                'head_position' => 'Chief Human Resource Management Officer',
            ],
        ];
    }
}
