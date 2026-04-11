<?php

namespace Database\Factories;

use App\Enums\RoleEnum;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'status' => 'active',
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Assign the system_admin role after creation.
     */
    public function asSystemAdmin(): static
    {
        return $this->afterCreating(function (User $user) {
            $user->syncRoles(RoleEnum::SystemAdmin->value);
        });
    }

    /**
     * Assign the admin role after creation.
     */
    public function asAdmin(): static
    {
        return $this->afterCreating(function (User $user) {
            $user->syncRoles(RoleEnum::Admin->value);
        });
    }

    /**
     * Assign the user role after creation.
     */
    public function asUser(): static
    {
        return $this->afterCreating(function (User $user) {
            $user->syncRoles(RoleEnum::User->value);
        });
    }
}
