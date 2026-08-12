<?php

namespace Database\Factories;

use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use App\Models\Registration;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Attendance>
 */
class AttendanceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'registration_id' => Registration::factory(),
            'training_day' => 1,
            'attendance_date' => now()->toDateString(),
            'status' => AttendanceStatus::Present,
            'time_in' => '08:05:00',
        ];
    }

    public function onDay(int $day): static
    {
        return $this->state(fn () => ['training_day' => $day]);
    }

    public function absent(): static
    {
        return $this->state(fn () => [
            'status' => AttendanceStatus::Absent,
            'time_in' => null,
        ]);
    }
}
