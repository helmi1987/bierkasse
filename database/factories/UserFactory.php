<?php

namespace Database\Factories;

use App\Models\User;
use App\Support\LegalTexts;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'username' => Str::lower(Str::random(3)).fake()->unique()->numberBetween(1000, 999999),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('password'),
            'role' => User::ROLE_USER,
            'status' => User::STATUS_ACTIVE,
            'remember_token' => Str::random(10),
            // Accepted the current privacy policy and terms of use (consent gate), see withoutConsent().
            'privacy_accepted_version' => LegalTexts::version(LegalTexts::PRIVACY),
            'terms_accepted_version' => LegalTexts::version(LegalTexts::TERMS),
            'consent_accepted_at' => now(),
        ];
    }

    public function admin(): static
    {
        return $this->state(fn () => ['role' => User::ROLE_ADMIN]);
    }

    public function kassier(): static
    {
        return $this->state(fn () => ['role' => User::ROLE_KASSIER]);
    }

    public function left(): static
    {
        return $this->state(fn () => ['status' => User::STATUS_LEFT]);
    }

    public function locked(): static
    {
        return $this->state(fn () => ['status' => User::STATUS_LOCKED, 'locked_at' => now(), 'locked_reason' => 'Test']);
    }

    /** Never accepted the privacy policy / terms of use (e.g. account created before the consent gate). */
    public function withoutConsent(): static
    {
        return $this->state(fn () => ['privacy_accepted_version' => null, 'terms_accepted_version' => null, 'consent_accepted_at' => null]);
    }
}
