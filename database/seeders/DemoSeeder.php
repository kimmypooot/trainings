<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Enums\TrainingStatus;
use App\Models\FieldOffice;
use App\Models\Profile;
use App\Models\Training;
use App\Models\User;
use App\Support\RegistrationService;
use Illuminate\Database\Seeder;

/**
 * Test accounts and sample trainings for local evaluation.
 *
 * Refuses to run in production — these are known credentials.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command->error('DemoSeeder is blocked in production.');

            return;
        }

        $staff = [
            ['admin@csc.gov.ph', Role::Admin, 'CSC ADMINISTRATOR'],
            ['superadmin@csc.gov.ph', Role::SuperAdmin, 'CSC SUPER ADMINISTRATOR'],
            ['fieldoffice@csc.gov.ph', Role::FieldOffice, 'CSC FIELD OFFICE'],
            ['management@csc.gov.ph', Role::Management, 'CSC MANAGEMENT'],
        ];

        // The field-office account is scoped to one office; the rest see the region.
        $leyteI = FieldOffice::where('code', 'lfoi')->value('id');

        // `role`, `field_office_id`, and `is_active` are deliberately not
        // mass-assignable, so they are forced rather than passed to fill() —
        // otherwise they are silently discarded and every account lands as a
        // participant.
        foreach ($staff as [$email, $role, $name]) {
            User::firstOrNew(['email' => $email])
                ->fill(['name' => $name, 'password' => 'Password123'])
                ->forceFill([
                    'role' => $role,
                    'field_office_id' => $role === Role::FieldOffice ? $leyteI : null,
                    /*
                     * The demo field office is a designated collecting officer,
                     * because that is the arrangement the region actually runs:
                     * a participant pays at the field office nearest them, and
                     * the person who takes the money is a field-office user.
                     *
                     * Seeding it off made the demo account the one shape the
                     * app supports but nobody could see — the roster showed no
                     * Record Payment, no Revenue panel and no Payments queue,
                     * which reads as "field offices cannot take money" rather
                     * than "this account has not been designated". Admins and
                     * superadmins carry the same ability by role and need no
                     * flag; management is oversight and gets neither.
                     */
                    'is_collecting_officer' => $role === Role::FieldOffice,
                    'is_active' => true,
                    'email_verified_at' => now(),
                    'profile_completed_at' => now(),
                ])
                ->save();
        }

        $participant = User::firstOrNew(['email' => 'participant@example.com']);
        $participant->fill(['password' => 'Password123'])
            ->forceFill([
                'role' => Role::Participant,
                'is_active' => true,
                'email_verified_at' => now(),
                'profile_completed_at' => now(),
            ])
            ->save();

        $profile = Profile::updateOrCreate(
            ['user_id' => $participant->getKey()],
            [
                'first_name' => 'JUAN',
                'middle_name' => 'DIZON',
                'last_name' => 'DELA CRUZ',
                'date_of_birth' => '1990-05-04',
                'sex' => 'Male',
                'is_pwd' => false,
                'civil_status' => 'Single',
                'mobile_number' => '09171234567',
                'position_title' => 'ADMINISTRATIVE OFFICER III',
                // SG 16 keeps the demo participant inside the supervisory band,
                // so the SDC document flow can be exercised with this account.
                'salary_grade' => 'SG 16',
                'organization_name' => 'DEPARTMENT OF EDUCATION',
                'sector' => 'National Government Agency',
                'region' => 'Region VIII (Eastern Visayas)',
                'province' => 'Leyte',
                'city_municipality' => 'Palo',
                'field_office_id' => FieldOffice::where('code', 'lfoi')->value('id'),
                'position_level' => '2nd Level (Rank and File)',
                'employment_status' => 'Permanent',
                'organization_address' => 'PALO, LEYTE',
                'food_restrictions_details' => 'NO PORK',
                'consented_at' => now(),
            ]
        );

        $participant->forceFill(['name' => $profile->fullName()])->save();

        $samples = [
            ['Records Management Seminar', now()->addWeeks(2), 30, TrainingStatus::Published],
            ['Public Service Ethics Workshop', now()->addWeeks(5), 50, TrainingStatus::Published],
            ['Leadership Development Program', now()->addMonths(2), null, TrainingStatus::Published],
            ['Basic Computer Literacy', now()->addWeeks(8), 25, TrainingStatus::Draft],
        ];

        foreach ($samples as [$title, $starts, $capacity, $status]) {
            $training = Training::updateOrCreate(
                ['slug' => str($title)->slug()->value()],
                [
                    'title' => $title,
                    'description' => "A sample training seeded for local testing: {$title}.",
                    'venue' => 'CSC Central Office, Quezon City',
                    'starts_at' => $starts->copy()->setTime(8, 30),
                    'ends_at' => $starts->copy()->setTime(16, 30),
                    'registration_closes_at' => $starts->copy()->subDays(3),
                    'capacity' => $capacity,
                    'status' => $status,
                ]
            );

            // Give the demo participant one registration to look at.
            if ($title === 'Records Management Seminar' && $participant->registrations()->count() === 0) {
                RegistrationService::register($participant->fresh(), $training);
            }
        }

        $this->command->info('Demo data seeded. Password for every account: Password123');
    }
}
