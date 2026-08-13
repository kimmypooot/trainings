<?php

namespace Database\Factories;

use App\Models\ScanLink;
use App\Models\Training;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<ScanLink>
 */
class ScanLinkFactory extends Factory
{
    protected $model = ScanLink::class;

    /**
     * A live link whose code is the fixed string below.
     *
     * Fixed rather than random so a test can unlock a station without having to
     * thread the plaintext back out of ScanLink::issue() — the states worth
     * testing are expired, revoked and wrong-code, not the digits themselves.
     */
    public const CODE = '123456';

    public function definition(): array
    {
        return [
            'training_id' => Training::factory(),
            'token' => Str::random(40),
            'code_hash' => Hash::make(self::CODE),
            'issued_by' => User::factory(),
            'label' => 'Front door',
            'expires_at' => CarbonImmutable::now()->addDays(7),
        ];
    }

    public function expired(): static
    {
        return $this->state(fn () => ['expires_at' => CarbonImmutable::now()->subDay()]);
    }

    public function revoked(): static
    {
        return $this->state(fn () => ['revoked_at' => CarbonImmutable::now()->subHour()]);
    }
}
